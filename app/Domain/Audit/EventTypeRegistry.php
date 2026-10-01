<?php

declare(strict_types=1);

namespace App\Domain\Audit;

use App\Domain\Audit\Exceptions\UnknownEventType;

final class EventTypeRegistry
{
    /** @var array<string, EventType> */
    private array $types = [];

    public function register(EventType ...$types): void
    {
        foreach ($types as $type) {
            if (isset($this->types[$type->code])) {
                throw UnknownEventType::duplicate($type->code);
            }
            $this->types[$type->code] = $type;
        }
    }

    public function get(string $code): EventType
    {
        return $this->types[$code] ?? throw UnknownEventType::code($code);
    }

    public function has(string $code): bool
    {
        return isset($this->types[$code]);
    }

    /**
     * @return array<string, EventType>
     */
    public function all(): array
    {
        ksort($this->types);

        return $this->types;
    }
}
