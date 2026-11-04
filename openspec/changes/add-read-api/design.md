# Design

## Context

Мотивация описана в proposal.md, требования — в `specs/`.

Что уже есть после шагов 2–6:
- `PathMetrics::calculate(PathPlan, now, zone)` считает EV/PV/AC, SPI/CPI и даты. Его пока никто не вызывает (шаг 5).
- Текущий путь «самый поздний `started_at`, затем наибольший `path_id`» уже выбирается SQL-запросом с `DISTINCT ON` в `DbalSignalReadModel`. Последняя активность — `max(last_seen_at)` сессий, при их отсутствии — `started_at` пути (шаг 6).
- `signal_state` хранит ключ `тип|аудитория|ученик|предмет` и флаг `active`. Состояние `weekly_goal.behind` заводится на неделю, а предмет — дата понедельника из `weekly_goal_check`. Записи прошлых недель остаются `active`.
- В `src/UI/Http/` пусто. `symfony/security-bundle` и `symfony/browser-kit` не установлены. Маршруты из атрибутов уже подключены в `config/routes.yaml`.
- Зона Europe/Belgrade в коде есть только как `DiagnosticsSchedule::ZONE` в Infrastructure.

## Goals / Non-Goals

**Goals:**
- Новые расчёты (неделя, серия, баланс) — в Domain, с табличными тестами. Контроллеры только переводят результат в JSON.
- Чтобы заменить заглушку JWT на проверку подписи, достаточно поменять один класс authenticator.

**Non-Goals:**
- Кеш сводок, пагинация, батчинг запросов для списка ментора.
- Проверка подписи JWT и роли из `user.registered`.

## Decisions

### 1. Слои и классы

| Часть | Слой | Где |
|---|---|---|
| `Regularity` — активные дни недели, серия, активные минуты недели | Domain | `src/Domain/Metrics/` |
| `TimeBalance` — доли списанного по категориям | Domain | `src/Domain/Metrics/` |
| `StudentSummaryQuery`, `MentorStudentsQuery`, результаты `StudentSummary` и `MentorStudent` | Application | `src/Application/Api/` |
| Порт `ProgressReadModel` и его DTO `CurrentPath`, `ActivityInterval` | Application | `src/Application/Port/` |
| `DbalProgressReadModel` | Infrastructure | `src/Infrastructure/Persistence/Doctrine/` |
| Контроллеры и перевод в JSON (`SummaryJson`) | UI | `src/UI/Http/Controller/` |
| `StubJwtAuthenticator`, `ApiUser`, `StudentVoter`, ответы 401 и 403 | UI | `src/UI/Http/Security/` |

Authenticator и voter лежат в UI: это вопросы HTTP-доступа, а правило «ментор текущего пути» voter берёт из того же порта `ProgressReadModel`.

### 2. Аутентификация — `symfony/security-bundle` и свой authenticator

Firewall `api` на `^/api`, `stateless: true`, а `access_control` требует `IS_AUTHENTICATED` для `^/api`. `StubJwtAuthenticator` работает так:
- принимает заголовок `Authorization: Bearer x.y.z`;
- декодирует base64url-payload и проверяет `sub`, `role` ∈ {student, mentor} и `exp` по `ClockInterface`;
- возвращает `SelfValidatingPassport` с пользователем `ApiUser(id, role)` и ролями `ROLE_STUDENT` или `ROLE_MENTOR`.

Пользователь не загружается из базы: справочника ролей на MVP нет. Ответ 401 `{"error":"unauthorized"}` отдают entry point и `onAuthenticationFailure`, ответ 403 `{"error":"forbidden"}` — access denied handler.

Почему так:
- Альтернатива — свой `kernel.request` listener. Он меньше по коду, но в нём пришлось бы вручную воспроизводить firewall, entry point и voter.
- `lexik/jwt-authentication-bundle` требует ключей и проверки подписи, а это бэклог.
- С security-bundle настоящая проверка позже ляжет в тот же authenticator или в `access_token` handler, а контроллеры и voter не изменятся.

Сквозное решение (формат токена, claims, заглушка и план замены) — в ADR 0006.

### 3. Права — voter `StudentVoter`

Атрибут `VIEW_STUDENT`, предмет — `student_id`:
- ученик проходит, если `id === sub`;
- ментор проходит, если `currentPath(student)?->mentorId === sub`.

Порядок ответов: 401 → 403 → 404. Ученик без пути на свою сводку получает 404, а ментор — 403, потому что у такого ученика нет ментора. Так ментор не узнаёт, существует ли чужой ученик. `student_id` не проверяется на формат UUID: строка, которая не совпала ни с `sub`, ни с учеником, даёт 403. `/mentor/students` закрыт через `#[IsGranted('ROLE_MENTOR')]`.

### 4. Read-модель — один порт на DBAL

