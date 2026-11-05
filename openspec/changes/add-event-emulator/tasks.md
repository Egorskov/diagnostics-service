# Tasks

Предусловие: шаг 7 заархивирован (`add-read-api`), работа идёт в ветке `feat/event-emulator` от `main`. Каждая задача: код → тесты → зелёный `make check` → коммит (Conventional Commits). Новых зависимостей нет.

## 1. Генератор

- [x] 1.1 `EmulatedEvent`, `Profile`, `Scenario` и `ScenarioGenerator` в `src/UI/Console/Emulator/` для профиля `steady` (требования «Поток событий одного ученика», «Конверт и payload по контракту»; design, решения 3, 5). Проверка юнит-тестом:
  - первое событие — `path.assigned` с `started_at` за `--days` дней;
  - `occurred_at` не убывает и не позже `now`;
  - `task.started` раньше `task.completed`;
  - `sandbox.run` только у заданий `sandbox`;
  - heartbeat раз в 60 с;
  - уникальные `event_id`;
  - одинаковый seed даёт одинаковый поток;
  - каждое событие разбирается `EnvelopeSerializer::decode()` не в `UndecodableMessage`.

  `make check`; коммит `feat: event emulator scenario generator`
- [ ] 1.2 Профили `behind`, `stuck`, `inactive` и защита `steady` от недельного сигнала. Всё через `Thresholds` (требование «Профили поведения»; design, решение 4). Проверка табличным юнит-тестом на in-memory порогах, включая нестандартные пороги и `--days` = 1:
  - `stuck`: active > ratio × оценка;
  - `inactive`: пауза ≥ N дней;
  - `behind`: неделя < ratio × цель;
  - `steady`: после четверга 18:00 неделя ≥ ratio × цель, активное время каждого задания ≤ ratio × оценка.

  `make check`; коммит `feat: emulator behaviour profiles`

## 2. Команда

- [ ] 2.1 `MessengerEventPublisher`: sender транспорта с `RawBodyStamp` и `AmqpStamp(event_type)` (design, решение 2). Проверка юнит-тестом:
  - транспорт по типу события;
  - routing key;
  - `EnvelopeSerializer::encode()` возвращает тело байт-в-байт и `Content-Type: application/json`.

  `make check`; коммит `feat: publish emulated events to broker transports`
- [ ] 2.2 `EmulateEventsCommand` (`app:emulate-events`): опции `--student`, `--mentor`, `--days`, `--profile`, `--seed`, `--dry-run`, валидация и вывод (требования «Публикация тем же путём, что у продюсеров», «Воспроизводимость и опции»; design, решение 7). Проверка тестом через `CommandTester`:
  - `--dry-run` печатает NDJSON;
  - два запуска с одним seed дают одинаковый вывод;
  - `--profile=lazy`, неверный UUID, `--days=0`, `inactive` с малым `--days` дают ненулевой код и пустые транспорты;
  - вывод содержит id и токены.

  `make check`; коммит `feat: app:emulate-events command`

## 3. Сквозная проверка

- [ ] 3.1 Функциональный тест: эмулятор → тела из in-memory транспортов → `deliver()` → `consume` (требования «Профили поведения», «Публикация тем же путём, что у продюсеров», «Воспроизводимость и опции»; design, решения 2, 4). Проверки:
  - `steady`: failure transport пуст, summary ученика 200 с путём, ментор видит ученика; после `CheckStudentInactivity` и `CheckWeeklyGoals` в четверг 19:00 активных сигналов нет;
  - `stuck`: активно `task.stuck`;
  - `inactive`: после `CheckStudentInactivity` активно `student.inactive`;
  - `behind`: после `CheckWeeklyGoals` в четверг 19:00 активно `weekly_goal.behind`;
  - повторная доставка того же seed ничего не меняет.

  `make check`; коммит `test: event emulator end-to-end flow`
- [ ] 3.2 Ручная проверка на настоящем RabbitMQ:
  - `make emulate ARGS="--profile=stuck --seed=…"`, затем `make consume`;
  - очереди `diagnostics.courses`, `diagnostics.time`, `diagnostics.heartbeat` опустели, failure transport пуст;
  - выведенными токенами `curl` к `/api/v1/students/{id}/summary` и `/api/v1/mentor/students` отвечает 200;
  - во временной очереди на `diagnostics.events` пришёл `diagnostics.task.stuck`;
  - повторный запуск с тем же seed ничего не меняет;
  - тестовые данные удалены.

  Коммит `docs: mark manual emulator check as done`
