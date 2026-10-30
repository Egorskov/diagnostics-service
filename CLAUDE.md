# CLAUDE.md — сервис Диагностики

Микросервис платформы менторинга. Собирает события активности учеников, считает метрики прогресса и качества материалов, публикует сигналы `diagnostics.*` для сервиса Уведомлений.

**Перед любой задачей прочитай:**
- `docs/SPEC.md` — границы, модель данных, формулы, правила сигналов, скоуп MVP
- `docs/asyncapi.yaml` — контракты событий; это источник правды для DTO

Если задача противоречит спеке — остановись и спроси, не меняй спеку молча. Если спека молчит — предложи решение и зафиксируй его (где именно — см. «Правила работы»).

## Стек
- PHP 8.3, Symfony 7 (skeleton + нужные бандлы, без webapp-pack)
- PostgreSQL 16, Doctrine ORM + Migrations
- RabbitMQ: Symfony Messenger, транспорт AMQP (`symfony/amqp-messenger`)
- Symfony Scheduler для периодических проверок сигналов
- Docker Compose: `php` (fpm + cli), `nginx`, `postgres`, `rabbitmq` (с management UI)
- Тесты: PHPUnit; статика: PHPStan (level 8), PHP-CS-Fixer (@Symfony)

## Архитектура
```
src/
  Domain/          # сущности, value objects, расчёт метрик (чистый PHP, без Symfony/Doctrine)
  Application/     # хендлеры событий, use cases, правила сигналов, порты (интерфейсы)
  Infrastructure/  # Doctrine-репозитории, Messenger (сериализатор конверта), outbox, scheduler
  UI/Http/         # контроллеры Read-API (/api/v1)
  UI/Console/      # команды, в т.ч. эмулятор входящих событий
```
- Формулы метрик (§6 спеки) — в `Domain`, покрыты юнит-тестами на табличных примерах.
- Входящее событие → кастомный сериализатор разбирает конверт → DTO по `event_type` → хендлер.
- Идемпотентность: таблица `inbox_event`, проверка `event_id` в той же транзакции, что и изменения.
- Исходящие сигналы — только через outbox (запись в той же транзакции, что `signal_state`), relay публикует в exchange `diagnostics.events` с routing key = `event_type`.
- Сигналы публикуются на переходе состояния (§7.3), не на каждой проверке.
- Пороги — из таблицы `threshold`, не хардкодом.
- `activity.heartbeat` — отдельный транспорт/очередь.
- Время: хранить в UTC (`DateTimeImmutable`), расписания — `Europe/Belgrade`. Время в домене получать через `Psr\Clock\ClockInterface`, чтобы тестировать.

## Правила работы
- Изменения ведём через OpenSpec (CLI `openspec`, контекст и правила — `openspec/config.yaml`). Каждый шаг MVP — отдельный change: `/opsx:propose` → `/opsx:apply` → `/opsx:archive`. Propose только планирует; к коду — после явного «ок» на артефакты.
- Источник правды — `docs/SPEC.md` и `docs/asyncapi.yaml`. `openspec/specs/` — журнал реализованного поведения, который пополняется при archive; при расхождении правы SPEC/asyncapi, а расхождение — повод остановиться и спросить.
- Где фиксировать решения: сквозные, переживающие один change (конверт событий, outbox, выбор БД, маппинг) — `docs/adr/NNNN-название.md`; решения в рамках одного изменения — `design.md` этого change.
- Маленькие шаги: одна задача из `tasks.md` → тесты → зелёный `make check` → коммит. Conventional Commits.
- Не добавлять зависимости без объяснения зачем.
- Не трогать `docs/asyncapi.yaml` без явной просьбы — это контракт с другими командами.
- Миграции только через `doctrine:migrations:diff`, руками не править сгенерированное без причины.
- Объясняй неочевидные решения коротко в ответе — проект учебный.

## Команды (Makefile)
```
make up          # docker compose up -d
make sh          # shell в php-контейнере
make migrate     # doctrine:migrations:migrate
make consume     # messenger:consume courses time heartbeat scheduler_default
make test        # phpunit
make check       # phpstan + cs-fixer --dry-run + phpunit
make emulate     # bin/console app:emulate-events — сгенерировать поток событий для одного ученика
```

## Текущий скоуп — MVP (SPEC §10)
1. Скелет Symfony + Docker Compose + Makefile + CI-проверки локально
2. Конверт событий, сериализатор, inbox (идемпотентность)
3. Консьюмеры: `path.assigned`, `task.started`, `task.completed`, `time.logged`, `activity.heartbeat`, `sandbox.run`
4. Сессионизация → `task_progress.active_minutes`
5. EV/PV/AC, SPI/CPI, прогноз (Domain + тесты)
6. Сигналы: `student.inactive`, `task.stuck`, `weekly_goal.behind` + outbox
7. API: `GET /api/v1/students/{id}/summary`, `GET /api/v1/mentor/students` (JWT от Auth — на MVP допустим заглушечный authenticator)
8. Эмулятор событий

Всё остальное — бэклог, не делать без запроса.