`ProgressReadModel`:
- `currentPath(studentId): ?CurrentPath` — `pathId`, `mentorId`, `startedAt`, `weeklyGoalMinutes`;
- `currentPathsOfMentor(mentorId): list<CurrentPath>` — тот же `DISTINCT ON`, затем фильтр по `mentor_id`;
- `pathPlan(studentId, pathId): PathPlan` — `path_task` с `LEFT JOIN task_progress` (`completed_at` у закрытых) и суммой `time_log` по заданию;
- `sessions(studentId): list<ActivityInterval>` — `started_at`, `last_seen_at` всех сессий ученика;
- `loggedByCategory(studentId, fromDate, toDate): array<string,int>` — списания за даты включительно, с заданием и без;
- `activeSignalTypes(studentId, weekSubject): list<SignalType>` — `DISTINCT signal_type` активных записей. Для `weekly_goal.behind` берётся только `subject_id = weekSubject`.

Запрос текущего пути повторяет SQL из `DbalSignalReadModel`. Выносить общий фрагмент ради двух мест не стану: разойдутся — поймают тесты обеих read-моделей.

### 5. Неделя, активный день и серия — в `Regularity`

`Regularity::calculate(list<ActivityInterval>, now, zone)` возвращает `activeDaysInWeek`, `streakDays` и `weekActiveMinutes`:
- Неделя — с понедельника 00:00 по воскресенье в зоне, как в `WeeklyMoment::weekStart`.
- Активные дни — все календарные даты в зоне от `started_at` до `last_seen_at` каждой сессии. Сессия через полночь делает активными оба дня.
- Активные минуты недели — сумма `last_seen_at − started_at + 1 мин` по сессиям, у которых `started_at` попадает в неделю. Это та же формула, что `ActivitySession::minutes()`.
- Серия — подряд идущие даты, заканчивающиеся сегодня, а если сегодня не активно — вчера.

Сессии загружаются все: их немного, потому что heartbeat продлевает сессию, а не создаёт новую. Ограничение глубины — в рисках.

### 6. Баланс — за ту же неделю

SPEC §6.2 не говорит, за какой период считать баланс. Пример §8 сходится с недельным списанием: 450 + 90 = 540 = `logged_minutes`, а 450/540 и 90/540 дают 0,83 и 0,17. Поэтому `TimeBalance::of(minutesByCategory)` получает списания недели и возвращает неокруглённые доли. Если ничего не списано, все три доли равны 0.

### 7. Риск — `spi` по возрастанию, затем отставание

SPEC §8 говорит «сортировка по риску», но риск не определяет. Предлагаю:
1. `spi` по возрастанию — это та же мера, что у `student.at_risk` (SPI < 0,8, бэклог);
2. затем `delay_days` по убыванию;
3. затем `student_id`.

`null` в обоих полях уходит в конец: темп и прогноз ещё не посчитаны, значит, признаков отставания нет. Сравнение — по неокруглённым значениям, сортирует `MentorStudentsQuery`.

Альтернатива — считать риск по числу активных сигналов. Она грубее: застревание на одном задании весит столько же, сколько отставание по всему пути.

### 8. Зона и предмет недельного сигнала

Даты и неделя сводки считаются в Europe/Belgrade (SPEC §9). Зона передаётся в Application параметром контейнера `app.timezone`, а не константой Infrastructure.

Предмет `weekly_goal.behind` для фильтра активных сигналов берётся как `Thresholds::weeklyGoalCheck()->weekStart(now)` — ровно так его пишет проверка, поэтому ключи совпадут даже при другой зоне в пороге.

### 9. JSON и тесты HTTP

Контроллеры собирают массив и отдают `JsonResponse` с флагом `JSON_PRESERVE_ZERO_FRACTION`, чтобы доли выводились как `0.0`, как в примере §8. Округление до сотых — `round(x, 2)` при выводе, даты — `format('Y-m-d')`, `last_activity_at` — `Y-m-d\TH:i:s\Z` в UTC. Сериализатор Symfony не нужен.

Функциональные тесты наследуют `EventFlowTestCase`:
- данные заводятся настоящими событиями через шину;
- запрос идёт через `self::$kernel->handle(Request::create(...))`, без `symfony/browser-kit`;
- время подменяется через `ClockSensitiveTrait::mockTime`, которое видит и authenticator.

### 10. ADR 0006

В ADR 0006 вынесено то, что переживёт этот change: формат токена (`sub`, `role`, `exp`), то, что подпись не проверяется, и план замены. Его прочтут все следующие эндпоинты §8 и тот, кто будет подключать настоящий Auth.

## Risks / Trade-offs

- **[Заглушка пропускает поддельный токен]** → Любой, кто может достучаться до API, прочтёт данные любого ученика. Допустимо только для MVP, где сервис не выставлен наружу. Это явно записано в ADR 0006, в спеке `api-auth` и в `security.yaml`.
- **[N+1 в `mentor/students`]** → На каждого ученика — 4 запроса. Для группы из десятков учеников это приемлемо, батчинг — при появлении нагрузки.
- **[Все сессии ученика на каждый запрос]** → При годах активности это тысячи строк. Если станет заметно, ограничу выборку `last_seen_at ≥ начало текущей недели − длина возможной серии` или перенесу дни в SQL.
- **[Определения недели, серии, баланса и риска не из SPEC]** → Записаны в спеках `student-summary` и `mentor-students`. В SPEC §6.2 и §8 их нет, поэтому `SPEC.md` не меняю; при ревью стоит подтвердить их с Валентином.
- **[Роль из токена, а не из `user_role`]** → Если Auth выдаст другие claims, правка будет только в authenticator.
