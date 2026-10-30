# Design

## Context

Мотивация — proposal.md, требования — `specs/event-ingestion/spec.md`.

Что есть сейчас (после шага 1): Symfony 7.4, Messenger + `amqp-messenger`, транспорт в `messenger.yaml` — только рецептовый `sync://`; Doctrine с XML-маппингом в Infrastructure (ADR 0001); PSR Clock; логгер — стандартный Symfony (stderr), monolog не установлен.

Что проверено в `vendor/symfony/amqp-messenger` 7.4 и влияет на решения:
- если `decode()` бросает `MessageDecodingFailedException`, receiver делает reject без requeue — сообщение пропадает мимо ретраев и failure transport;
- AMQP-транспорт объявляет один exchange и привязывает к нему свои очереди — одна очередь не может слушать несколько exchange через конфиг;
- повтор при ретрае уходит через default exchange (`''`) с routing key = имя нашей очереди, т. е. только нам, не всем подписчикам `courses.events`;
- при ретрае Messenger кодирует envelope обратно через сериализатор транспорта — `encode()` обязателен и должен сохранять стампы (счётчик попыток).

## Goals / Non-Goals

**Goals:**
- Хендлеры шага 3 получают типизированное сообщение и не знают про JSON, AMQP и дедупликацию.
- Ни одно входящее сообщение не теряется молча.

**Non-Goals:**
- Общий сериализатор для исходящих сигналов — outbox и relay шага 6 решат свой формат, переиспользовав только метаданные конверта.
- Батчинг heartbeat и тюнинг prefetch.

## Decisions

### 1. Слои и классы

| Часть | Слой | Где |
|---|---|---|
| `EventMetadata` — метаданные конверта (VO) | Application | `src/Application/Event/EventMetadata.php` |
| DTO шести событий, `fromPayload(array)` | Application | `src/Application/Event/Incoming/*` |
| `IncomingEvent` — интерфейс DTO (`metadata()`) | Application | `src/Application/Event/IncomingEvent.php` |
| `PayloadReader` — типизированное чтение массива с понятными ошибками | Application | `src/Application/Event/PayloadReader.php` |
| `EventTypeRegistry` — `event_type` + версия → класс DTO | Infrastructure | `src/Infrastructure/Messenger/` |
| `EnvelopeSerializer` (Messenger `SerializerInterface`) | Infrastructure | `src/Infrastructure/Messenger/` |
| `UndecodableMessage` + хендлер | Infrastructure | `src/Infrastructure/Messenger/` |
| `InboxMiddleware` + `DbalInbox` | Infrastructure | `src/Infrastructure/Messenger/`, `src/Infrastructure/Persistence/` |
| `FailedMessageLogger` (подписчик `WorkerMessageFailedEvent`) | Infrastructure | `src/Infrastructure/Messenger/` |

DTO и метаданные — в Application, потому что их потребляют хендлеры Application; Domain они не нужны. Хендлерам нужен `occurred_at` (сессионизация, SPEC §6.1), поэтому каждый DTO несёт `EventMetadata`, а не только payload.

### 2. Маппинг payload → DTO вручную
Каждый DTO — `final readonly` класс со статическим `fromPayload(EventMetadata, array): self`, который читает поля через `PayloadReader` (обязательность, тип, UUID, enum, дата). Ошибка → `InvalidPayload` с путём поля (`tasks[2].estimate_minutes`).

*Альтернатива:* `symfony/serializer` + `symfony/validator` — меньше кода на DTO, но две новые зависимости, магия денормализации и ошибки, которые PHPStan не видит. Шесть DTO — немного; явный код проще ревьюить и тестировать в учебном проекте. UUID пока строки с проверкой формата: `symfony/uid` добавим, когда понадобятся операции над UUID.

### 3. Невалидные сообщения — в failure transport, а не reject
`EnvelopeSerializer::decode()` не бросает `MessageDecodingFailedException`. При любой ошибке разбора (не JSON, конверт, неизвестный тип, версия ≠ 1, payload) он возвращает `UndecodableMessage(rawBody, reason, eventId?, correlationId?)`. Его хендлер бросает `UnrecoverableMessageHandlingException` — Messenger отправляет такое сообщение в failure transport сразу, без ретраев.

*Альтернатива:* бросать исключение и настроить у очередей `x-dead-letter-exchange` на отдельную DLQ. Минусы: второй «склад» непринятых сообщений рядом с failure transport, сообщение без причины, а аргументы очереди потом нельзя поменять без её пересоздания. С выбранным подходом всё непринятое — в одном месте и видно через `messenger:failed:show`.

Неизвестный `event_type` и `event_version` ≠ 1 обрабатываются так же — failure transport без ретраев + error в лог, не тихий ack (подтверждено пользователем 2026-10-30): событие настоящее, его потеря исказила бы EV/AC, а после добавления поддержки его можно повторить через `failed:retry`.

`UndecodableMessage` не проходит через inbox: у него может не быть валидного `event_id`.

### 4. `encode()` сохраняет исходное тело
При `decode()` сериализатор кладёт в envelope `RawBodyStamp` с исходным JSON. `encode()` возвращает это тело как есть, а стампы Messenger (`RedeliveryStamp`, `ErrorDetailsStamp` и др.) пишет в заголовки так же, как штатный Symfony-сериализатор (`X-Message-Stamp-*`, PHP `serialize`). Так при ретрае приходит байт-в-байт то же событие, а счётчик попыток не теряется.

