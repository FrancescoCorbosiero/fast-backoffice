<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Data;

use App\Support\Shape;
use Carbon\CarbonImmutable;

/**
 * One turn of a request's exchange after the first run: the agent asking
 * (its answer, and the questions it listed) or the client answering.
 */
final readonly class ConversationTurn
{
    public const string AGENT = 'agent';

    public const string CLIENT = 'client';

    /**
     * @param  self::AGENT|self::CLIENT  $by
     * @param  list<ClarifyingQuestion>  $questions
     */
    public function __construct(
        public string $by,
        public string $text,
        public CarbonImmutable $at,
        public array $questions = [],
    ) {}

    public function isAgent(): bool
    {
        return $this->by === self::AGENT;
    }

    /**
     * @return array{by: string, text: string, at: string, questions: list<array{question: string, options: list<string>}>}
     */
    public function toArray(): array
    {
        return [
            'by' => $this->by,
            'text' => $this->text,
            'at' => $this->at->toIso8601String(),
            'questions' => array_map(fn (ClarifyingQuestion $question): array => $question->toArray(), $this->questions),
        ];
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            by: Shape::string($data, 'by') === self::AGENT ? self::AGENT : self::CLIENT,
            text: Shape::string($data, 'text'),
            at: CarbonImmutable::parse(Shape::string($data, 'at', '1970-01-01T00:00:00+00:00')),
            questions: array_map(fn (array $question): ClarifyingQuestion => ClarifyingQuestion::fromArray($question), Shape::listOfArrays($data, 'questions')),
        );
    }
}
