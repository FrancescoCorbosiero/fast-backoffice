<?php

declare(strict_types=1);

namespace App\Modules\Quotes\Actions;

use App\Kernel\Numbering\NumberFormat;
use App\Kernel\Numbering\NumberSequence;
use App\Modules\Invoicing\Data\LineInput;
use App\Modules\Invoicing\Queries\DocumentFigures;
use App\Modules\Quotes\Data\QuoteData;
use App\Modules\Quotes\Data\QuoteInput;
use App\Modules\Quotes\Enums\QuoteStatus;
use App\Modules\Quotes\Models\Quote;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Date;
use Illuminate\Validation\ValidationException;

/**
 * A quote gets its number when it's written: it is not a fiscal document,
 * and a declined or expired one keeps its place in the series.
 */
final readonly class CreateQuote
{
    public const string SERIES = 'quotes';

    public const string FORMAT = 'PRV {number}/{year}';

    public function __construct(
        private ConnectionInterface $db,
        private DocumentFigures $figures,
        private NumberSequence $sequence,
    ) {}

    /**
     * @throws ValidationException
     */
    public function create(QuoteInput $input): QuoteData
    {
        $issuedOn = ($input->issuedOn ?? Date::today())->startOfDay();
        $validUntil = self::validity($issuedOn, $input->validUntil);
        $customer = $this->figures->customer($input->contactId);
        $computed = $this->figures->compute($input->lines);

        return $this->db->transaction(function () use ($input, $issuedOn, $validUntil, $customer, $computed): QuoteData {
            $year = $issuedOn->year;
            $number = $this->sequence->next(self::SERIES, $year);

            $quote = Quote::query()->create([
                'year' => $year,
                'number' => $number,
                'number_text' => (new NumberFormat(self::FORMAT))->render($number, $year),
                'status' => QuoteStatus::Draft,
                'issued_on' => $issuedOn->toDateString(),
                'valid_until' => $validUntil->toDateString(),
                'contact_id' => $input->contactId,
                'customer_name' => $customer->name,
                'subject' => $input->subject,
                'currency' => $this->figures->currency(),
                'lines' => array_map(fn (LineInput $line): array => $line->toArray(), $computed['lines']),
                'totals' => $computed['totals']->toArray(),
                'total_cents' => $computed['totals']->total->cents,
                'notes' => $input->notes,
            ]);

            return $quote->toData();
        });
    }

    /**
     * @throws ValidationException when the validity ends before the quote's date
     */
    public static function validity(CarbonImmutable $issuedOn, ?CarbonImmutable $validUntil): CarbonImmutable
    {
        $validUntil ??= $issuedOn->addDays(QuoteInput::DEFAULT_VALIDITY_DAYS);

        if ($validUntil->lessThan($issuedOn)) {
            throw ValidationException::withMessages(['valid_until' => 'La validità non può finire prima della data del preventivo.']);
        }

        return $validUntil->startOfDay();
    }
}
