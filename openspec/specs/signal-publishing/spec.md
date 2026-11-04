# signal-publishing Specification

## Purpose

Как сигналы Диагностики попадают в RabbitMQ: конверт `asyncapi.yaml → Envelope`, транзакционный outbox и relay в exchange `diagnostics.events` (SPEC §9). Подписчик — сервис Уведомлений.

## Requirements

### Requirement: Конверт сигнала
Сигнал SHALL публиковаться в конверте `Envelope`: новый UUID в `event_id`, `event_type` — имя сигнала (например, `diagnostics.task.stuck`), `event_version` = 1, `occurred_at` — момент перехода в UTC, `producer` = `diagnostics`, `payload` по контракту сигнала в `asyncapi.yaml`. Для сигнала, вызванного входящим событием, `correlation_id` MUST равняться `correlation_id` этого события, а если его нет — `event_id`. У сигналов из Scheduler `correlation_id` нет.

#### Scenario: Сигнал из события
- **WHEN** `task.stuck` вызван `sandbox.run` без `correlation_id`
- **THEN** `correlation_id` сигнала равен `event_id` этого `sandbox.run`

#### Scenario: Сигнал из расписания
- **WHEN** `student.inactive` опубликован ежечасной проверкой
- **THEN** в конверте нет `correlation_id`, `producer` = `diagnostics`

#### Scenario: Контракт payload
- **WHEN** опубликован любой сигнал MVP
- **THEN** payload содержит все обязательные поля его схемы в `asyncapi.yaml`, включая `audience`, `student_id` и `mentor_id`

### Requirement: Outbox в одной транзакции
Сигнал MUST записываться в outbox в той же транзакции базы, что изменение состояния сигнала (SPEC §9). Если транзакция откатилась, нет ни состояния, ни сигнала. Публикация в брокер MUST NOT выполняться внутри этой транзакции.

#### Scenario: Ошибка после записи сигнала
- **WHEN** обработка события упала после того, как сигнал был записан
- **THEN** ни состояние сигнала, ни запись outbox не сохранились, ретрай события запишет их заново

#### Scenario: Брокер недоступен
- **WHEN** RabbitMQ недоступен в момент перехода условия
- **THEN** состояние сигнала и запись outbox сохранены, сигнал будет опубликован позже

### Requirement: Relay в exchange сигналов
Relay SHALL публиковать каждую запись outbox в exchange `diagnostics.events` типа `topic` с routing key = `event_type`, телом — JSON конверта и `content_type` = `application/json`. Доставка — at-least-once: при сбое публикация повторяется с тем же `event_id`, после исчерпания ретраев запись попадает в failure transport (SPEC §9).

#### Scenario: Публикация
- **WHEN** relay обработал запись `diagnostics.weekly_goal.behind`
- **THEN** в exchange `diagnostics.events` пришло сообщение с routing key `diagnostics.weekly_goal.behind` и тем же `event_id`

#### Scenario: Повтор после сбоя
- **WHEN** первая попытка публикации упала
- **THEN** повторная попытка публикует тот же конверт с тем же `event_id`

#### Scenario: Ретраи исчерпаны
- **WHEN** публикация не удалась за все попытки
- **THEN** запись находится в failure transport и её можно повторить вручную
