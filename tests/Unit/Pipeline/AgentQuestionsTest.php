<?php

declare(strict_types=1);

use App\Modules\Pipeline\Support\AgentQuestions;

it('splits the answer into the text and the questions of its last block', function (): void {
    $parsed = AgentQuestions::parse("Ciao!\n<domande>[{\"domanda\": \"Dove?\", \"opzioni\": [\"Bacheca\", \"Ventaglio\", \"Bacheca\"]}]</domande>\nGrazie.");

    expect($parsed['text'])->toBe("Ciao!\n\nGrazie.")
        ->and($parsed['questions'])->toHaveCount(1)
        ->and($parsed['questions'][0]->question)->toBe('Dove?')
        ->and($parsed['questions'][0]->options)->toBe(['Bacheca', 'Ventaglio']);
});

it('keeps only the text when the block is missing, unclosed or not the expected JSON', function (string $answer): void {
    expect(AgentQuestions::parse($answer)['questions'])->toBe([]);
})->with([
    'no block' => ['Mi serve sapere dove.'],
    'unclosed' => ['Dove? <domande>[{"domanda": "Dove?"}]'],
    'not json' => ['<domande>dove le metto?</domande>'],
    'wrong shape' => ['<domande>{"domanda": "Dove?"}</domande>'],
    'no question' => ['<domande>[{"opzioni": ["A"]}, {"domanda": "   "}]</domande>'],
]);

it('caps the number of questions, options and their length, and squeezes whitespace', function (): void {
    $question = ['domanda' => "Una\n  domanda ".str_repeat('x', 300), 'opzioni' => ['1', '2', '3', '4', '5', '6', 7, ['8']]];
    $parsed = AgentQuestions::parse('<domande>'.json_encode(array_fill(0, 6, $question)).'</domande>');

    expect($parsed['questions'])->toHaveCount(AgentQuestions::MAX_QUESTIONS)
        ->and($parsed['questions'][0]->options)->toBe(['1', '2', '3', '4', '5'])
        ->and(mb_strlen($parsed['questions'][0]->question))->toBe(AgentQuestions::MAX_LENGTH)
        ->and($parsed['questions'][0]->question)->toStartWith('Una domanda x');
});
