# Сервис Диагностики — спецификация (MVP)

Статус: черновик к брифингу 30.10.2026
Владелец: Егор
Контракты событий: [`asyncapi.yaml`](./asyncapi.yaml) — источник правды для интеграций.

---

## 1. Назначение

Сервис собирает события активности учеников из других сервисов, считает метрики прогресса и качества учебных материалов и публикует **доменные сигналы** (`diagnostics.*`), на которые реагирует сервис Уведомлений.

Ответы на вопросы:
- **Ментору:** кто из учеников отстаёт или буксует, на каких заданиях застревают все, какие оценки сложности неверны.
- **Ученику:** где я относительно плана, когда закончу путь при текущем темпе, что мешает.

## 2. Границы

| Владеет | Не владеет |
|---|---|
| Сессии активности (активное время) | Курсы, задания, квизы, песочница — сервис **Курсов** |
| Агрегаты: план/факт, SPI/CPI, прогноз | Путь ученика (назначает ментор в Курсах) |
| Метрики качества материалов | Входная диагностика / тестирование (вне модуля; результат — опциональное событие) |
| Правила сигналов, их состояние и пороги | Каналы, шаблоны, тихие часы, доставка — сервис **Уведомлений** |
| Read-API для дашбордов | Пользователи и аутентификация — сервис **Auth** |

Принцип: Диагностика сообщает **факт** («ученик неактивен 3 дня»), а не «отправь письмо». Кому и как доставлять, решают Уведомления.

## 3. Глоссарий

| Термин | Определение |
|---|---|
| **Оценка задания** (`estimate_minutes`) | Сложность задания в минутах, задаётся ментором |
| **Списанное время** | Время, которое ученик списал вручную (`time.logged`) |
| **Точка активности** | Любое событие: `activity.heartbeat`, `sandbox.run`, `quiz.answered`, `task.started`, `task.completed` |
| **Сессия активности** | Последовательность точек активности с паузами ≤ `idle_timeout` (15 мин) |
| **Активное время** | Сумма длительностей сессий |
| **EV** (освоено) | Σ оценок закрытых заданий пути |
| **PV** (план) | Сколько оценочного времени должно быть закрыто к текущей дате |
| **AC** (факт) | Σ списанного времени по заданиям пути |
| **SPI** | EV / PV — темп относительно плана |
| **CPI** | EV / AC — эффективность относительно оценок ментора |

## 4. Входящие события

Все события в общем конверте (см. `asyncapi.yaml → Envelope`). Обработка идемпотентна по `event_id`.

| Событие | Источник | Зачем |
|---|---|---|
| `user.registered` | Auth | Справочник ролей (student/mentor) |
| `path.assigned` | Курсы | Путь ученика: ментор, недельная цель, задания с оценками и этапами |
| `path.updated` | Курсы | Полный снапшот пути после изменения ментором |
| `task.started` | Курсы | Начало задания; точка активности |
| `task.completed` | Курсы | EV, завершение этапа; точка активности |
| `quiz.answered` | Курсы | Статистика по вопросам и темам; точка активности |
| `sandbox.run` | Курсы / песочница | Неудачные запуски, ошибки; точка активности |
| `activity.heartbeat` | Фронт Курсов | Активное время на теории (раз в 60 с при активной вкладке и действиях пользователя) |
| `time.logged` | Учёт времени* | AC, недельная цель |
| `hint.used` | Курсы / AI-помощник | Уровень подсказки; качество материала |
| `material.rated` | Курсы | Оценка задания учеником 1–5, флаг «непонятно» |
| `assessment.completed` | Входная диагностика | *Опционально.* Базовая линия по темам |

\* Какой сервис владеет списанием времени — открытый вопрос (§11).

## 5. Модель данных (PostgreSQL)

```
inbox_event        (event_id PK, event_type, received_at)                       -- идемпотентность
user_role          (user_id PK, role)
student_path       (student_id, path_id, mentor_id, started_at,
                    weekly_goal_minutes, PK(student_id, path_id))
path_task          (path_id, task_id, stage_id, estimate_minutes, position,
                    PK(path_id, task_id))
task_progress      (student_id, task_id, path_id, status[not_started|in_progress|completed],
                    started_at, completed_at, active_minutes, failed_runs,
                    max_hint_level, PK(student_id, task_id))
activity_session   (id, student_id, task_id NULL, source[theory|sandbox|quiz|other],
                    started_at, last_seen_at)
time_log           (id, student_id, task_id NULL, category[theory|practice|interview],
                    minutes, logged_for_date, source_event_id)
quiz_answer        (student_id, task_id, question_id, topic, attempt, is_correct, answered_at)
sandbox_run        (run_id PK, student_id, task_id, result[passed|failed|error],
                    error_type NULL, occurred_at)
material_rating    (student_id, task_id, rating, unclear, rated_at)
signal_state       (signal_type, student_id NULL, subject_id NULL, audience,
                    active BOOL, first_emitted_at, last_emitted_at)            -- дедупликация
threshold          (key PK, value)                                              -- пороги, §7.1
```

