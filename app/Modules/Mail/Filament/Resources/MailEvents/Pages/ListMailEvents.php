<?php

declare(strict_types=1);

namespace App\Modules\Mail\Filament\Resources\MailEvents\Pages;

use App\Modules\Mail\Filament\Resources\MailEvents\MailEventResource;
use App\Modules\Mail\Models\MailEvent;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\Support\Htmlable;

class ListMailEvents extends ListRecords
{
    protected static string $resource = MailEventResource::class;

    public function getSubheading(): string|Htmlable|null
    {
        if (! MailEvent::query()->exists()) {
            return 'Nessuna notifica SES ricevuta finora: controlla la sottoscrizione SNS se le campagne sono già partite.';
        }

        return 'Le notifiche che SES manda all\'app (consegne, bounce, segnalazioni di spam) e cosa ne è stato fatto.';
    }
}
