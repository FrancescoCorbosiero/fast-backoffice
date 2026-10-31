<?php

declare(strict_types=1);

namespace Tests\Support;

use DateTimeImmutable;
use DateTimeZone;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Part\DataPart;

/**
 * PEC envelopes and SdI notifications as the mailbox would hold them,
 * built from the formats in sdi.md §5. Everything is anonymised.
 */
final class PecFixtures
{
    public const string SDI_REPLY_ADDRESS = 'sdi14@pec.fatturapa.it';

    /**
     * A PEC envelope: daticert.xml plus the original message in postacert.eml.
     *
     * @param  list<array{string, string, string}>  $attachments  [name, contents, mime] of the original message
     */
    public static function envelope(string $tipo, array $attachments, ?string $referencedMessageId = null, string $from = self::SDI_REPLY_ADDRESS, string $subject = 'POSTA CERTIFICATA: Notifica', ?string $messageId = null, string $innerSubject = 'Notifica'): string
    {
        $inner = (new Email)->from(new Address($from))->to('mario@pec.test')->subject($innerSubject)->text('Messaggio originale');
        foreach ($attachments as [$name, $contents, $mime]) {
            $inner->addPart(new DataPart($contents, $name, $mime));
        }

        $outer = (new Email)
            ->from(new Address('posta-certificata@pec.provider.test', 'Per conto di: '.$from))
            ->to('mario@pec.test')
            ->subject($subject)
            ->date(new DateTimeImmutable('2026-03-11 10:15:00', new DateTimeZone('Europe/Rome')))
            ->text('Messaggio di posta certificata')
            ->addPart(new DataPart(self::daticert($tipo, $referencedMessageId, sender: $from), 'daticert.xml', 'application/xml'))
            ->addPart(new DataPart($inner->toString(), 'postacert.eml', 'message/rfc822'));

        $outer->getHeaders()->addIdHeader('Message-ID', $messageId ?? bin2hex(random_bytes(12)).'@pec.provider.test');

        return $outer->toString();
    }

    /**
     * The provider's receipt for a message we sent: no original inside.
     */
    public static function receipt(string $tipo, string $referencedMessageId, ?string $messageId = null): string
    {
        $outer = (new Email)
            ->from(new Address('posta-certificata@pec.provider.test'))
            ->to('mario@pec.test')
            ->subject(($tipo === 'accettazione' ? 'ACCETTAZIONE' : 'CONSEGNA').': IT01234567897_00001.xml')
            ->date(new DateTimeImmutable('2026-03-11 09:00:00', new DateTimeZone('Europe/Rome')))
            ->text('Ricevuta')
            ->addPart(new DataPart(self::daticert($tipo, $referencedMessageId), 'daticert.xml', 'application/xml'));
        $outer->getHeaders()->addIdHeader('Message-ID', $messageId ?? bin2hex(random_bytes(12)).'@pec.provider.test');

        return $outer->toString();
    }

    /**
     * A plain message, no PEC certification at all.
     */
    public static function plain(string $subject, string $text = 'Ciao', ?string $messageId = null): string
    {
        $email = (new Email)->from('someone@example.test')->to('mario@pec.test')->subject($subject)->text($text);
        $email->getHeaders()->addIdHeader('Message-ID', $messageId ?? bin2hex(random_bytes(12)).'@example.test');

        return $email->toString();
    }

    public static function daticert(string $tipo, ?string $referencedMessageId, string $errore = 'nessuno', string $sender = 'mario@pec.test'): string
    {
        $msgid = $referencedMessageId === null ? '' : '<msgid>&lt;'.$referencedMessageId.'&gt;</msgid>';

        return '<?xml version="1.0" encoding="UTF-8"?><postacert tipo="'.$tipo.'" errore="'.$errore.'"><intestazione><mittente>'.$sender.'</mittente><destinatari tipo="certificato">sdi01@pec.fatturapa.it</destinatari><oggetto>IT01234567897_00001.xml</oggetto></intestazione>'
            .'<dati><gestore-emittente>Provider PEC</gestore-emittente><data zona="+0100"><giorno>11/03/2026</giorno><ora>09:00:00</ora></data><identificativo>opec.'.bin2hex(random_bytes(6)).'@pec.provider.test</identificativo>'.$msgid.'</dati></postacert>';
    }

