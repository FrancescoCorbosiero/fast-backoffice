<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Data;

use App\Support\Shape;

/**
 * One thing the agent needs to know before it can make the change, with
 * the answers it suggests (none for an open question). The portal shows
 * the options as choices; what the client picks is only text for the next
 * run, never a permission.
 */
final readonly class ClarifyingQuestion
{
    /**
     * @param  list<string>  $options
     */
    public function __construct(
        public string $question,
        public array $options = [],
    ) {}

    /**
     * @return array{question: string, options: list<string>}
     */
    public function toArray(): array
    {
        return ['question' => $this->question, 'options' => $this->options];
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $options = [];
        foreach (is_array($data['options'] ?? null) ? $data['options'] : [] as $option) {
            if (is_string($option)) {
                $options[] = $option;
            }
        }

        return new self(Shape::string($data, 'question'), $options);
    }
}
