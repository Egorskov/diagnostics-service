# Proposal

## Why

Шаг 6 MVP (CLAUDE.md «Текущий скоуп», п. 6; SPEC §7, §9, §10): Диагностика уже знает активность, прогресс и списания, но никому об этом не сообщает. Сервис Уведомлений ждёт сигналы `diagnostics.*` (SPEC §1, §2), а без них ни ученик, ни ментор не узнают, что кто-то пропал, застрял или отстаёт от недельной цели.

## What Changes

- Три правила из SPEC §7.2 (остальные сигналы — бэклог §10):
  - `diagnostics.student.inactive` — ученику после 3 дней без активности, ментору после 7; Scheduler ежечасно;
  - `diagnostics.task.stuck` — ученику, когда активное время > 1,5 × оценки или неудачных запусков ≥ 10, проверка на `sandbox.run` и `activity.heartbeat`; ментору — через 24 ч после сигнала ученику, если задание не закрыто, Scheduler ежечасно;
  - `diagnostics.weekly_goal.behind` — ученику, если к четвергу 18:00 Europe/Belgrade списано меньше 50 % недельной цели.
- Дедупликация по SPEC §7.3: таблица `signal_state`, сигнал публикуется только на переходе условия в истину и сбрасывается, когда условие перестало выполняться.
- Транзакционный outbox (SPEC §9): сигнал записывается в той же транзакции, что `signal_state`, отдельный relay публикует его в exchange `diagnostics.events` с routing key = `event_type` в конверте `asyncapi.yaml → Envelope`, `producer` = `diagnostics`.
- Пороги правил (`inactive_student_days`, `inactive_mentor_days`, `weekly_goal_check`, `weekly_goal_min_ratio`, `stuck_time_ratio`, `stuck_failed_runs`, `stuck_escalation_hours`) — из таблицы `threshold` с начальными значениями §7.1 (ADR 0004).
- Задачи Scheduler в `DiagnosticsSchedule` (сейчас пустое расписание).
- Новая зависимость `symfony/uid` — UUID для `event_id` исходящих сигналов (design, решение 9).

Вне скоупа:
- Остальные сигналы §7.2: `time.log_missing`, `stage.completed`, `streak.milestone`, `student.at_risk`, `quiz.topic_failed`, `material.needs_review`, `mentor.weekly_digest` — бэклог SPEC §10.
- Cooldown, тихие часы и доставка — ответственность Уведомлений (SPEC §7.3).
- Активные сигналы в `GET /students/{id}/summary` — шаг 7.
- Очистка `signal_state` и опубликованных сообщений outbox, алерты по failure transport сверх лога.

`SPEC.md` и `asyncapi.yaml` не меняются: payload сигналов берётся из контрактов `StudentInactive`, `TaskStuck`, `WeeklyGoalBehind` как есть.

## Capabilities

### New Capabilities
- `signals`: правила трёх сигналов MVP, их пороги и дедупликация по переходу состояния (SPEC §7.1–7.3).
- `signal-publishing`: конверт исходящих сигналов, outbox и relay в `diagnostics.events` (SPEC §9, `asyncapi.yaml`).

### Modified Capabilities

Нет: хендлеры `sandbox.run`, `activity.heartbeat`, `task.completed` начинают проверять `task.stuck`, но их требования из `progress-tracking` не меняются.

## Impact

- Новые таблицы `signal_state` и строки порогов в `threshold`; outbox — в существующей `messenger_messages` (design, решение 6).
- Новые транспорты Messenger: `outbox` (Doctrine) и `signals` (AMQP, exchange `diagnostics.events`); `make consume` получает `outbox`.
- Хендлеры `SandboxRunHandler`, `ActivityHeartbeatHandler`, `TaskCompletedHandler` вызывают проверку `task.stuck`.
- Новый ADR `docs/adr/0005-signals-outbox.md`.
- Зависимость `symfony/uid`.
