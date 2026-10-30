# 0001. Маппинг Doctrine в XML вне слоя Domain

Статус: принято
Дата: 2026-10-29

## Контекст

CLAUDE.md требует, чтобы `src/Domain` был чистым PHP без Symfony и Doctrine:
там живут сущности, value objects и формулы метрик (SPEC §6), которые
покрываются быстрыми юнит-тестами. SPEC не говорит, как при этом
персистить доменные объекты.

Варианты:
1. **Атрибуты `#[ORM\...]` в доменных классах.** Самый распространённый путь
   в Symfony, но Domain начинает зависеть от `doctrine/orm`.
2. **Отдельные Doctrine-сущности в Infrastructure + маппинг в доменные объекты
   руками.** Полная изоляция, но двойной набор классов и ручной маппинг для
   каждой таблицы из §5 — дорого для MVP.
3. **XML-маппинг в Infrastructure для доменных классов.** Domain остаётся без
   импортов Doctrine, а Doctrine гидрирует его классы напрямую.

## Решение

Вариант 3. Файлы маппинга лежат в
`src/Infrastructure/Persistence/Doctrine/Mapping/`, префикс — `App\Domain`
(`config/packages/doctrine.yaml`, `validate_xml_mapping: true`).
Имя файла повторяет путь класса относительно префикса через точку:
`App\Domain\Progress\TaskProgress` → `Progress.TaskProgress.orm.xml`.

Миграции тоже относятся к инфраструктуре:
`src/Infrastructure/Persistence/Doctrine/Migrations/`, namespace
`App\Infrastructure\Persistence\Doctrine\Migrations`.

## Последствия

- Domain тестируется без ядра и базы; PHPStan видит в нём только чистый PHP.
- Маппинг не виден рядом с полем класса; ошибки в XML ловятся схемой при
  загрузке метаданных и `doctrine:schema:validate`.
- Доменные классы всё равно должны быть совместимы с Doctrine: не `final`
  (нужны lazy-прокси), коллекции через `Doctrine\Common\Collections` или
  без коллекций-связей. Если это начнёт сильно мешать модели, пересматриваем
  в пользу варианта 2 для конкретных агрегатов.
