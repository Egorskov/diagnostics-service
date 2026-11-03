# Proposal

## Why

Шаг 4 MVP (CLAUDE.md, «Текущий скоуп»; SPEC §6.1, §10): точки активности уже доходят до порта `ActivityTracker`, но заглушка их отбрасывает, поэтому `task_progress.active_minutes` всегда 0. От активного времени зависят сигнал `task.stuck` (§7.2) и `week.active_minutes` в Read-API (§8), и пока заглушка на месте, реальный поток событий подключать нельзя.

## What Changes

- Таблица `activity_session` (SPEC §5): сессии активности ученика по заданию или без задания.
- Сессионизация по SPEC §6.1 заменяет `NullActivityTracker`: точка продлевает подходящую сессию или открывает новую, а `task_progress.active_minutes` пересчитывается как сумма сессий задания.
- Таблица `threshold` (SPEC §5, §7.1) и порт чтения порогов. Значение `idle_timeout_min` = 15 добавляется отдельной миграцией с данными: `doctrine:migrations:diff` данные не генерирует. Остальные пороги добавит шаг, которому они понадобятся.
- ADR 0004 «Пороги и их начальные значения» — решение сквозное, им воспользуется шаг 6.

## Capabilities

### New Capabilities
- `activity-sessions`: как точки активности складываются в сессии и как из сессий получается активное время задания.

### Modified Capabilities
- `progress-tracking`: требование «Heartbeat принимается как точка активности» — теперь heartbeat обновляет `active_minutes`, если прогресс по заданию есть.

## Impact

- **Код:** `src/Domain/Progress/ActivitySession.php`, `TaskProgress` (обновление активного времени), `src/Domain/Threshold/`, `src/Application/Activity/`, `src/Application/Port/`, `src/Infrastructure/Persistence/Doctrine/` (репозитории, маппинг, миграции). Удаляется `src/Infrastructure/Activity/NullActivityTracker.php`.
- **Тесты:** `ProgressFlowTest` ожидал `active_minutes` = 0 и будет обновлён.
- **Зависимости:** новых нет.
- **Брокер и конфиг:** транспорты и binding keys не меняются.
- **Контракты:** `SPEC.md` и `asyncapi.yaml` не меняются.
- **Порядок:** change `add-event-consumers` (PR #6) должен быть влит и заархивирован раньше: дельта `progress-tracking` ссылается на его спеку.

### Вне скоупа
- EV/PV/AC, SPI/CPI, прогноз, регулярность и «расхождение» (шаг 5).
- Сигналы, в том числе `task.stuck` и `student.inactive`, остальные пороги §7.1 и настройка порогов ментором (шаг 6 и бэклог).
- Read-API (шаг 7). `quiz.answered` как точка активности (бэклог).
- Склейка соседних сессий и очистка старых сессий.
