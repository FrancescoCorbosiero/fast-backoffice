<?php

declare(strict_types=1);

namespace App\Kernel;

use InvalidArgumentException;
use Stringable;

/**
 * An opaque reference to a record of some module, "type:key", used where
 * a lower module points at a higher one without knowing its tables: the
 * owner of a stored file, the allocation of a cash movement, the subject
 * of an audit entry. Examples: "invoice:123", "domain_renewal:7:2027".
 */
final readonly class Reference implements Stringable
{
    public function __construct(
        public string $type,
        public string $key,
    ) {
        if (preg_match('/^[a-z][a-z0-9_]*$/', $type) !== 1) {
            throw new InvalidArgumentException("\"{$type}\" is not a reference type (snake_case).");
        }

        if (preg_match('/^[A-Za-z0-9_-]+(?::[A-Za-z0-9_-]+)*$/', $key) !== 1) {
            throw new InvalidArgumentException("\"{$key}\" is not a reference key.");
        }
    }

    public static function to(string $type, int|string $key): self
    {
        return new self($type, (string) $key);
    }

    public static function fromString(string $reference): self
    {
        $separator = strpos($reference, ':');

        if ($separator === false) {
            throw new InvalidArgumentException("\"{$reference}\" is not a reference (type:key).");
        }

        return new self(substr($reference, 0, $separator), substr($reference, $separator + 1));
    }

    public function is(string $type): bool
    {
        return $this->type === $type;
    }

    public function equals(Reference $other): bool
    {
        return $this->type === $other->type && $this->key === $other->key;
    }

    public function toString(): string
    {
        return "{$this->type}:{$this->key}";
    }

    public function __toString(): string
    {
        return $this->toString();
    }
}
