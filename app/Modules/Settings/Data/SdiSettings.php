<?php

declare(strict_types=1);

namespace App\Modules\Settings\Data;

use App\Support\Shape;

/**
 * The SdI channel (sdi.md §2): the app's PEC address, the address SdI
 * answers from, and the two switches. The mailbox credentials are secrets
 * and live in config/pec.php, never here.
 */
final readonly class SdiSettings
{
    public const string FIRST_SDI_ADDRESS = 'sdi01@pec.fatturapa.it';

    public function __construct(
        public ?string $pecAddress = null,
        public string $sdiAddress = self::FIRST_SDI_ADDRESS,
        public bool $autoSend = false,
        public bool $inboxEnabled = false,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $pec = Shape::nullableString($data, 'pec_address');
        $sdi = Shape::nullableString($data, 'sdi_address');

        return new self(
            pecAddress: $pec === null ? null : mb_strtolower(trim($pec)),
            sdiAddress: $sdi === null || trim($sdi) === '' ? self::FIRST_SDI_ADDRESS : mb_strtolower(trim($sdi)),
            autoSend: Shape::bool($data, 'auto_send'),
            inboxEnabled: Shape::bool($data, 'inbox_enabled'),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'pec_address' => $this->pecAddress,
            'sdi_address' => $this->sdiAddress,
            'auto_send' => $this->autoSend,
            'inbox_enabled' => $this->inboxEnabled,
        ];
    }

    public function withSdiAddress(string $address): self
    {
        return new self($this->pecAddress, mb_strtolower(trim($address)), $this->autoSend, $this->inboxEnabled);
    }

    /**
     * Still pointed at the first-contact address: SdI has not answered yet.
     */
    public function awaitsSdiReplyAddress(): bool
    {
        return $this->sdiAddress === self::FIRST_SDI_ADDRESS;
    }
}
