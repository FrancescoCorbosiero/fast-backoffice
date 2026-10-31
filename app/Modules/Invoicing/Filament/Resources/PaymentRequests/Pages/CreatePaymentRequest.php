<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Filament\Resources\PaymentRequests\Pages;

use App\Modules\Invoicing\Actions\CreatePaymentRequest as CreatePaymentRequestAction;
use App\Modules\Invoicing\Data\DraftInput;
use App\Modules\Invoicing\Filament\Components\LinesSchema;
use App\Modules\Invoicing\Filament\Resources\PaymentRequests\PaymentRequestResource;
use App\Modules\Invoicing\Models\PaymentRequest;
use Carbon\CarbonImmutable;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreatePaymentRequest extends CreateRecord
{
    protected static string $resource = PaymentRequestResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $issuedOn = is_string($data['issued_on'] ?? null) ? CarbonImmutable::parse($data['issued_on']) : null;
        $request = app(CreatePaymentRequestAction::class)->create(DraftInput::fromArray(LinesSchema::toInput($data)), $issuedOn);

        return PaymentRequest::query()->findOrFail($request->id);
    }

    protected function getRedirectUrl(): string
    {
        return PaymentRequestResource::getUrl('view', ['record' => $this->getRecord()]);
    }
}