## 6. Расчёт метрик

### 6.1 Активное время (сессионизация)
1. Пришла точка активности ученика `S` в момент `t`.
2. Если у `S` есть сессия с `last_seen_at ≥ t − idle_timeout` (и тем же `task_id`) — `last_seen_at = t`.
3. Иначе открыть новую сессию `started_at = last_seen_at = t`.
4. Длительность сессии = `last_seen_at − started_at + 1 мин`.
5. Активное время задания = Σ сессий с этим `task_id` → `task_progress.active_minutes`.

MVP-допущение: события приходят примерно по порядку; опоздания > `idle_timeout` пишутся отдельной сессией.

### 6.2 Прогресс ученика по пути
- `EV = Σ estimate(completed)`
- `PV = min(Σ estimate(all), weekly_goal × недель_с_начала)` — *MVP-допущение*: план равномерен по недельной цели; без дедлайнов по заданиям.
- `AC = Σ time_log.minutes` по заданиям пути
- `SPI = EV / PV` (не считается, пока PV = 0), `CPI = EV / AC` (пока AC = 0 — не считается)
- **Прогноз завершения** = сегодня + `(Σ estimate − EV) / среднее EV в неделю за 4 недели`
- **Отставание, дней** = прогноз − плановая дата (`started_at + Σ estimate / weekly_goal`)
- **Регулярность:** активные дни за неделю, текущая серия
- **Баланс:** доли списанного времени по категориям theory / practice / interview
- **Расхождение:** `списано / активно` за неделю — только отображается, не порождает сигналов ментору

### 6.3 Качество материалов (по заданию, по всем ученикам)
Показывается только при выборке ≥ `min_sample` (5) завершивших.

| Метрика | Формула | Сигнал проблемы |
|---|---|---|
| Калибровка | медиана `active_minutes` завершивших / `estimate` | > 2.0 или < 0.4 |
| Застревание | начали и не закрыли за `stuck_days` / начали | > 30% |
| Подсказки | доля учеников с `max_hint_level ≥ 2` | > 50% |
| Песочница | медиана неудачных запусков до `passed`; топ `error_type` | — (для ментора) |
| Вопрос квиза | доля верных с первой попытки | > 95% или < 20% |
| Оценка учениками | средняя оценка; доля «непонятно» | < 3.0 или > 30% |

## 7. Сигналы (исходящие события)

### 7.1 Пороги (дефолты, таблица `threshold`)

| Ключ | Значение |
|---|---|
| `idle_timeout_min` | 15 |
| `inactive_student_days` | 3 |
| `inactive_mentor_days` | 7 |
| `weekly_goal_check` | четверг 18:00 Europe/Belgrade |
| `weekly_goal_min_ratio` | 0.5 |
| `stuck_time_ratio` | 1.5 (активное время / оценка) |
| `stuck_failed_runs` | 10 |
| `stuck_escalation_hours` | 24 |
| `at_risk_spi` | 0.8 |
| `at_risk_weeks` | 2 |
| `quiz_topic_fail_attempts` | 2 |
| `log_missing_check` | ежедневно 21:00, порог активности 30 мин |
| `streak_milestones` | 7, 14, 30, 60, 100 |
| `stuck_days` | 7 |
| `min_sample` | 5 |

### 7.2 Правила

| Событие | Аудитория | Условие | Когда проверяется |
|---|---|---|---|
| `diagnostics.student.inactive` | student | нет активности ≥ 3 дня | Scheduler, ежечасно |
| `diagnostics.student.inactive` | mentor | нет активности ≥ 7 дней | Scheduler, ежечасно |
| `diagnostics.weekly_goal.behind` | student | списано < 50% недельной цели | Scheduler, чт 18:00 |
| `diagnostics.task.stuck` | student | активное время > 1.5 × оценки ИЛИ неудачных запусков ≥ 10 | на `sandbox.run`, `activity.heartbeat` |
| `diagnostics.task.stuck` | mentor | прошло 24 ч после сигнала ученику, задание не закрыто | Scheduler, ежечасно |
| `diagnostics.time.log_missing` | student | активно ≥ 30 мин за день, списано 0 | Scheduler, 21:00 |
| `diagnostics.stage.completed` | student, mentor | закрыты все задания этапа | на `task.completed` |
| `diagnostics.streak.milestone` | student | серия достигла отметки | Scheduler, 00:05 |
| `diagnostics.student.at_risk` | mentor | SPI < 0.8 две недели подряд | Scheduler, пн 08:00 |
| `diagnostics.quiz.topic_failed` | mentor | тема провалена 2 раза (≥ 50% неверных в попытке) | на `quiz.answered` |
| `diagnostics.material.needs_review` | mentor | любая метрика §6.3 за порогом | Scheduler, ежедневно |
| `diagnostics.mentor.weekly_digest` | mentor | всегда | Scheduler, пн 09:00 |