    public static function rc(string $fileName, string $sdiId = '1234567', string $codice = 'ABC1234'): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?><ns3:RicevutaConsegna xmlns:ns3="http://www.fatturapa.gov.it/sdi/messaggi/v1.0" versione="1.0">'
            .'<IdentificativoSdI>'.$sdiId.'</IdentificativoSdI><NomeFile>'.$fileName.'</NomeFile><DataOraRicezione>2026-03-11T09:10:00.000+01:00</DataOraRicezione><DataOraConsegna>2026-03-11T09:12:30.000+01:00</DataOraConsegna>'
            .'<Destinatario><Codice>'.$codice.'</Codice><Descrizione>Consegna al codice destinatario</Descrizione></Destinatario><MessageId>98765</MessageId><PecMessageId>opec@pec.provider.test</PecMessageId><Note/></ns3:RicevutaConsegna>';
    }

    /**
     * @param  list<array{string, string}>  $errors  [code, description]
     */
    public static function ns(string $fileName, array $errors, string $sdiId = '1234568'): string
    {
        $list = '';
        foreach ($errors as [$code, $description]) {
            $list .= '<Errore><Codice>'.$code.'</Codice><Descrizione>'.$description.'</Descrizione><Suggerimento>Correggere e reinviare</Suggerimento></Errore>';
        }

        return '<?xml version="1.0" encoding="UTF-8"?><ns3:NotificaScarto xmlns:ns3="http://www.fatturapa.gov.it/sdi/messaggi/v1.0" versione="1.0">'
            .'<IdentificativoSdI>'.$sdiId.'</IdentificativoSdI><NomeFile>'.$fileName.'</NomeFile><DataOraRicezione>2026-03-11T09:10:00.000+01:00</DataOraRicezione><RiferimentoArchivio><IdentificativoSdI>'.$sdiId.'</IdentificativoSdI><NomeFile>'.$fileName.'</NomeFile></RiferimentoArchivio>'
            .'<ListaErrori>'.$list.'</ListaErrori><MessageId>98766</MessageId><Note/></ns3:NotificaScarto>';
    }

    public static function mc(string $fileName, string $sdiId = '1234569'): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?><ns3:NotificaMancataConsegna xmlns:ns3="http://www.fatturapa.gov.it/sdi/messaggi/v1.0" versione="1.0">'
            .'<IdentificativoSdI>'.$sdiId.'</IdentificativoSdI><NomeFile>'.$fileName.'</NomeFile><DataOraRicezione>2026-03-11T09:10:00.000+01:00</DataOraRicezione>'
            .'<Descrizione>Il canale di trasmissione del destinatario non è raggiungibile</Descrizione><MessageId>98767</MessageId><Note/></ns3:NotificaMancataConsegna>';
    }

    public static function at(string $fileName, string $sdiId = '1234569'): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?><ns3:AttestazioneTrasmissioneFattura xmlns:ns3="http://www.fatturapa.gov.it/sdi/messaggi/v1.0" versione="1.0">'
            .'<IdentificativoSdI>'.$sdiId.'</IdentificativoSdI><NomeFile>'.$fileName.'</NomeFile><DataOraRicezione>2026-03-11T09:10:00.000+01:00</DataOraRicezione>'
            .'<Destinatario><Codice>ABC1234</Codice><Descrizione>Impossibilità di recapito</Descrizione></Destinatario><MessageId>98768</MessageId><PecMessageId>x</PecMessageId><Note/><HashFileOriginale>abc</HashFileOriginale></ns3:AttestazioneTrasmissioneFattura>';
    }

    public static function mt(string $fileName, string $sdiId = '7654321'): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?><ns3:MetadatiInvioFile xmlns:ns3="http://www.fatturapa.gov.it/sdi/messaggi/v1.0" versione="1.0">'
            .'<IdentificativoSdI>'.$sdiId.'</IdentificativoSdI><NomeFile>'.$fileName.'</NomeFile><CodiceDestinatario>0000000</CodiceDestinatario><Formato>FPR12</Formato><TentativiInvio>1</TentativiInvio><MessageId>55555</MessageId><Note/></ns3:MetadatiInvioFile>';
    }

    public static function supplierInvoiceXml(): string
    {
        return (string) file_get_contents(__DIR__.'/../Fixtures/sdi/IT04827193048_0007A.xml');
    }

    public static function supplierInvoiceP7m(): string
    {
        return (string) file_get_contents(__DIR__.'/../Fixtures/sdi/IT04827193048_0007A.xml.p7m');
    }
}
