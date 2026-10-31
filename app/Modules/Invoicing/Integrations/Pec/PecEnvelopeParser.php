<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Integrations\Pec;

use Carbon\CarbonImmutable;
use DOMDocument;
use DOMXPath;
use Throwable;
use Webklex\PHPIMAP\Address;
use Webklex\PHPIMAP\Attachment;
use Webklex\PHPIMAP\Message;

/**
 * Opens a PEC message (sdi.md §5): reads daticert.xml for the
 * certification type and the referenced Message-ID, and the attachments
 * of the original message inside postacert.eml.
 */
final class PecEnvelopeParser
{
    public function parse(string $raw): PecEnvelope
    {
        $message = Message::fromString($raw);
        $attachments = $this->attachments($message);

        $certificationType = null;
        $certificationError = null;
        $referenced = null;
        $originalFrom = null;
        $inner = [];

        foreach ($attachments as $attachment) {
            if (strcasecmp($attachment->name, 'daticert.xml') === 0) {
                [$certificationType, $certificationError, $referenced, $sender] = $this->certification($attachment->contents);
                $originalFrom ??= $sender;

                continue;
            }
            if (strcasecmp($attachment->name, 'postacert.eml') === 0 || ($attachment->mimeType !== null && strcasecmp($attachment->mimeType, 'message/rfc822') === 0)) {
                try {
                    $innerMessage = Message::fromString($attachment->contents);
                    $inner = [...$inner, ...$this->attachments($innerMessage)];
                    $originalFrom ??= $this->sender($innerMessage);
                } catch (Throwable) {
                    // An unreadable inner message: the outer attachments are all we have.
                }

                continue;
            }
            if (strcasecmp($attachment->name, 'smime.p7s') === 0) {
                continue;
            }
            $inner[] = $attachment;
        }

        $messageId = trim((string) $message->getMessageId(), " \t<>");
        $date = null;
        try {
            $date = CarbonImmutable::instance($message->getDate()->toDate());
        } catch (Throwable) {
            $date = null;
        }

        $from = $this->sender($message);

        return new PecEnvelope(
            messageId: $messageId !== '' ? $messageId : hash('sha256', $raw),
            from: $from,
            originalFrom: $originalFrom ?? ($certificationType === null ? $from : null),
            subject: trim((string) $message->getSubject()),
            date: $date,
            certificationType: $certificationType,
            certificationError: $certificationError,
            referencedMessageId: $referenced,
            attachments: $inner,
        );
    }

    private function sender(Message $message): ?string
    {
        try {
            $address = $message->getFrom()->first();

            return $address instanceof Address && $address->mail !== '' ? mb_strtolower($address->mail) : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return list<PecAttachment>
     */
    private function attachments(Message $message): array
    {
        $result = [];
        foreach ($message->getAttachments() as $attachment) {
            if (! $attachment instanceof Attachment) {
                continue;
            }
            $name = $attachment->name ?? $attachment->filename ?? '';
            $content = $attachment->content;
            if ($name === '') {
                continue;
            }
            $type = $attachment->content_type;
            $result[] = new PecAttachment(basename($name), $content, trim((string) $type) === '' ? null : strtolower(trim(explode(';', (string) $type)[0])));
        }

        return $result;
    }

    /**
     * @return array{?string, ?string, ?string, ?string} type, error, referenced message id, original sender
     */
    private function certification(string $xml): array
    {
        $previous = libxml_use_internal_errors(true);
        try {
            $document = new DOMDocument;
            if (! $document->loadXML($xml)) {
                return [null, null, null, null];
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        $xpath = new DOMXPath($document);
        $type = $xpath->evaluate('string(/*[local-name()="postacert"]/@tipo)');
        $error = $xpath->evaluate('string(/*[local-name()="postacert"]/@errore)');
        $msgid = $xpath->evaluate('string(//*[local-name()="msgid"])');
        $sender = $xpath->evaluate('string(//*[local-name()="intestazione"]/*[local-name()="mittente"])');

        return [
            is_string($type) && $type !== '' ? $type : null,
            is_string($error) && $error !== '' && $error !== 'nessuno' ? $error : null,
            is_string($msgid) && trim($msgid, " <>\t\n") !== '' ? trim($msgid, " <>\t\n") : null,
            is_string($sender) && trim($sender) !== '' ? mb_strtolower(trim($sender)) : null,
        ];
    }
}
