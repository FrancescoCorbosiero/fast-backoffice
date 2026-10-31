<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Actions;

use App\Kernel\Numbering\NumberFormat;
use App\Kernel\Numbering\NumberSequence;
use App\Modules\Files\Actions\StoreFile;
use App\Modules\Invoicing\Data\DraftInput;
use App\Modules\Invoicing\Data\PartySnapshot;
use App\Modules\Invoicing\Data\PaymentRequestData;
use App\Modules\Invoicing\DocumentContext;
use App\Modules\Invoicing\Enums\PaymentRequestStatus;
use App\Modules\Invoicing\Integrations\Pdf\CourtesyPdfRenderer;
use App\Modules\Invoicing\Models\PaymentRequest;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Date;
use Illuminate\Validation\ValidationException;

/**
 * A pro forma: asks for payment before the invoice (design.md §5.3). Its
 * own non-fiscal numbering, its own PDF, nothing to SdI.
 */
final readonly class CreatePaymentRequest
{
    public const string SERIES = 'payment-requests';

    public const string FORMAT = 'RP {number}/{year}';

    public function __construct(
        private ConnectionInterface $db,
        private DocumentContext $context,
        private NumberSequence $sequence,
        private CourtesyPdfRenderer $pdf,
        private StoreFile $files,
        private CreateDraft $drafts,
    ) {}

    /**
     * @throws ValidationException
     */
    public function create(DraftInput $input, ?CarbonImmutable $issuedOn = null): PaymentRequestData
    {
        CreateDraft::validateLines($input->lines);
        $issuedOn = ($issuedOn ?? Date::today())->startOfDay();
        $customer = $this->context->customer($input->contactId);
        $currency = $this->drafts->currency();
        $computed = $this->context->compute($input->lines, $currency, chargeStampDuty: false);
        $defaults = $this->context->defaults();
        $terms = $input->paymentTermsDays ?? $defaults->paymentTermsDays;

        return $this->db->transaction(function () use ($input, $issuedOn, $customer, $currency, $computed, $terms): PaymentRequestData {
            $year = $issuedOn->year;
            $number = $this->sequence->next(self::SERIES, $year);
            $numberText = (new NumberFormat(self::FORMAT))->render($number, $year);
            $dueOn = $issuedOn->addDays($terms);

            $request = PaymentRequest::query()->create([
                'year' => $year,
                'number' => $number,
                'number_text' => $numberText,
                'status' => PaymentRequestStatus::Open,
                'issued_on' => $issuedOn->toDateString(),
                'due_on' => $dueOn->toDateString(),
                'contact_id' => $customer->id,
                'customer_name' => $customer->displayName,
                'currency' => $currency,
                'lines' => array_map(fn ($line): array => $line->toArray(), $computed['lines']),
                'totals' => $computed['totals']->toArray(),
                'total_cents' => $computed['totals']->total->cents,
                'notes' => $input->notes,
            ]);

            $pdf = $this->pdf->render(
                title: 'Richiesta di pagamento '.$numberText,
                seller: PartySnapshot::ofSeller($this->context->profile()),
                customer: PartySnapshot::ofCustomer($customer),
                date: $issuedOn,
                dueOn: $dueOn,
                lines: $computed['lines'],
                totals: $computed['totals'],
                legalWording: null,
                notes: $input->notes,
                isFiscal: false,
            );

            $file = $this->files->store($request->toData()->reference(), 'pdf', 'richiesta-di-pagamento-'.$number.'-'.$year.'.pdf', $pdf, 'application/pdf');
            $request->forceFill(['pdf_file_id' => $file->id])->save();

            return $request->toData();
        });
    }
}
