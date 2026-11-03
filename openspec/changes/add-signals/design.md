# Design

## Context

Мотивация — proposal.md, требования — `specs/`.

Что есть после шагов 2–5:
- шина `doctrine_ping_connection → doctrine_transaction → InboxMiddleware`: хендлер работает в транзакции, `flush` и коммит делает `doctrine_transaction`;
- `TaskProgressRepository::findForUpdate` блокирует прогресс, а повторный вызов в той же транзакции возвращает сущность из identity map (шаг 4);
- `messenger_messages` уже есть (миграция шага 2, там же failure transport с `queue_name=failed`);
- `DiagnosticsSchedule` — пустое stateful-расписание, `make consume` уже слушает `scheduler_default`;
- `Thresholds` — типизированный порт без кеша, начальные значения — миграцией с данными (ADR 0004);
- UUID в проекте только приходят извне, генератора нет;
- `PathMetrics` шага 5 сигналам MVP не нужен: `student.at_risk` в бэклоге.

## Goals / Non-Goals

**Goals:**
- Правила — чистые функции в Domain с табличными тестами; транзакции, запросы и брокер — вокруг них.
- Outbox и конверт сигналов годятся для сигналов из бэклога без переделки.

**Non-Goals:**
- Блокировка расписания для нескольких воркеров, батчинг проверок, очистка outbox.

## Decisions

### 1. Слои и классы

| Часть | Слой | Где |
|---|---|---|
| `SignalType`, `Audience`, `SignalKey`, `SignalState` (переход состояния) | Domain | `src/Domain/Signal/` |
| Правила `Inactivity`, `TaskStuck`, `WeeklyGoal`, `WeeklyMoment` | Domain | `src/Domain/Signal/` |
| `SignalEmitter` — переход + запись в outbox; проверки `TaskStuckCheck`, `StudentInactivityCheck`, `StuckEscalationCheck`, `WeeklyGoalCheck` | Application | `src/Application/Signal/` |
| Сообщения расписания и их хендлеры | Application | `src/Application/Signal/` |
| `OutgoingSignal` — конверт сигнала | Application | `src/Application/Signal/` |
| Порты `SignalStateRepository`, `SignalOutbox`, `SignalPublisher`, `SignalReadModel`; новые методы `Thresholds` | Application | `src/Application/Port/` |
| Маппинг, миграции, DBAL-запросы, `DoctrineThresholds` | Infrastructure | `src/Infrastructure/Persistence/Doctrine/` |
| Outbox-транспорт, relay, сериализатор сигналов | Infrastructure | `src/Infrastructure/Messenger/` |
| Задачи расписания | Infrastructure | `src/Infrastructure/Scheduler/DiagnosticsSchedule.php` |

### 2. Переход состояния — в `SignalState`
`SignalState::observe(bool $condition, now): bool` возвращает `true` только на переходе ложь → истина: тогда `active` = true, `last_emitted_at` = now, а `first_emitted_at` ставится при первой публикации и дальше не меняется. Ложное условие сбрасывает `active`. Эскалация `task.stuck` отсчитывается от `last_emitted_at` сигнала ученику, то есть от последнего перехода, а не от самого первого.

`SignalEmitter::observe(key, condition, now, fn (): payload)` загружает или создаёт состояние, вызывает `observe` и при `true` пишет `OutgoingSignal` в outbox. Payload строится лениво: на ложном и повторно истинном условии он не нужен.

### 3. Ключ `signal_state` — строка, колонки — как в SPEC §5
Состояния различаются по (тип, аудитория, ученик, предмет), а предмет у `student.inactive` пустой. Составной первичный ключ Doctrine с `NULL` невозможен, а уникальный индекс `NULLS NOT DISTINCT` не попадает в `doctrine:migrations:diff`. Поэтому первичный ключ — детерминированная строка `тип|аудитория|ученик|предмет` (`SignalKey`), а колонки `signal_type`, `audience`, `student_id`, `subject_id NULL`, `active`, `first_emitted_at`, `last_emitted_at` остаются как в §5. Вставка дубликата ключа роняет транзакцию, и ретрай увидит уже созданное состояние.

