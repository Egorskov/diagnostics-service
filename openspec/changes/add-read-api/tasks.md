# Tasks

Предусловие: шаг 6 заархивирован (`add-signals`), работа идёт в ветке `feat/read-api` от `main`. Каждая задача: код → тесты → зелёный `make check` → коммит (Conventional Commits).

## 1. Основа

- [x] 1.1 Зависимость `symfony/security-bundle` 7.4: firewall, authenticator и voter для Read-API (design, решение 2). Проверка: `composer.json`, `composer.lock` и `symfony.lock` обновлены, рецепт создал `config/packages/security.yaml`, `make check` зелёный; коммит `build: add symfony/security-bundle for Read-API auth`
- [x] 1.2 ADR `docs/adr/0006-api-auth-stub.md`: Bearer JWT, claims `sub`, `role`, `exp`, подпись на MVP не проверяется, план замены на публичный ключ Auth (design, решения 2, 10). Проверка: ADR ссылается на SPEC §2, §8; коммит `docs: ADR 0006 Read-API auth stub`

## 2. Domain

- [x] 2.1 `Regularity` в `src/Domain/Metrics/`: активные дни недели, серия и активные минуты недели по интервалам сессий (требования «Текущая неделя», «Активный день», «Серия активных дней»; design, решение 5). Проверка: табличный юнит-тест на сценарии спеки — серия 4, «сегодня ещё не занимался», пропуск, сессия через полночь понедельника, воскресенье 23:59 и понедельник 00:00, переход на зимнее время; `make check`; коммит `feat: weekly regularity and streak`
- [x] 2.2 `TimeBalance` в `src/Domain/Metrics/`: доли `theory` / `practice` / `interview` (требование «Баланс времени»; design, решение 6). Проверка: табличный юнит-тест — 450/90 из примера §8, всё в одной категории, ничего не списано; `make check`; коммит `feat: logged time balance`

## 3. Чтение данных

- [ ] 3.1 Порт `ProgressReadModel` с DTO `CurrentPath`, реализация `DbalProgressReadModel` (design, решение 4). Проверка: интеграционный тест — два пути и выбор текущего, ученики ментора без бывших, `PathPlan` с закрытыми и списанными заданиями, списание без задания по категориям, активные сигналы с прошлой неделей и сброшенным состоянием; `make check`; коммит `feat: progress read model`

## 4. Use cases

- [ ] 4.1 `StudentSummaryQuery` и `StudentSummary` в `src/Application/Api/`: `PathMetrics`, `Regularity`, `TimeBalance` и активные сигналы на `now` в зоне `app.timezone` (требования `student-summary`; design, решение 8). Проверка: юнит-тест на in-memory read-модели — пример SPEC §8 целиком, нет пути → `null`, предмет недельного сигнала из `weeklyGoalCheck()`; `make check`; коммит `feat: student summary query`
- [ ] 4.2 `MentorStudentsQuery` и `MentorStudent`: ученики текущих путей ментора, последняя активность и сортировка по риску (требования `mentor-students`; design, решение 7). Проверка: юнит-тест — порядок 0,6 / 1,1 / без `spi`, равный `spi` с `delay_days` 23 и 5, ученик без сессий, пустой список; `make check`; коммит `feat: mentor students query`

## 5. HTTP

- [ ] 5.1 `StubJwtAuthenticator`, `ApiUser`, firewall `api` и JSON-ответы 401/403 (требования «Токен в каждом запросе», «Подпись на MVP не проверяется»; design, решение 2). Проверка: юнит-тест authenticator — нет заголовка, не три части, payload не JSON, нет `sub`, роль `admin`, истёкший и отсутствующий `exp`, произвольная подпись; `make check`; коммит `feat: stub JWT authenticator for Read-API`
- [ ] 5.2 `StudentVoter` (требования «Ученик видит только себя», «Ментор видит своих учеников»; design, решение 3). Проверка: юнит-тест voter — свой и чужой ученик, ментор текущего и прошлого пути, ученик без пути; `make check`; коммит `feat: student access voter`
- [ ] 5.3 Контроллер `GET /api/v1/students/{id}/summary` и `SummaryJson` (design, решение 9). Проверка: функциональный тест через события и `kernel->handle()` — тело совпадает с формой примера §8, включая `0.0` в балансе; 401, 403 и 404 по сценариям спек; `make check`; коммит `feat: GET /api/v1/students/{id}/summary`
- [ ] 5.4 Контроллер `GET /api/v1/mentor/students`. Проверка: функциональный тест — порядок по риску, бывший ученик не виден, ученик получает 403, пустой список; `make check`; коммит `feat: GET /api/v1/mentor/students`

## 6. Проверка целиком

- [ ] 6.1 Ручная проверка через nginx (`localhost:8080`). События публикуются через management API RabbitMQ и обрабатываются воркером. Затем `curl` с токенами ученика, своего ментора и чужого ментора: ответы 200, 200 и 403, тело сводки сверено с примером §8 по форме. Тестовые строки удаляются. Проверка: команды и результат — в описании PR
- [ ] 6.2 `openspec validate add-read-api --strict` проходит, итоговые требования сверены с SPEC §8. Проверка: вывод команды; коммит `docs: mark manual Read-API check as done`
