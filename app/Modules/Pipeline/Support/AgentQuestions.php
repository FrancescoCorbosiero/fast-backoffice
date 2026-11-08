<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Support;

use App\Modules\Pipeline\Data\ClarifyingQuestion;

/**
 * Splits the agent's final answer into the text the client reads and the
 * questions it listed in a <domande> block (PromptBuilder asks for one when
 * it needs to know something). The block is the agent's output, so it is
 * read defensively: anything malformed is dropped and the text alone
 * remains, which the client can still answer in their own words.
 */
final class AgentQuestions
{
    public const string OPEN = '<domande>';

    public const string CLOSE = '</domande>';

    public const int MAX_QUESTIONS = 4;

    public const int MAX_OPTIONS = 5;

    public const int MAX_LENGTH = 200;

    /**
     * @return array{text: string, questions: list<ClarifyingQuestion>}
     */
    public static function parse(string $answer): array
    {
        $start = mb_strrpos($answer, self::OPEN);
        $end = $start === false ? false : mb_strpos($answer, self::CLOSE, $start);
        if ($start === false || $end === false) {
            return ['text' => trim($answer), 'questions' => []];
        }

        $json = mb_substr($answer, $start + mb_strlen(self::OPEN), $end - $start - mb_strlen(self::OPEN));
        $text = trim(mb_substr($answer, 0, $start).mb_substr($answer, $end + mb_strlen(self::CLOSE)));
        $decoded = json_decode(trim($json), true);

        $questions = [];
        foreach (is_array($decoded) ? $decoded : [] as $item) {
            if (! is_array($item) || ! is_string($item['domanda'] ?? null)) {
                continue;
            }
            $question = self::clean($item['domanda']);
            if ($question === '') {
                continue;
            }
            $options = [];
            foreach (is_array($item['opzioni'] ?? null) ? $item['opzioni'] : [] as $option) {
                $option = is_string($option) ? self::clean($option) : '';
                if ($option !== '' && ! in_array($option, $options, true)) {
                    $options[] = $option;
                }
            }
            $questions[] = new ClarifyingQuestion($question, array_slice($options, 0, self::MAX_OPTIONS));
            if (count($questions) === self::MAX_QUESTIONS) {
                break;
            }
        }

        return ['text' => $text, 'questions' => $questions];
    }

    private static function clean(string $text): string
    {
        return mb_substr(trim((string) preg_replace('/\s+/u', ' ', $text)), 0, self::MAX_LENGTH);
    }
}
