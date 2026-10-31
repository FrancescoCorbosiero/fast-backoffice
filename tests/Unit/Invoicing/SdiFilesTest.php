<?php

declare(strict_types=1);

use App\Modules\Invoicing\Enums\PecMessageKind;
use App\Modules\Invoicing\Integrations\FatturaPa\SignedFile;
use App\Modules\Invoicing\Integrations\Pec\PecEnvelopeParser;
use App\Modules\Invoicing\Integrations\Pec\SdiFileNames;
use App\Modules\Invoicing\Integrations\Pec\SdiNotificationReader;
use Tests\Support\PecFixtures;

it('recognises the names SdI gives its files', function (): void {
    expect(SdiFileNames::notification('IT01234567897_00001_RC_001.xml'))->toBe(['invoice' => 'IT01234567897_00001.xml', 'code' => 'RC'])
        ->and(SdiFileNames::notification('IT01234567897_0007A_MT_001.xml'))->toBe(['invoice' => 'IT01234567897_0007A.xml', 'code' => 'MT'])
        ->and(SdiFileNames::notification('IT01234567897_00001.xml'))->toBeNull()
        ->and(SdiFileNames::isMetadata('IT01234567897_0007A_MT_001.xml'))->toBeTrue()
        ->and(SdiFileNames::isInvoice('IT01234567897_00001.xml'))->toBeTrue()
        ->and(SdiFileNames::isInvoice('IT01234567897_0007A.xml.p7m'))->toBeTrue()
        ->and(SdiFileNames::isInvoice('IT01234567897_00001_RC_001.xml'))->toBeFalse()
        ->and(SdiFileNames::isInvoice('daticert.xml'))->toBeFalse()
        ->and(SdiFileNames::plain('IT01234567897_0007A.xml.p7m'))->toBe('IT01234567897_0007A.xml');
});

it('removes the CAdES envelope from a signed invoice, DER or base64', function (): void {
    $der = (string) file_get_contents(__DIR__.'/../../Fixtures/sdi/IT04827193048_0007A.xml.p7m');
    $xml = (string) file_get_contents(__DIR__.'/../../Fixtures/sdi/IT04827193048_0007A.xml');

    expect(SignedFile::isSigned('a.xml.p7m'))->toBeTrue()
        ->and(SignedFile::isSigned('a.xml'))->toBeFalse()
        ->and(SignedFile::unwrap($der))->toBe($xml)
        ->and(SignedFile::unwrap(chunk_split(base64_encode($der), 76)))->toBe($xml)
        ->and(fn () => SignedFile::unwrap('not a signed file'))->toThrow(InvalidArgumentException::class);
});

it('reads SdI notifications whatever their namespace prefix', function (): void {
    $reader = new SdiNotificationReader;

    $rc = $reader->read(PecFixtures::rc('IT01234567897_00001.xml', '42'));
    expect($rc->kind)->toBe(PecMessageKind::SdiDelivered)
        ->and($rc->sdiIdentifier)->toBe('42')
        ->and($rc->fileName)->toBe('IT01234567897_00001.xml')
        ->and($rc->deliveredAt?->toIso8601String())->toBe('2026-03-11T09:12:30+01:00')
        ->and($rc->errors)->toBe([]);

    $ns = $reader->read(PecFixtures::ns('IT01234567897_00002.xml', [['00404', 'Fattura duplicata'], ['00305', 'IdFiscaleIVA del CessionarioCommittente non valido']]));
    expect($ns->kind)->toBe(PecMessageKind::SdiRejected)
        ->and($ns->errorLines())->toBe(['00404 — Fattura duplicata', '00305 — IdFiscaleIVA del CessionarioCommittente non valido'])
        ->and($ns->errors[0]['hint'])->toBe('Correggere e reinviare');

    $mc = $reader->read(PecFixtures::mc('IT01234567897_00003.xml'));
    expect($mc->kind)->toBe(PecMessageKind::SdiNotDelivered)->and($mc->description)->toContain('non è raggiungibile');

    expect($reader->read(PecFixtures::at('IT01234567897_00003.xml'))->kind)->toBe(PecMessageKind::SdiAttestation)
        ->and($reader->read(PecFixtures::mt('IT04827193048_0007A.xml', '777'))->sdiIdentifier)->toBe('777')
        ->and(fn () => $reader->read('<root/>'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $reader->read('nope'))->toThrow(InvalidArgumentException::class);
});

it('opens a PEC envelope: certification data outside, the original attachments inside', function (): void {
    $parser = new PecEnvelopeParser;

    $envelope = $parser->parse(PecFixtures::envelope('posta-certificata', [['IT01234567897_00001_RC_001.xml', PecFixtures::rc('IT01234567897_00001.xml'), 'application/xml']], messageId: 'abc123@pec.provider.test'));
    expect($envelope->messageId)->toBe('abc123@pec.provider.test')
        ->and($envelope->certificationType)->toBe('posta-certificata')
        ->and($envelope->isTransportReceipt())->toBeFalse()
        ->and($envelope->from)->toBe('posta-certificata@pec.provider.test')
        ->and($envelope->originalFrom)->toBe(PecFixtures::SDI_REPLY_ADDRESS)
        ->and($envelope->date?->toDateString())->toBe('2026-03-11')
        ->and(array_map(fn ($a) => $a->name, $envelope->attachments))->toBe(['IT01234567897_00001_RC_001.xml'])
        ->and($envelope->attachment('it01234567897_00001_rc_001.xml')?->contents)->toContain('RicevutaConsegna');

    $receipt = $parser->parse(PecFixtures::receipt('accettazione', 'sent-1@studio.test'));
    expect($receipt->certificationType)->toBe('accettazione')
        ->and($receipt->isTransportReceipt())->toBeTrue()
        ->and($receipt->referencedMessageId)->toBe('sent-1@studio.test')
        ->and($receipt->attachments)->toBe([]);

    $plain = $parser->parse(PecFixtures::plain('Ciao', 'testo', 'plain-1@example.test'));
    expect($plain->certificationType)->toBeNull()->and($plain->messageId)->toBe('plain-1@example.test')->and($plain->subject)->toBe('Ciao')->and($plain->originalFrom)->toBe('someone@example.test');

    $signed = $parser->parse(PecFixtures::envelope('posta-certificata', [['IT04827193048_0007A.xml.p7m', PecFixtures::supplierInvoiceP7m(), 'application/pkcs7-mime'], ['IT04827193048_0007A_MT_001.xml', PecFixtures::mt('IT04827193048_0007A.xml'), 'application/xml']]));
    expect(array_map(fn ($a) => $a->name, $signed->attachments))->toBe(['IT04827193048_0007A.xml.p7m', 'IT04827193048_0007A_MT_001.xml'])
        ->and($signed->attachments[0]->contents)->toBe(PecFixtures::supplierInvoiceP7m());
});
