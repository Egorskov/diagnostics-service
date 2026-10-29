DC  = docker compose
PHP = $(DC) exec php

# UID хоста пробрасывается в образ php, чтобы файлы из контейнера были вашими
export UID := $(shell id -u)

.DEFAULT_GOAL := help
.PHONY: help build up down restart logs sh install migrate diff consume emulate \
        test test-db stan cs cs-fix check

help: ## Список команд
	@grep -E '^[a-zA-Z_-]+:.*?## ' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-10s\033[0m %s\n", $$1, $$2}'

## --- Окружение ---
build: ## Собрать образы
	$(DC) build

up: ## Поднять контейнеры
	$(DC) up -d

down: ## Остановить контейнеры
	$(DC) down

restart: down up ## Перезапустить контейнеры

logs: ## Логи всех сервисов
	$(DC) logs -f --tail=100

sh: ## Shell в php-контейнере
	$(PHP) sh

install: ## composer install
	$(PHP) composer install

## --- База и очереди ---
migrate: ## Применить миграции
	$(PHP) bin/console doctrine:migrations:migrate --no-interaction

diff: ## Сгенерировать миграцию по изменениям маппинга
	$(PHP) bin/console doctrine:migrations:diff

consume: ## Запустить воркер Messenger (транспорты настраиваются на шагах 2–3 MVP)
	$(PHP) bin/console messenger:consume async heartbeat scheduler_default -vv

emulate: ## Сгенерировать поток событий для ученика (шаг 8 MVP); аргументы: make emulate ARGS="..."
	$(PHP) bin/console app:emulate-events $(ARGS)

## --- Качество ---
test-db: ## Создать тестовую базу и применить миграции
	$(PHP) bin/console --env=test doctrine:database:create --if-not-exists --quiet
	$(PHP) bin/console --env=test doctrine:migrations:migrate --no-interaction --allow-no-migration --quiet

test: test-db ## PHPUnit
	$(PHP) bin/phpunit

stan: ## PHPStan (level 8)
	$(PHP) bin/console cache:warmup --env=dev --quiet
	$(PHP) vendor/bin/phpstan analyse --memory-limit=1G

cs: ## PHP-CS-Fixer, только проверка
	$(PHP) vendor/bin/php-cs-fixer fix --dry-run --diff

cs-fix: ## PHP-CS-Fixer, исправить
	$(PHP) vendor/bin/php-cs-fixer fix

check: stan cs test ## Всё, что должно быть зелёным перед коммитом