`subject_id` — текст: UUID задания для `task.stuck`, дата понедельника `YYYY-MM-DD` для `weekly_goal.behind`. Ключ по неделе делает каждую неделю отдельным состоянием: «один сигнал в неделю» получается без расписания по дням.

*Альтернатива:* `subject_id NOT NULL` с пустой строкой. Отступает от §5 и требует помнить про `''` в каждом запросе.

### 4. Где проверяется `task.stuck`
`TaskStuckCheck::check(studentId, taskId, EventMetadata)` вызывается в конце хендлеров `sandbox.run` и `activity.heartbeat` (heartbeat — только с `task_id`) после того, как трекер обновил `active_minutes`. Прогресс берётся через `findForUpdate`: в этой транзакции он уже заблокирован и лежит в identity map, так что повторного запроса нет, а параллельные события одного задания проверяются по очереди. Оценка — `path_task` пути прогресса; если задания в пути нет, проверяется только условие по запускам.

`task.completed` вызывает ту же проверку: задание закрыто, условие ложно, состояния обеих аудиторий сбрасываются. Хендлеры шага 3 меняются на одну строку, их требования — нет.

*Альтернатива:* проверять в `SessionActivityTracker`. Тогда сигнал срабатывал бы и на `task.started`, а `sandbox.run` всё равно пришлось бы трогать.

### 5. Расписание — ежечасно, порог читается внутри проверки
Три задачи, каждая — cron `0 * * * *` в Europe/Belgrade: `CheckStudentInactivity`, `EscalateStuckTasks`, `CheckWeeklyGoals`. Недельная проверка тоже ежечасная: она сама решает, наступил ли момент `weekly_goal_check` текущей недели. Cron `0 18 * * 4` собрался бы один раз при старте воркера и не увидел бы изменение порога, а ADR 0004 требует читать пороги при каждой проверке. Ключ состояния по неделе (решение 3) не даёт отправить сигнал дважды. Цена — задержка до часа, если порог поставить не на начало часа; для значения по умолчанию задержки нет.

Каждая проверка обходит всех учеников за одну транзакцию шины: на объёмах MVP это секунды. Эскалация перед публикацией берёт прогресс через `findForUpdate`, чтобы не разминуться с параллельным `task.completed`.

### 6. Outbox — Doctrine-транспорт Messenger
`SignalOutbox` реализуется отправкой `OutgoingSignal` в транспорт `outbox` (`doctrine://default?queue_name=outbox&auto_setup=0`, таблица `messenger_messages`). Doctrine-транспорт пишет через то же соединение, поэтому `INSERT` попадает в транзакцию хендлера или проверки: откат убирает и `signal_state`, и сигнал. Relay — хендлер `OutgoingSignal` на транспорте `outbox`: он вызывает `SignalPublisher`, а тот отправляет конверт в AMQP-транспорт `signals` (exchange `diagnostics.events`, `topic`) с `AmqpStamp(routing key = event_type)`. Ретраи (3 попытки, 1 → 2 → 4 с) и failure transport — штатные Messenger, как у входящих событий (ADR 0002).

Транспорт настроен с `use_notify=false`. На PostgreSQL Doctrine-транспорт по умолчанию ждёт `LISTEN/NOTIFY`, когда очередь опустела, а триггер для `NOTIFY` создаётся только при `auto_setup`, который у нас выключен (таблица — из миграции). Без триггера новые и отложенные сообщения проверялись бы раз в `check_delayed_interval` (60 с): сигнал задерживался бы до минуты, а ретрай через 1 с — тоже до минуты. Нашёл функциональный тест ретрая (задача 4.2). Обычный опрос раз в секунду (`sleep` воркера) проще и не требует триггера вне миграций.

Тело в брокере — JSON конверта: его пишет `SignalSerializer`, а `decode()` не поддерживается, потому что свои сигналы мы не читаем. В `outbox` сообщение лежит в формате PHP-сериализатора: это внутренний транспорт.

*Альтернатива:* своя таблица `outbox` и команда-поллер с `FOR UPDATE SKIP LOCKED`. Даёт явную схему, но повторяет ретраи, failure transport и воркер, которые Messenger уже даёт.

