# Tasks

Предусловие: шаг 4 заархивирован (`add-activity-sessions`), работа в ветке `feat/path-metrics` от `main`. Каждая задача: код → табличные тесты → зелёный `make check` → коммит (Conventional Commits).

## 1. Входные данные

- [x] 1.1 `PlannedTask` и `PathPlan` в `src/Domain/Metrics/` (design, решения 1–2): неизменяемые, оценка задания ≥ 1, списанные минуты ≥ 0, список заданий типизирован для PHPStan. Проверка: юнит-тест на конструирование и отказ при оценке 0 или отрицательных минутах; `make check`; коммит `feat: path metrics input value objects`

## 2. EV, PV, AC

- [ ] 2.1 `PathMetrics::calculate(PathPlan, now, zone)` с `EV`, `PV`, `AC` (требования «Освоено», «План», «Факт», design, решение 4). Проверка: табличный тест `tests/Unit/Domain/Metrics/PathMetricsTest.php` по всем сценариям требования, включая границу `now` = `started_at` и неполную минуту плана; `make check`; коммит `feat: earned, planned and actual minutes`

## 3. SPI и CPI

- [ ] 3.1 `SPI` и `CPI` как `?float` (требование «Темп и эффективность»). Проверка: табличный тест на пример §8 (`assertEqualsWithDelta`), нулевой `PV` и нулевой `AC`; `make check`; коммит `feat: schedule and cost performance indexes`

## 4. Даты

- [ ] 4.1 Плановая дата завершения в переданной зоне (требования «Календарные даты», «Плановая дата завершения», design, решение 5). Проверка: табличный тест на ровные 4 недели, неполный день, нулевую цель, пустой путь и `started_at` перед полуночью по Белграду; `make check`; коммит `feat: planned finish date`
- [ ] 4.2 Прогноз завершения (требование «Прогноз завершения», design, решения 6–7). Проверка: табличный тест на все сценарии требования, включая границы окна ровно 28 суток и 28 суток плюс секунда, делитель 1 и 4, пройденный путь и темп 0; `make check`; коммит `feat: forecast finish date`
- [ ] 4.3 Отставание в днях (требование «Отставание»). Проверка: табличный тест на отставание, опережение, ноль и отсутствие прогноза или плановой даты; `make check`; коммит `feat: delay against planned finish`

## 5. Проверка целиком

- [ ] 5.1 Сквозной тест на пример ответа SPEC §8: один `PathPlan` даёт `ev 2700, pv 3600, ac 3300`, `spi` 0,75, `cpi` 0,82 после округления до сотых и согласованные плановую дату, прогноз и отставание. Проверка: тест зелёный, `make check` и `openspec validate add-path-metrics --strict` проходят; коммит `test: path metrics against spec example`

## Workflow follow-up

- После ревью и влития PR: `/opsx:archive add-path-metrics`.
- Шаг 7 (Read-API) соберёт `PathPlan` из базы, передаст `now` из `ClockInterface` и зону Europe/Belgrade.