*Альтернатива:* нормализовать DTO обратно в JSON — второй маппинг на каждый DTO и риск расхождения с оригиналом.

### 5. Топология: очередь на каждый exchange-источник
AMQP-транспорт привязывает очереди только к своему exchange (Context), поэтому транспорты такие:

| Транспорт | Exchange (topic) | Очередь | Binding keys |
|---|---|---|---|
| `courses` | `courses.events` | `diagnostics.courses` | `path.assigned`, `task.started`, `task.completed`, `sandbox.run` |
| `time` | `time.events` | `diagnostics.time` | `time.logged` |
| `heartbeat` | `activity.events` | `diagnostics.heartbeat` | `activity.heartbeat` |
| `failed` | — | Doctrine, таблица `messenger_messages`, `queue_name=failed` | — |

У всех AMQP-транспортов — `EnvelopeSerializer`. Воркер: `messenger:consume courses time heartbeat scheduler_default` — Messenger опрашивает транспорты по порядку, heartbeat последним, поэтому его всплеск не задерживает события Курсов.

**Следствие (подтверждено пользователем 2026-10-30):** CLAUDE.md и Makefile называли транспорты `async heartbeat`; `async` заменяется на `courses time` в `make consume` и в разделе «Команды» CLAUDE.md (задача 6.1). Если владельцем `time.logged` окажутся Курсы (SPEC §11.1), транспорт `time` удаляется, а `time.logged` добавляется в binding keys `courses`.

*Альтернатива:* один транспорт `async` и привязки к остальным exchange через definitions RabbitMQ или exchange-to-exchange binding — сохраняет имя, но топология уходит из кода в отдельный файл брокера.

Failure transport — Doctrine, а не AMQP: AMQP-receiver не поддерживает листинг, и `messenger:failed:show` / `failed:retry <id>` с ним не работают. Цена — новая зависимость `symfony/doctrine-messenger` (сейчас не установлена; подтверждено пользователем 2026-10-30). Таблица `messenger_messages` появляется в миграции через `doctrine:migrations:diff` (транспорт регистрирует её схему в Doctrine).

*Альтернатива:* failure transport в AMQP-очереди `diagnostics.failed` — без зависимости, но сообщения смотреть только в UI RabbitMQ, а повторять — только все разом через `failed:retry` без выбора.

### 6. Inbox — middleware внутри транзакции
Шина: `doctrine_ping_connection` → `doctrine_transaction` → `InboxMiddleware` → `handle_message`. Для envelope с `ReceivedStamp` и `IncomingEvent`:

```sql
INSERT INTO inbox_event (event_id, event_type, received_at) VALUES (…) ON CONFLICT (event_id) DO NOTHING
```

Вставлено 0 строк → дубликат: middleware логирует (info) и возвращает envelope, не вызывая хендлер; сообщение подтверждается. Ошибка хендлера откатывает транзакцию вместе со строкой inbox — событие обработается на ретрае. Одновременные дубликаты: второй `INSERT` ждёт на уникальном ключе до коммита первого и получает конфликт.

`received_at` — из `ClockInterface`. Запись через DBAL, а не через ORM-сущность: inbox — техническая таблица, не доменный объект. Таблица создаётся миграцией; чтобы `doctrine:migrations:diff` её видел, схема `inbox_event` описывается в `InboxSchemaListener` (подписчик `postGenerateSchema`) — тот же приём, что использует Messenger для `messenger_messages`.

*Альтернатива:* `SELECT` + `INSERT` или проверка в каждом хендлере — гонка между консьюмерами и дублирование кода.

### 7. Ретраи и лог
`retry_strategy` на каждом AMQP-транспорте: `max_retries: 3`, `delay: 1000`, `multiplier: 2` (1 с → 2 с → 4 с). `FailedMessageLogger` на `WorkerMessageFailedEvent` без `willRetry()` пишет `error` с `event_id`, `event_type`, `correlation_id`, транспортом и текстом ошибки — через PSR `LoggerInterface`, чтобы позже подключить monolog/JSON без правки кода.

### 8. Сквозная часть — в ADR 0002
Конверт, `UndecodableMessage` → failure transport, топология очередей и inbox-в-транзакции переживут этот change (outbox шага 6 и любые новые консьюмеры опираются на них). Фиксируются в `docs/adr/0002-event-envelope-inbox.md`; этот design — детали реализации.

## Risks / Trade-offs

- [Объявляем чужие exchange (`courses.events` и др.)] → declare идемпотентен при совпадении типа; тип `topic` взят из asyncapi. При расхождении RabbitMQ вернёт `PRECONDITION_FAILED` на старте — громко, не молча.
- [`inbox_event` растёт без ограничения] → для MVP допустимо; очистка — бэклог, отмечено в proposal.
- [PHP `serialize` стампов в заголовках] → читает только наш же сервис; заголовки не часть контракта asyncapi.
- [Событие MVP без хендлера до шага 3 после ретраев уйдёт в failed] → ожидаемо; трафика до шага 3 нет, эмулятор — шаг 8.
- [Имена очередей `diagnostics.*` становятся видны другим командам] → согласуются вместе с вопросом §11.8 к команде Уведомлений.

## Migration Plan

Новая схема: миграция `inbox_event` и `messenger_messages` через `make diff` → `make migrate`; очереди создаются `messenger:setup-transports` (или auto_setup при первом consume). Откат: `doctrine:migrations:migrate prev`, очереди `diagnostics.*` удалить в UI RabbitMQ — других потребителей у них нет.