### 7.3 Дедупликация
- Сигнал публикуется **на переходе состояния** (условие стало истинным), а не на каждой проверке. Состояние хранится в `signal_state`.
- Сигнал сбрасывается, когда условие перестало выполняться (например, появилась активность). Повторный переход — новый сигнал.
- Cooldown, тихие часы, частота доставки — ответственность Уведомлений.

## 8. Read-API

REST, JSON, `/api/v1`. Аутентификация — JWT от сервиса Auth (проверка подписи публичным ключом). Ученик видит только себя; ментор — своих учеников (`student_path.mentor_id`).

| Метод | Путь | Описание |
|---|---|---|
| GET | `/students/{id}/summary` | EV/PV/AC, SPI/CPI, прогноз, отставание, регулярность, баланс, активные сигналы |
| GET | `/students/{id}/timeline?from&to` | По дням: списано / активно, задания |
| GET | `/students/{id}/tasks` | По заданиям: статус, оценка, активное время, запуски, подсказки |
| GET | `/mentor/students` | Ученики ментора, сортировка по риску |
| GET | `/mentor/materials?path_id` | Метрики качества материалов (§6.3) |
| GET/PUT | `/mentor/thresholds` | *Бэклог.* Настройка порогов |

Пример `GET /students/{id}/summary`:
```json
{
  "student_id": "8f1c…",
  "path_id": "a21e…",
  "ev_minutes": 2700, "pv_minutes": 3600, "ac_minutes": 3300,
  "spi": 0.75, "cpi": 0.82,
  "forecast_finish": "2027-01-20", "planned_finish": "2026-12-28", "delay_days": 23,
  "week": { "goal_minutes": 1500, "logged_minutes": 540, "active_minutes": 610, "active_days": 3 },
  "streak_days": 4,
  "balance": { "theory": 0.0, "practice": 0.83, "interview": 0.17 },
  "active_signals": ["student.at_risk"]
}
```

## 9. Нефункциональные требования
- **Идемпотентность:** `inbox_event` — повторная доставка не меняет состояние.
- **Ретраи:** Messenger retry (3 попытки, экспонента), затем failure transport (DLQ) + алерт в лог.
- **Время:** хранение в UTC; расписания — Europe/Belgrade (MVP); таймзона ученика — бэклог.
- **Нагрузка:** `activity.heartbeat` — самый частый поток; отдельная очередь, допускается батчинг на стороне Курсов.
- **Публикация:** транзакционный outbox (запись события в той же транзакции, что и `signal_state`), затем relay в RabbitMQ.
- **Наблюдаемость:** структурные логи с `event_id`, `correlation_id`.

## 10. Скоуп

### MVP (к созвону / первой итерации)
- Консьюмеры: `path.assigned`, `task.started`, `task.completed`, `time.logged`, `activity.heartbeat`, `sandbox.run`
- Сессионизация, EV/PV/AC, SPI/CPI, прогноз
- Сигналы: `student.inactive`, `task.stuck`, `weekly_goal.behind`
- API: `GET /students/{id}/summary`, `GET /mentor/students`
- Docker Compose: php-fpm, PostgreSQL, RabbitMQ; эмулятор входящих событий (console-команда)

### Бэклог
- Качество материалов (§6.3) и `material.needs_review`
- `quiz.answered`, `hint.used`, `material.rated`, `quiz.topic_failed`
- `time.log_missing`, `streak.milestone`, `stage.completed`, `weekly_digest`
- Настраиваемые пороги ментором, таймзона ученика, дедлайны заданий (PV по дедлайнам)
- `assessment.completed` → динамика по темам; дерево навыков; геймификация

## 11. Открытые вопросы

**К Валентину**
1. Где живёт списание времени и путь ученика — в Курсах или отдельно? Кто публикует `time.logged`?
2. Пороги сигналов фиксированные или ментор настраивает их сам? Для всех учеников или индивидуально?
3. Нужны ли дедлайны по заданиям в MVP или план считаем по недельной цели?
4. Шкала готовности («Пока разогрев») — чья она и переезжает ли в Диагностику?

**К команде Курсов**
5. Готовы публиковать `task.*`, `quiz.answered`, `sandbox.run`, `activity.heartbeat`, `hint.used`, `material.rated` по контракту?
6. Как идентифицируются этапы (`stage_id`) и темы вопросов (`topic`)?

**К команде Уведомлений**
7. Устраивает поле `audience` + `student_id`/`mentor_id` для маршрутизации, или нужен явный `recipient_ids`?
8. Exchange `diagnostics.events` (topic), routing key = `event_type` — ок?

**Общие**
9. Конверт событий (§ `Envelope`) — общий стандарт для всех сервисов?
