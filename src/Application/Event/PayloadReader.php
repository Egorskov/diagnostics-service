<?php

declare(strict_types=1);

namespace App\Application\Event;

/**
 * Типизированное чтение JSON-объекта события с проверкой контракта.
 *
 * Ошибка содержит путь к полю (`tasks[2].estimate_minutes`), чтобы в failure transport
 * было видно, что именно нарушено. `null` у необязательного поля равен его отсутствию.
 */
final readonly class PayloadReader
{
    private const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    /** ISO 8601 в профиле RFC 3339: с секундами, дробная часть и смещение (или Z) опциональны */
    private const DATETIME_FORMATS = ['Y-m-d\TH:i:sP', 'Y-m-d\TH:i:s.uP'];

    /**
     * @param array<mixed> $data
     */
    public function __construct(
        private array $data,
        private string $path = '',
    ) {
    }

    public function string(string $key): string
    {
        return $this->optionalString($key) ?? throw $this->missing($key);
    }

    public function optionalString(string $key): ?string
    {
        $value = $this->value($key);
        if (null === $value) {
            return null;
        }
        // пустая строка допустима: в asyncapi.yaml нет minLength; UUID, enum и даты проверяются отдельно
        if (!\is_string($value)) {
            throw $this->invalid($key, 'must be a string');
        }

        return $value;
    }

    public function int(string $key, ?int $min = null): int
    {
        return $this->optionalInt($key, $min) ?? throw $this->missing($key);
    }

    public function optionalInt(string $key, ?int $min = null): ?int
    {
        $value = $this->value($key);
        if (null === $value) {
            return null;
        }
        if (!\is_int($value)) {
            throw $this->invalid($key, 'must be an integer');
        }
        if (null !== $min && $value < $min) {
            throw $this->invalid($key, \sprintf('must be >= %d', $min));
        }

        return $value;
    }

    public function bool(string $key, bool $default): bool
    {
        $value = $this->value($key);
        if (null === $value) {
            return $default;
        }
        if (!\is_bool($value)) {
            throw $this->invalid($key, 'must be a boolean');
        }

        return $value;
    }

    public function uuid(string $key): string
    {
        return $this->optionalUuid($key) ?? throw $this->missing($key);
    }

    public function optionalUuid(string $key): ?string
    {
        $value = $this->optionalString($key);
        if (null === $value) {
            return null;
        }
        if (1 !== preg_match(self::UUID_PATTERN, $value)) {
            throw $this->invalid($key, 'must be a UUID');
        }

        return strtolower($value);
    }

    /**
     * Дата без времени (`format: date`) — полночь UTC.
     */
    public function date(string $key): \DateTimeImmutable
    {
        $value = $this->string($key);
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('UTC'));
        if (false === $date || $this->hasParseWarnings() || $date->format('Y-m-d') !== $value) {
            throw $this->invalid($key, 'must be a date in Y-m-d format');
        }

        return $date;
    }

    /**
     * Момент времени (`format: date-time`), приведённый к UTC.
     */
    public function dateTime(string $key): \DateTimeImmutable
    {
        $value = $this->string($key);
        foreach (self::DATETIME_FORMATS as $format) {
            $dateTime = \DateTimeImmutable::createFromFormat($format, $value);
            if (false !== $dateTime && !$this->hasParseWarnings()) {
                return $dateTime->setTimezone(new \DateTimeZone('UTC'));
            }
        }

        throw $this->invalid($key, 'must be an ISO 8601 date-time with timezone');
    }

    /**
     * @template T of \BackedEnum
     *
     * @param class-string<T> $enum
     *
     * @return T
     */
    public function enum(string $key, string $enum): \BackedEnum
    {
        return $this->optionalEnum($key, $enum) ?? throw $this->missing($key);
    }

    /**
     * @template T of \BackedEnum
     *
     * @param class-string<T> $enum
     *
     * @return T|null
     */
    public function optionalEnum(string $key, string $enum): ?\BackedEnum
    {
        $value = $this->value($key);
        if (null === $value) {
            return null;
        }
        $case = \is_string($value) || \is_int($value) ? $enum::tryFrom($value) : null;
        if (null === $case) {
            $allowed = array_map(static fn (\BackedEnum $c): string => (string) $c->value, $enum::cases());

            throw $this->invalid($key, 'must be one of: '.implode(', ', $allowed));
        }

        return $case;
    }

    public function object(string $key): self
    {
        $value = $this->value($key);
        if (null === $value) {
            throw $this->missing($key);
        }
        if (!\is_array($value) || (array_is_list($value) && [] !== $value)) {
            throw $this->invalid($key, 'must be an object');
        }

        return new self($value, $this->pathTo($key));
    }

    /**
     * Список объектов; каждый элемент читается своим reader с путём `key[i]`.
     *
     * @return list<self>
     */
    public function list(string $key): array
    {
        $value = $this->value($key);
        if (null === $value) {
            throw $this->missing($key);
        }
        if (!\is_array($value) || !array_is_list($value)) {
            throw $this->invalid($key, 'must be an array');
        }

        $items = [];
        foreach ($value as $index => $item) {
            $itemPath = \sprintf('%s[%d]', $this->pathTo($key), $index);
            if (!\is_array($item) || (array_is_list($item) && [] !== $item)) {
                throw new InvalidPayload($itemPath, 'must be an object');
            }
            $items[] = new self($item, $itemPath);
        }

        return $items;
    }

    private function value(string $key): mixed
    {
        return $this->data[$key] ?? null;
    }

    private function pathTo(string $key): string
    {
        return '' === $this->path ? $key : $this->path.'.'.$key;
    }

    private function missing(string $key): InvalidPayload
    {
        return new InvalidPayload($this->pathTo($key), 'is required');
    }

    private function invalid(string $key, string $reason): InvalidPayload
    {
        return new InvalidPayload($this->pathTo($key), $reason);
    }

    /**
     * createFromFormat «чинит» переполнение (2026-02-30 → 2026-03-02) и сообщает об этом
     * только предупреждением — такие значения считаем невалидными.
     */
    private function hasParseWarnings(): bool
    {
        $errors = \DateTimeImmutable::getLastErrors();

        return false !== $errors && ($errors['warning_count'] > 0 || $errors['error_count'] > 0);
    }
}
