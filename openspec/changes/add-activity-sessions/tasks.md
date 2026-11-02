# Tasks

Каждая задача: код → тесты → зелёный `make check` → коммит (Conventional Commits).

Перед стартом: PR #6 влит в `main`, change `add-event-consumers` заархивирован (`openspec/specs/progress-tracking` существует), ветка `feat/activity-sessions` создана от `main`.

## 1. Пороги

- [x] 1.1 Написать `docs/adr/0004-thresholds.md`: таблица `threshold`, значение как текст, типизированный порт, чтение без кеша, ошибка вместо значения из кода, миграция с данными как единственное исключение из правила diff (design §6, §7); проверка: ADR ссылается на SPEC §5, §7.1, ADR 0003 и design.md этого change. Коммит `docs: adr 0004 thresholds`
- [x] 1.2 Сущность `Threshold`, XML-маппинг, порт `Thresholds`, Doctrine-реализация с ошибкой при отсутствии и нечисловом значении; `make diff` генерирует миграцию схемы; проверка: `make migrate`, `doctrine:schema:validate` без ошибок; интеграционный тест: число, нет строки, не число
- [ ] 1.3 Миграция с данными через `doctrine:migrations:generate`: `idle_timeout_min` = 15 с `ON CONFLICT DO NOTHING`; проверка: интеграционный тест читает 15 после `make test-db`, `doctrine:migrations:diff` сообщает «no changes», откат и повторное применение миграции не дают ошибок

## 2. Домен (чистый PHP)

- [ ] 2.1 `ActivitySession` (`covers`, `touch`, `minutes`); проверка: табличные юнит-тесты — границы окна (ровно 15 минут, 15 минут и 1 с, в обе стороны), точка внутри сессии, расширение границ, длительность 0 с, 90 с, 120 с
- [ ] 2.2 `TaskProgress::updateActiveMinutes()`; проверка: юнит-тест — значение обновляется, статус и `failed_runs` не меняются

## 3. Хранение (Infrastructure)

- [ ] 3.1 XML-маппинг `ActivitySession` с индексом `(student_id, task_id, last_seen_at)`; `make diff` генерирует миграцию; проверка: `make migrate`, `doctrine:schema:validate` без ошибок, `doctrine:migrations:diff` сообщает «no changes». Коммит включает сгенерированную миграцию без ручных правок
- [ ] 3.2 Порт и репозиторий `ActivitySessionRepository` (`forTask`, `withoutTaskNear`, `add`); проверка: интеграционный тест — сессии чужого ученика и задания не попадают в выборку, `withoutTaskNear` учитывает границы окна и не возвращает сессии с заданием

## 4. Сессионизация (Application)

- [ ] 4.1 `SessionActivityTracker`, удаление `NullActivityTracker`; обновить `ProgressFlowTest` под ненулевой `active_minutes`; проверка: юнит-тесты на фейках — все сценарии требований «Продление и открытие сессии», «Источник сессии», «Опоздавшие точки», «Активное время задания», «Таймаут берётся из настроек» (значение из порта, ошибка порта не меняет сессии); `make check` подтверждает автопривязку порта

## 5. Сквозная проверка

- [ ] 5.1 Функциональный тест через воркер и БД: `task.started` как первое событие даёт `active_minutes` = 1 (прогресс и сессия в одной транзакции до `flush`); два heartbeat с интервалом 60 с и один через 16 минут дают две сессии и сумму; heartbeat по заданию без прогресса сохраняет сессию; без строки порога событие не отмечается в inbox и уходит на повтор; проверка: `make test`
- [ ] 5.2 Ручная проверка на брокере: опубликовать через management API `path.assigned`, `task.started` и heartbeat по заданию; `make consume`; ожидается: `activity_session` и `active_minutes` по сценарию, `messenger:failed:show` пуст. Команды записать в описание PR

## Workflow follow-up

- Открыть PR `feat/activity-sessions` → `main` после зелёного `make check`.
- После ревью — `/opsx:archive`: сверить `openspec/specs/activity-sessions` и `openspec/specs/progress-tracking` со SPEC.md и asyncapi.yaml.
