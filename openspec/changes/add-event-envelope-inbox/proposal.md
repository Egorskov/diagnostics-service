# Proposal

## Why

Шаг 2 MVP (CLAUDE.md, «Текущий скоуп»): прежде чем писать консьюмеры (шаг 3), сервис должен уметь принимать события из RabbitMQ в общем конверте (`asyncapi.yaml → Envelope`, SPEC §4), превращать их в типизированные сообщения и обрабатывать каждое событие ровно один раз по `event_id` (SPEC §5 `inbox_event`, §9 «Идемпотентность», «Ретраи»). Сейчас транспорты Messenger не настроены, и `make consume` не работает.

## What Changes

- Value object метаданных конверта (`event_id`, `event_type`, `event_version`, `occurred_at`, `producer`, `correlation_id`) с валидацией обязательных полей по `Envelope`.
- DTO для шести событий MVP из SPEC §10 — `path.assigned`, `task.started`, `task.completed`, `time.logged`, `activity.heartbeat`, `sandbox.run`. Поля и обязательность берутся из `asyncapi.yaml`.
- Кастомный сериализатор Messenger: разбирает конверт, по `event_type` выбирает DTO; невалидный конверт, неизвестный `event_type` или неподдерживаемая `event_version` не теряются, а попадают в failure transport без ретраев.
- AMQP-транспорты для входящих: отдельная очередь на каждый exchange-источник (`courses.events`, `time.events`, `activity.events`); `activity.heartbeat` — в своей очереди (SPEC §9 «Нагрузка»). Привязка — только routing keys событий MVP.
- Таблица `inbox_event` (SPEC §5) и middleware, которое записывает `event_id` в той же транзакции, что и изменения хендлера; повторная доставка не вызывает хендлер.
- Ретраи: 3 попытки с экспоненциальной задержкой, затем failure transport и запись об ошибке в лог с `event_id` и `correlation_id` (SPEC §9).
- ADR 0002 «Конверт событий, inbox и топология входящих очередей» — решение сквозное, переживёт этот change.

## Capabilities

### New Capabilities
- `event-ingestion`: приём входящих событий — разбор конверта, сопоставление `event_type` с типизированным сообщением, идемпотентность по `event_id`, поведение при невалидных сообщениях и ошибках обработки.

### Modified Capabilities
<!-- нет: openspec/specs/ пока пуст -->

## Impact

- **Код:** `src/Application/Event/` (метаданные, DTO), `src/Infrastructure/Messenger/` (сериализатор, middleware inbox, логирование ошибок), `src/Infrastructure/Persistence/` (inbox), миграция для `inbox_event` и таблицы failure transport.
- **Конфиг:** `config/packages/messenger.yaml` — транспорты, ретраи, failure transport, middleware шины.
- **Makefile / CLAUDE.md:** имена транспортов в `make consume` меняются с `async heartbeat` на `courses time heartbeat` — см. design.md §5, подтверждено.
- **Брокер:** сервис объявляет свои очереди `diagnostics.*` и привязывает их к exchange других сервисов; объявление exchange идемпотентно при совпадающем типе (`topic`, как в asyncapi).
- **Зависимости:** одна новая — `symfony/doctrine-messenger` для failure transport в PostgreSQL (чтобы непринятые сообщения можно было смотреть и повторять через `messenger:failed:*`, см. design.md §5). Маппинг payload → DTO пишется вручную, логирование через PSR `LoggerInterface`.
- **Контракты:** `SPEC.md` и `asyncapi.yaml` не меняются.

### Вне скоупа
- Хендлеры событий и бизнес-логика (шаг 3); сейчас сообщения без хендлера уходят в failure transport.
- События бэклога (`user.registered`, `path.updated`, `quiz.answered`, `hint.used`, `material.rated`, `assessment.completed`) — очереди к ним не привязываются.
- Исходящие сигналы и outbox (шаг 6), эмулятор событий (шаг 8).
- Очистка старых записей `inbox_event`, monolog и JSON-логи, алерты вне лога.