### 7. Конверт сигнала
`event_id` — UUID v7, `occurred_at` — момент перехода из `ClockInterface`, `event_version` = 1, `producer` = `diagnostics`. `correlation_id` — `correlation_id` входящего события, а если его нет, `event_id` (spec, «Конверт сигнала»). У проверок расписания своей цепочки нет, поле не пишется. Payload собирается по схемам `StudentInactive`, `TaskStuck`, `WeeklyGoalBehind`; `mentor_id` берётся из текущего пути, а для `task.stuck` — из пути прогресса.

### 8. Данные для проверок — один запрос на проверку
`SignalReadModel` (DBAL, без сущностей):
- `studentsWithCurrentPath()` — `DISTINCT ON (student_id)` по `student_path` с сортировкой `started_at DESC, path_id DESC` и `LEFT JOIN` с `max(last_seen_at)` из `activity_session`; индекс `(student_id, task_id, last_seen_at)` шага 4 подходит;
- `loggedMinutes(weekStart, weekEnd)` — сумма `time_log.minutes` по ученикам за даты недели.

Эскалация читает активные состояния `task.stuck` с аудиторией `student` и `last_emitted_at` ≤ now − порог через `SignalStateRepository`.

### 9. Зависимость `symfony/uid`
Нужен UUID для `event_id`. `symfony/uid` — компонент Symfony той же версии 7.4, даёт `Uuid::v7()`, упорядоченный по времени, что удобно при разборе логов и failure transport. Самописный v4 — десяток строк, но генерация UUID — не то, что стоит поддерживать в проекте самим.

### 10. Пороги
Миграция с данными (ADR 0004, п. 5) добавляет семь строк §7.1. Новые методы `Thresholds`: `inactiveStudentDays(): int`, `inactiveMentorDays(): int`, `stuckTimeRatio(): float`, `stuckFailedRuns(): int`, `stuckEscalationHours(): int`, `weeklyGoalMinRatio(): float`, `weeklyGoalCheck(): WeeklyMoment`. `weekly_goal_check` хранится текстом из §7.1 `четверг 18:00 Europe/Belgrade`: русское название дня, `ЧЧ:ММ`, идентификатор зоны. Неделя для недельной цели считается в этой же зоне.

### 11. Сквозная часть — в ADR 0005
Outbox на Doctrine-транспорте, конверт и маршрутизация сигналов, at-least-once, ключ `signal_state` и семантика перехода понадобятся каждому сигналу из бэклога. Фиксируются в `docs/adr/0005-signals-outbox.md`.

## Risks / Trade-offs

- [Два воркера, читающих `scheduler_default`, запустят проверки дважды] → в MVP один воркер (`make consume`); для нескольких нужен `Schedule::lock()` и `symfony/lock` — бэклог. Дубль проверки не дублирует сигналы: второй увидит активное состояние или упадёт на ключе.
- [At-least-once: сбой между публикацией и ack outbox даст дубликат] → у дубликата тот же `event_id`, Уведомления дедуплицируют по нему (как наш inbox).
- [Проверка всех учеников в одной транзакции] → секунды на объёмах MVP; если вырастет — разбить на сообщения по ученику.
- [`task.stuck` на каждый heartbeat добавляет чтение `path_task` и `signal_state`] → оба по первичному ключу, прогресс уже в памяти.
- [Сигнал по старому событию после `failed:retry`] → осознанно: условие вычисляется по текущему состоянию, а не по моменту события.
- [`messenger_messages` растёт: Doctrine-транспорт удаляет обработанные сообщения, а failure — нет] → как и сейчас для входящих.

## Migration Plan

`make diff` (схема `signal_state`) → миграция с данными порогов → `make migrate`. Новые транспорты: `outbox` использует существующую таблицу, `signals` объявляет exchange `diagnostics.events` при первой отправке. `make consume` получает `outbox`. Откат: остановить воркер, `doctrine:migrations:migrate prev` дважды, revert; неопубликованные записи `outbox` останутся в `messenger_messages` с `queue_name=outbox`.

## Open Questions

- SPEC §11, вопросы 7–8 (поле `audience` и exchange `diagnostics.events`) остаются открытыми у команды Уведомлений; реализация следует текущему `asyncapi.yaml`, ответ может поменять только маршрутизацию.
