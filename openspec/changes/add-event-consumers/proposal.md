# Proposal

## Why

Шаг 3 MVP (CLAUDE.md, «Текущий скоуп»; SPEC §10): события уже принимаются и разбираются (шаг 2), но хендлеров нет, поэтому каждое событие после ретраев уходит в failure transport, а состояния, из которого считаются EV/PV/AC (SPEC §6.2), не существует. Шаги 4–7 опираются на это состояние.

## What Changes

- Таблицы SPEC §5, которые наполняются событиями MVP: `student_path`, `path_task`, `task_progress`, `time_log`, `sandbox_run` — доменные классы, XML-маппинг (ADR 0001), миграция через `doctrine:migrations:diff`.
- Хендлеры шести событий MVP:
  - `path.assigned` — сохраняет путь и задания (полный снапшот), заводит прогресс `not_started`;
  - `task.started`, `task.completed` — переводят статус и фиксируют время из `occurred_at`;
  - `time.logged` — запись в `time_log`;
  - `sandbox.run` — запись в `sandbox_run`, счётчик `failed_runs`;
  - `activity.heartbeat` — только передаёт точку активности дальше.
- Порт точек активности: `task.started`, `task.completed`, `sandbox.run` и `activity.heartbeat` передают в него точку (SPEC §3). На этом шаге реализация пустая, настоящая появится на шаге 4.
- Правила для событий не по порядку и без контекста — SPEC их не задаёт (§6.1 говорит только «примерно по порядку»). Решение сквозное, фиксируется в ADR 0003.
- Enum `TimeCategory`, `SandboxResult`, `ActivitySource` переезжают из `Application` в `Domain`: на них теперь ссылаются доменные классы, а Domain не может зависеть от Application.
- Функциональный тест приёма переходит с хендлера-заглушки на настоящие хендлеры, заглушка удаляется.

## Capabilities

### New Capabilities
- `progress-tracking`: как входящие события меняют состояние ученика — путь и задания, статус и время по заданиям, списанное время, запуски песочницы; поведение при повторах, нарушенном порядке и событиях без контекста.

### Modified Capabilities
<!-- нет: приём, inbox и ретраи (event-ingestion) не меняются -->

## Impact

- **Код:** `src/Domain/Progress/` (новое), `src/Application/Handler/`, `src/Application/Port/`, `src/Infrastructure/Persistence/Doctrine/` (репозитории, маппинг, миграция), `src/Infrastructure/Activity/`; импорты enum в DTO `src/Application/Event/Incoming/`.
- **Тесты:** удаляются `tests/Support/RecordingEventHandler.php` и его запись в `config/services_test.yaml`; переписывается `tests/Functional/EventIngestionTest.php`.
- **Зависимости:** новых нет.
- **Брокер и конфиг:** транспорты и binding keys не меняются.
- **Контракты:** `SPEC.md` и `asyncapi.yaml` не меняются.

### Вне скоупа
- Сессионизация, `activity_session`, расчёт `active_minutes` (шаг 4): до него `active_minutes` остаётся 0, а heartbeat не оставляет следа в БД.
- Формулы EV/PV/AC, SPI/CPI и прогноз (шаг 5).
- Сигналы, `signal_state`, `threshold`, outbox (шаг 6); `failed_runs` здесь только считается, порог не проверяется.
- Read-API (шаг 7), эмулятор (шаг 8).
- События бэклога: `user.registered` и `user_role`, `path.updated`, `quiz.answered`, `hint.used` (`max_hint_level` остаётся 0), `material.rated`, `assessment.completed`.
- Поля контракта без колонки в SPEC §5 не сохраняются: `tasks[].kind`, `tests_passed`, `tests_total`.
