# Tasks

Каждая задача: код → тесты → зелёный `make check` → коммит (Conventional Commits).

Перед стартом: PR #5 (пустые строки в payload) и PR #4 (архив шага 2) влиты в `main`, ветка `feat/event-consumers` создана от `main`.

## 1. Решение и подготовка

- [x] 1.1 Написать `docs/adr/0003-event-ordering.md`: прогресс не зависит от порядка событий, статус только вперёд, раннее время побеждает; событие без контекста — ошибка обработки → ретраи → failure transport (design §3, §6); проверка: ADR ссылается на SPEC §6.1, §9, ADR 0002 и design.md этого change. Коммит `docs: adr 0003 event ordering`
- [x] 1.2 Перенести `TimeCategory`, `SandboxResult`, `ActivitySource` в `src/Domain/Progress/`, обновить импорты DTO и тестов; проверка: `make check` зелёный без изменения поведения. Коммит `refactor: move progress enums to domain`

## 2. Домен (чистый PHP)

- [x] 2.1 `TaskStatus` и `TaskProgress` (`start`, `complete`, `recordFailedRun`; новый прогресс: `not_started`, счётчики 0); проверка: табличный юнит-тест переходов — все пары статус/событие, оба порядка старта и завершения, завершение без старта, повторное завершение с более поздним временем, инкремент не меняет статус
- [ ] 2.2 `StudentPath` и `PathTask` с обновлением из снапшота; проверка: юнит-тест, что обновление меняет ментора, цель, `started_at`, этап, оценку и позицию
- [ ] 2.3 `TimeLog` и `SandboxRun` с признаком неудачного запуска; проверка: табличный юнит-тест: `failed` и `error` неудачные, `passed` нет

## 3. Хранение (Infrastructure)

- [ ] 3.1 XML-маппинг пяти сущностей по SPEC §5; `make diff` генерирует миграцию; проверка: `make migrate`, затем `doctrine:schema:validate` без ошибок и `doctrine:migrations:diff` сообщает «no changes». Коммит включает сгенерированную миграцию без ручных правок
- [ ] 3.2 Порты и Doctrine-репозитории пути и заданий; проверка: интеграционный тест на тестовой БД — сохранение и чтение с составным PK, задания пути выбираются только своего пути
- [ ] 3.3 Порт и репозиторий прогресса с `PESSIMISTIC_WRITE` (design §8), репозитории `TimeLog` и `SandboxRun`; проверка: интеграционный тест: поиск вне транзакции падает как ожидается для блокировки, в транзакции возвращает строку; `logged_for_date` читается обратно тем же днём, время — в UTC

## 4. Хендлеры (Application)

- [ ] 4.1 `ActivityPoint`, порт `ActivityTracker`, `NullActivityTracker`; хендлер `ActivityHeartbeat`; проверка: юнит-тест с записывающим фейком — точка с источником, заданием и моментом `occurred_at`; `make check` подтверждает автопривязку порта
- [ ] 4.2 Хендлер `PathAssigned`: путь, полная замена заданий, прогресс `not_started` только для новых; проверка: юнит-тесты на фейках — все сценарии требования «Назначение пути»
- [ ] 4.3 Хендлеры `TaskStarted` и `TaskCompleted`: создание прогресса с `path_id` из события, переход, точка активности с источником `other`; проверка: юнит-тесты сценариев «Начало задания», «Завершение задания», «Событие раньше назначения пути»
- [ ] 4.4 Хендлер `TimeLogged`; проверка: юнит-тест сценариев «Списание времени» — с заданием, без задания, две записи за день
- [ ] 4.5 Хендлер `SandboxRun` и `UnknownTaskProgress`; проверка: юнит-тесты — `failed`, `error`, `passed`, дубликат `run_id` без изменений и без точки активности, неизвестное задание бросает исключение и ничего не сохраняет

## 5. Сквозная проверка

- [ ] 5.1 Удалить `RecordingEventHandler` и его запись в `config/services_test.yaml`; перевести `EventIngestionTest` на настоящие хендлеры: двойная доставка `task.completed` меняет прогресс один раз, очистка таблиц прогресса в `setUp`; проверка: `make test`
- [ ] 5.2 Функциональный тест потока через воркер и in-memory транспорт: `path.assigned` → `task.started` → `sandbox.run` (`failed`) → `time.logged` → `task.completed` → `activity.heartbeat`; проверка: итоговые строки в таблицах соответствуют сценариям спеки, `failed` транспорт пуст
- [ ] 5.3 Функциональный тест: `sandbox.run` по неизвестному заданию не оставляет строк в `sandbox_run` и `inbox_event` и уходит на повтор; после обработки `path.assigned` повтор проходит; проверка: `make test`
- [ ] 5.4 Ручная проверка на брокере: опубликовать через management API тот же поток в `courses.events`, `time.events`, `activity.events`; `make consume`; ожидается: `messenger:failed:show` пуст, строки в таблицах по сценарию; затем `sandbox.run` по неизвестному заданию после 3 ретраев виден в `messenger:failed:show`. Команды записать в описание PR

## Workflow follow-up

- Открыть PR `feat/event-consumers` → `main` после зелёного `make check`.
- После ревью — `/opsx:archive`: сверить `openspec/specs/progress-tracking` со SPEC.md и asyncapi.yaml.
