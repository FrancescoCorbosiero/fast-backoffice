<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Integrations\Pec;

use Illuminate\Contracts\Config\Repository;
use RuntimeException;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Part\DataPart;
use Throwable;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\ClientManager;
use Webklex\PHPIMAP\Folder;
use Webklex\PHPIMAP\Message;

/**
 * The real mailbox: Symfony Mailer over SMTP out, webklex/php-imap in.
 * Processed messages are flagged (\Flagged), never deleted (sdi.md §5).
 */
final class SmtpImapPecMailbox implements PecMailbox
{
    public function __construct(private readonly Repository $config) {}

    public function isConfigured(): bool
    {
        return $this->string('pec.smtp.host') !== null && $this->string('pec.imap.host') !== null && $this->string('pec.smtp.username') !== null;
    }

    public function send(OutgoingPecMessage $message): string
    {
        $host = $this->string('pec.smtp.host') ?? throw new RuntimeException('PEC SMTP is not configured.');
        $port = $this->int('pec.smtp.port', 465);
        $encryption = $this->string('pec.smtp.encryption') ?? 'ssl';
        $scheme = $encryption === 'ssl' ? 'smtps' : 'smtp';
        $dsn = sprintf('%s://%s:%s@%s:%d', $scheme, rawurlencode($this->string('pec.smtp.username') ?? ''), rawurlencode($this->string('pec.smtp.password') ?? ''), $host, $port);
        if ($encryption === 'none') {
            $dsn .= '?auto_tls=false';
        }

        $email = (new Email)
            ->from(new Address($message->from))
            ->to(new Address($message->to))
            ->subject($message->subject)
            ->text($message->text)
            ->addPart(new DataPart($message->attachmentContents, $message->attachmentName, $message->attachmentMimeType));
        $messageId = $email->generateMessageId();
        $email->getHeaders()->addIdHeader('Message-ID', $messageId);

        try {
            Transport::fromDsn($dsn)->send($email);
        } catch (Throwable $exception) {
            throw new RuntimeException('PEC send failed: '.$exception->getMessage(), 0, $exception);
        }

        return $messageId;
    }

    public function fetchUnprocessed(int $limit = 50): array
    {
        $folder = $this->folder();
        $messages = [];

        try {
            $collection = $folder->query()->unflagged()->leaveUnread()->setFetchOrder('asc')->limit($limit)->get();
            foreach ($collection as $message) {
                if (! $message instanceof Message) {
                    continue;
                }
                $header = $message->getHeader();
                $structure = $message->getStructure();
                $raw = ($header !== null ? $header->raw : '')."\r\n\r\n".($structure !== null ? $structure->raw : '');
                $messages[] = new IncomingPecMessage((string) $message->getUid(), $raw);
            }
        } catch (Throwable $exception) {
            throw new RuntimeException('PEC inbox read failed: '.$exception->getMessage(), 0, $exception);
        } finally {
            $folder->getClient()->disconnect();
        }

        return $messages;
    }

    public function markProcessed(IncomingPecMessage $message): void
    {
        $folder = $this->folder();

        try {
            $folder->query()->getMessageByUid((int) $message->uid)->setFlag('Flagged');
        } catch (Throwable $exception) {
            throw new RuntimeException('PEC flag failed: '.$exception->getMessage(), 0, $exception);
        } finally {
            $folder->getClient()->disconnect();
        }
    }

    private function folder(): Folder
    {
        $host = $this->string('pec.imap.host') ?? throw new RuntimeException('PEC IMAP is not configured.');
        $encryption = $this->string('pec.imap.encryption') ?? 'ssl';

        try {
            /** @var Client $client */
            $client = (new ClientManager)->make([
                'host' => $host,
                'port' => $this->int('pec.imap.port', 993),
                'encryption' => $encryption === 'none' ? false : $encryption,
                'validate_cert' => (bool) $this->config->get('pec.imap.validate_cert', true),
                'username' => $this->string('pec.imap.username') ?? '',
                'password' => $this->string('pec.imap.password') ?? '',
                'protocol' => 'imap',
            ]);
            $client->connect();

            return $client->getFolderByPath($this->string('pec.imap.folder') ?? 'INBOX')
                ?? throw new RuntimeException('PEC folder not found.');
        } catch (RuntimeException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new RuntimeException('PEC IMAP connection failed: '.$exception->getMessage(), 0, $exception);
        }
    }

    private function string(string $key): ?string
    {
        $value = $this->config->get($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function int(string $key, int $default): int
    {
        $value = $this->config->get($key);

        return is_numeric($value) ? (int) $value : $default;
    }
}
