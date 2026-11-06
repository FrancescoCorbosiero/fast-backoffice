<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Enums;

/**
 * The lifecycle of a request (design.md §5.6):
 *
 *   received → queued → running → preview_ready → approved → deployed
 *                          │            │              └──────→ failed
 *                          └→ failed    └→ rejected
 *
 * Every write of the status goes through canBecome(), so a job that runs
 * late or twice can't move a request backwards.
 */
enum ChangeRequestStatus: string
{
    case Received = 'received';
    case Queued = 'queued';
    case Running = 'running';
    case PreviewReady = 'preview_ready';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Deployed = 'deployed';
    case Failed = 'failed';

    public function canBecome(self $next): bool
    {
        return in_array($next, $this->next(), true);
    }

    /**
     * @return list<self>
     */
    public function next(): array
    {
        return match ($this) {
            self::Received => [self::Queued],
            self::Queued => [self::Running],
            self::Running => [self::PreviewReady, self::Failed],
            self::PreviewReady => [self::Approved, self::Rejected],
            self::Approved => [self::Deployed, self::Failed],
            self::Rejected, self::Deployed, self::Failed => [],
        };
    }

    public function isTerminal(): bool
    {
        return $this->next() === [];
    }

    public function awaitsDecision(): bool
    {
        return $this === self::PreviewReady;
    }

    public function label(): string
    {
        return match ($this) {
            self::Received => 'Ricevuta',
            self::Queued => 'In coda',
            self::Running => 'In lavorazione',
            self::PreviewReady => 'Anteprima pronta',
            self::Approved => 'Approvata',
            self::Rejected => 'Rifiutata',
            self::Deployed => 'Pubblicata',
            self::Failed => 'Fallita',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Received, self::Queued => 'gray',
            self::Running => 'info',
            self::PreviewReady => 'warning',
            self::Approved, self::Deployed => 'success',
            self::Rejected, self::Failed => 'danger',
        };
    }

    /**
     * What the client reads in the portal: what is happening, in "tu".
     */
    public function clientHint(): string
    {
        return match ($this) {
            self::Received, self::Queued => 'Abbiamo ricevuto la richiesta: la prendiamo in carico a breve.',
            self::Running => 'Ci stiamo lavorando: tra poco potrai vedere un\'anteprima.',
            self::PreviewReady => 'L\'anteprima è pronta: guardala e dicci se va bene.',
            self::Approved => 'Hai approvato la modifica: la stiamo pubblicando.',
            self::Rejected => 'La modifica è stata rifiutata e non verrà pubblicata.',
            self::Deployed => 'La modifica è online.',
            self::Failed => 'Non siamo riusciti a completare la richiesta: lo studio la riguarderà.',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];
        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
