# Tasks

Каждая задача: код → тесты → зелёный `make check` → коммит (Conventional Commits).

## 1. Решение и зависимость

- [x] 1.1 Написать `docs/adr/0002-event-envelope-inbox.md` (конверт и `EventMetadata`, неразобранное → `UndecodableMessage` → failure transport, топология очередей по exchange-источникам, inbox в транзакции шины); проверка: ADR ссылается на SPEC §4, §5, §9 и на design.md этого change. Коммит `docs: adr 0002 event envelope and inbox`
- [x] 1.2 Установить `symfony/doctrine-messenger` (зачем: failure transport в PostgreSQL с `messenger:failed:show` / `failed:retry <id>`, design §5); проверка: `composer show symfony/doctrine-messenger` в контейнере, `make check` зелёный. Коммит `build: add doctrine-messenger for failure transport`

## 2. Метаданные конверта и чтение payload (Application)

- [x] 2.1 `PayloadReader`: обязательные/необязательные поля, int, string, bool, UUID, дата `Y-m-d`, ISO 8601 → `DateTimeImmutable` UTC, enum, вложенные списки; ошибки `InvalidPayload` с путём поля; проверка: табличный юнит-тест на валидные и невалидные значения, включая путь `tasks[2].estimate_minutes`
- [x] 2.2 `EventMetadata::fromEnvelope(array)` по `asyncapi → Envelope`; проверка: табличный юнит-тест — валидный конверт, без `correlation_id`, `occurred_at` со смещением → UTC, нет каждого обязательного поля, `event_version` < 1 и не int

## 3. DTO событий MVP (Application)

- [x] 3.1 Интерфейс `IncomingEvent` и DTO `TaskStarted`, `TaskCompleted`; проверка: юнит-тест `fromPayload` — валидный payload и отсутствие каждого обязательного поля
- [x] 3.2 DTO `PathAssigned` со списком заданий (`PathSnapshot`, `kind` — необязательный enum); проверка: юнит-тест на снапшот из 3 заданий, на пустой `tasks` и на невалидный `estimate_minutes` < 1 в середине списка
- [ ] 3.3 DTO `TimeLogged`, `ActivityHeartbeat`, `SandboxRun` (необязательные `task_id`, `error_type`, `tests_*`; enum `category`, `source`, `result`); проверка: юнит-тест на необязательные поля и значения вне enum

## 4. Сериализатор (Infrastructure)

- [ ] 4.1 `EventTypeRegistry` (`event_type` + `event_version` → класс DTO, только 6 событий MVP, версия 1); проверка: юнит-тест на известный тип, неизвестный тип, версию 2
- [ ] 4.2 `EnvelopeSerializer::decode()` → DTO или `UndecodableMessage` с причиной; `RawBodyStamp` с исходным телом; стампы из заголовков `X-Message-Stamp-*`; проверка: табличный юнит-тест — не JSON, невалидный конверт, неизвестный тип, неподдерживаемая версия, невалидный payload, валидное событие
- [ ] 4.3 `EnvelopeSerializer::encode()` возвращает исходное тело и стампы в заголовках; проверка: юнит-тест round-trip `decode(encode(x))` сохраняет тело байт-в-байт и `RedeliveryStamp`
- [ ] 4.4 `UndecodableMessage` и его хендлер (`UnrecoverableMessageHandlingException`); проверка: юнит-тест, что хендлер бросает unrecoverable с причиной

## 5. Inbox (Infrastructure)

- [ ] 5.1 `InboxSchemaListener` описывает `inbox_event (event_id uuid PK, event_type, received_at timestamp UTC)`; `make diff` генерирует миграцию; проверка: `make migrate` применяет её, `doctrine:migrations:diff` после этого сообщает «no changes». Коммит включает сгенерированную миграцию без ручных правок
- [ ] 5.2 `DbalInbox::markProcessed(EventMetadata): bool` через `INSERT … ON CONFLICT DO NOTHING`, время из `ClockInterface`; проверка: интеграционный тест на тестовой БД — первый вызов `true`, второй с тем же `event_id` `false`
- [ ] 5.3 `InboxMiddleware` (только envelope с `ReceivedStamp` и `IncomingEvent`); проверка: интеграционный тест шины — дубликат не вызывает хендлер; при исключении хендлера строки в `inbox_event` нет; сообщения без `ReceivedStamp` проходят мимо inbox

## 6. Транспорты, ретраи, failure transport (Infrastructure, конфиг)

- [ ] 6.1 `messenger.yaml`: транспорты `courses`, `time`, `heartbeat` (exchange, очереди, binding keys по design §5, `EnvelopeSerializer`), `retry_strategy` 3 / 1000 / ×2, `failed` = `doctrine://default?queue_name=failed&auto_setup=0`, middleware шины `doctrine_ping_connection` → `doctrine_transaction` → `InboxMiddleware`; в `when@test` транспорты `in-memory://`; `make consume` → `courses time heartbeat scheduler_default`; строка `make consume` в разделе «Команды» CLAUDE.md (решение подтверждено); проверка: `bin/console debug:messenger` и `messenger:setup-transports` создают очереди `diagnostics.*` с привязками, видно в UI RabbitMQ
- [ ] 6.2 Миграция `messenger_messages` через `make diff`; проверка: `make migrate`, `messenger:failed:show` без ошибок
- [ ] 6.3 `FailedMessageLogger` на `WorkerMessageFailedEvent` без ретрая: `error` с `event_id`, `event_type`, `correlation_id`, транспортом, ошибкой; проверка: юнит-тест с fake-логгером на оба случая — `willRetry` (лога нет) и финальная ошибка (лог с полями)

## 7. Сквозная проверка

- [ ] 7.1 Функциональный тест: in-memory транспорт с `EnvelopeSerializer` + тестовый хендлер — валидное событие обработано один раз при двойной доставке; невалидное попало в `failed` без ретраев; проверка: `make test`
- [ ] 7.2 Ручная проверка на брокере: опубликовать в `courses.events` через management API валидный `task.completed`, невалидный JSON и `quiz.answered`; `make consume`; ожидается: первые два — в `messenger:failed:show` (у первого пока нет хендлера — после 3 ретраев, у второго — сразу), `quiz.answered` в очереди `diagnostics.courses` не появился. Записать команды в описание PR

## Workflow follow-up

- Открыть PR `feat/event-envelope-inbox` → `main` после зелёного `make check`.
- После ревью — `/opsx:archive`: сверить `openspec/specs/event-ingestion` со SPEC.md и asyncapi.yaml.
