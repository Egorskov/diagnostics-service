# Spec Delta

## MODIFIED Requirements

### Requirement: Heartbeat принимается как точка активности
`activity.heartbeat` SHALL обрабатываться успешно и подтверждаться брокеру, не попадая в failure transport, независимо от наличия пути и прогресса. Из данных прогресса heartbeat MUST менять только `active_minutes` и только у существующего прогресса: статус, время и счётчики не меняются, новый прогресс не создаётся (SPEC §6.1).

#### Scenario: Heartbeat без задания
- **WHEN** приходит `activity.heartbeat` без `task_id`
- **THEN** событие подтверждено, таблицы прогресса не изменились

#### Scenario: Heartbeat по неизвестному заданию
- **WHEN** приходит `activity.heartbeat` с `task_id`, для которого нет прогресса
- **THEN** событие подтверждено, прогресс не создан

#### Scenario: Heartbeat по заданию с прогрессом
- **WHEN** приходит `activity.heartbeat` с `task_id`, для которого есть прогресс
- **THEN** событие подтверждено, `active_minutes` обновлён, статус и `failed_runs` не изменились
