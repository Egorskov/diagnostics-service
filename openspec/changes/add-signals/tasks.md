# Tasks

Предусловие: шаг 5 заархивирован (`add-path-metrics`), работа в ветке `feat/signals` от `main`. Каждая задача: код → тесты → зелёный `make check` → коммит (Conventional Commits).

## 1. Основа

- [x] 1.1 Зависимость `symfony/uid` 7.4 для `event_id` сигналов (design, решение 9). Проверка: `composer.json` и `composer.lock` обновлены, `make check` зелёный; коммит `build: add symfony/uid for outgoing event ids`
- [x] 1.2 ADR `docs/adr/0005-signals-outbox.md`: outbox на Doctrine-транспорте, конверт и маршрутизация сигналов, at-least-once, ключ `signal_state`, семантика перехода (design, решения 2, 3, 6, 7). Проверка: ADR ссылается на SPEC §7.3, §9 и ADR 0002, 0004; коммит `docs: ADR 0005 signals and outbox`

## 2. Пороги

- [x] 2.1 `WeeklyMoment` в `src/Domain/Signal/` (день недели, время, зона; начало недели и момент проверки для `now`). Проверка: табличный юнит-тест на четверг 17:59/18:00, воскресенье 23:59, понедельник 00:00 и переход на зимнее время; `make check`; коммит `feat: weekly check moment`
- [x] 2.2 Миграция с данными для семи порогов §7.1 и методы `Thresholds` с разбором чисел, долей и `четверг 18:00 Europe/Belgrade` (design, решение 10; ADR 0004). Проверка: интеграционный тест — значения после миграций, ошибка на отсутствующем и мусорном значении; `make check`; коммит `feat: signal thresholds`

## 3. Состояние сигнала

- [x] 3.1 `SignalType`, `Audience`, `SignalKey`, `SignalState::observe()` в `src/Domain/Signal/` (design, решения 2–3). Проверка: табличный юнит-тест переходов ложь/истина, повтор, сброс, новый переход и неизменный `first_emitted_at`; `make check`; коммит `feat: signal state transitions`
- [x] 3.2 XML-маппинг `signal_state`, миграция через `make diff`, порт `SignalStateRepository` и Doctrine-реализация (поиск по ключу, активные `task.stuck` для эскалации). Проверка: интеграционный тест на сохранение, поиск и выборку для эскалации; `make check`; коммит `feat: signal state persistence`

## 4. Публикация

- [x] 4.1 `OutgoingSignal` — конверт по `asyncapi.yaml → Envelope` с `event_id` UUID v7 и правилом `correlation_id` (требование «Конверт сигнала», design, решение 7). Проверка: юнит-тест на поля конверта для сигнала из события (с `correlation_id` и без) и из расписания; `make check`; коммит `feat: outgoing signal envelope`
- [x] 4.2 Транспорты `outbox` (Doctrine) и `signals` (AMQP, `diagnostics.events`), `SignalSerializer`, relay-хендлер и `SignalPublisher`; `outbox` в `make consume` и in-memory в тестах (требование «Relay в exchange сигналов», design, решение 6). Проверка: функциональный тест — сигнал из `outbox` попадает в `signals` с routing key = `event_type` и телом-конвертом, падение отправки уходит в ретраи; `make check`; коммит `feat: outbox relay to diagnostics.events`
- [x] 4.3 `SignalEmitter`: переход состояния и запись в outbox в одной транзакции (требования «Дедупликация по переходу состояния», «Outbox в одной транзакции»). Проверка: функциональный тест — откат транзакции не оставляет ни состояния, ни записи outbox; повторная истина не пишет второй сигнал; `make check`; коммит `feat: emit signals on state transitions`

## 5. Правила

- [x] 5.1 Правила `Inactivity`, `TaskStuck`, `WeeklyGoal` в `src/Domain/Signal/` (требования «Неактивный ученик», «Застревание — сигнал ученику», «Отставание от недельной цели»). Проверка: табличные юнит-тесты на все границы сценариев (3 суток и 2 суток 23 часа, 90 и 91 минута при оценке 60, 9 и 10 запусков, 749 и 750 минут, задание закрыто, нет оценки); `make check`; коммит `feat: signal rules`
- [x] 5.2 `TaskStuckCheck` в хендлерах `sandbox.run`, `activity.heartbeat`, `task.completed` (design, решение 4). Проверка: функциональный тест через воркер — heartbeat доводит время до 91 и даёт один сигнал в `signals`, десятый неудачный запуск даёт `reason` = `failed_runs`, `task.completed` сбрасывает состояния, нет порога — событие в ретраях без изменений; `make check`; коммит `feat: task.stuck signal on activity`
- [x] 5.3 `SignalReadModel` на DBAL: текущий путь, последняя активность и списания за неделю (требования «Текущий путь ученика», «Последняя активность», design, решение 8). Проверка: интеграционный тест на два пути, ученика без сессий и списание без задания; `make check`; коммит `feat: signal read model`
- [ ] 5.4 `StudentInactivityCheck` и сообщение `CheckStudentInactivity` (требование «Неактивный ученик»). Проверка: функциональный тест с фиксированными часами — 3 суток → сигнал ученику, 7 суток → ментору без повтора ученику, новая активность сбрасывает обе аудитории; `make check`; коммит `feat: student.inactive check`
- [ ] 5.5 `StuckEscalationCheck` и сообщение `EscalateStuckTasks` (требование «Застревание — эскалация ментору»). Проверка: функциональный тест — 23 и 24 часа после сигнала ученику, без повтора после эскалации, задание закрыто — эскалации нет; `make check`; коммит `feat: task.stuck escalation to mentor`
- [ ] 5.6 `WeeklyGoalCheck` и сообщение `CheckWeeklyGoals` (требование «Отставание от недельной цели»). Проверка: функциональный тест — четверг 17:00 и 18:00, 540 и 750 минут из 1500, новая неделя даёт новый сигнал, списание без задания учитывается; `make check`; коммит `feat: weekly_goal.behind check`
- [ ] 5.7 Задачи в `DiagnosticsSchedule`: три сообщения по cron `0 * * * *` в Europe/Belgrade (design, решение 5). Проверка: юнит-тест расписания — три задачи и их ближайший запуск в зоне Belgrade; `make check`; коммит `feat: hourly signal checks in scheduler`

## 6. Проверка целиком

- [ ] 6.1 Ручная проверка на RabbitMQ: временная очередь, привязанная к `diagnostics.events` по `diagnostics.#`; поток событий доводит задание до `task.stuck`, `messenger:consume outbox` публикует сигнал с верным routing key и конвертом; failure transport пуст, тестовые строки удалены. Проверка: команды и результат — в описании PR
- [ ] 6.2 `openspec validate add-signals --strict` проходит, итоговые требования сверены с SPEC §7 и `asyncapi.yaml`. Проверка: вывод команды; коммит `docs: mark manual signals check as done`

## Workflow follow-up

- После ревью и влития PR: `/opsx:archive add-signals`.
- Шаг 7 покажет активные сигналы из `signal_state` в `GET /students/{id}/summary`.
