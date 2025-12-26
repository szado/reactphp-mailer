<?php

declare(strict_types=1);

use Clue\React\NDJson\Decoder;
use Clue\React\NDJson\Encoder;
use React\EventLoop\Loop;
use React\Stream\DuplexResourceStream;
use React\Stream\ReadableResourceStream;
use React\Stream\ThroughStream;
use React\Stream\WritableResourceStream;
use Shado\React\Mailer\Email;
use Shado\React\Mailer\EmailAddress;
use Symfony\Component\Mailer\Mailer as SymfonyMailer;
use Symfony\Component\Mailer\Transport as SymfonyTransport;
use Symfony\Component\Mime\Address as SymfonyAddress;
use Symfony\Component\Mime\Email as SymfonyEmail;

if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require __DIR__ . '/../vendor/autoload.php';
} else {
    require __DIR__ . '/../../../autoload.php';
}

$symfonyMailer = null;
[$decoder, $encoder] = createStreams();

$decoder->on('error', static function (Throwable $error) use ($encoder, $decoder): void {
    $encoder->end(['status' => 'error', 'message' => 'Input error: ' . $error->getMessage()]);
    $decoder->close();
});

$decoder->on('data', static function (mixed $data) use ($decoder, $encoder, &$symfonyMailer): void {
    if (!is_array($data) || array_is_list($data) || !isset($data['id'], $data['type'], $data['payload'])) {
        $decoder->close();
        $encoder->end(['status' => 'error', 'message' => 'Malformed message']);
        return;
    }

    if ($data['type'] === 'dsn') {
        try {
            $symfonyMailer = new SymfonyMailer(SymfonyTransport::fromDsn((string)$data['payload']));
            $encoder->write(['id' => $data['id'], 'status' => 'ok']);
        } catch (Throwable $throwable) {
            $decoder->close();
            $encoder->end(['status' => 'error', 'message' => $throwable->getMessage()]);
        }
    } elseif ($data['type'] === 'send') {
        try {
            if (!$symfonyMailer) {
                $encoder->write(['id' => $data['id'], 'status' => 'error', 'message' => 'Try to send email before DSN initialization']);
                return;
            }

            $message = buildEmailFromPayload((array)$data['payload']);
            $symfonyMailer->send($message);
            $encoder->write(['id' => $data['id'], 'status' => 'ok']);
        } catch (Throwable $exception) {
            $encoder->write(['id' => $data['id'], 'status' => 'error', 'message' => $exception->getMessage()]);
        }
    } else {
        $encoder->write(['id' => $data['id'], 'status' => 'error', 'message' => "Unknown message type: {$data['type']}"]);
    }
});

Loop::run();

/**
 * @return array{0: Decoder, 1: Encoder}
 */
function createStreams(): array {
    if (isset($_SERVER['argv'][1])) {
        $socket = stream_socket_client($_SERVER['argv'][1]);
        $stream = new DuplexResourceStream($socket);

        $through = new ThroughStream();
        $stream->on('data', static function ($chunk) use ($through): void {
            $through->write($chunk);
        });

        return [
            new Decoder($through),
            new Encoder($stream, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | (PHP_VERSION_ID >= 50606 ? JSON_PRESERVE_ZERO_FRACTION : 0)),
        ];
    }

    return [
        new Decoder(new ReadableResourceStream(STDIN), assoc: true),
        new Encoder(new WritableResourceStream(STDOUT), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | (PHP_VERSION_ID >= 50606 ? JSON_PRESERVE_ZERO_FRACTION : 0)),
    ];
}

function buildEmailFromPayload(array $payload): SymfonyEmail {
    $email = Email::fromArray($payload);
    $symfonyEmail = (new SymfonyEmail())
        ->from(createSymfonyAddress($email->from))
        ->to(...createSymfonyAddresses($email->to))
        ->cc(...createSymfonyAddresses($email->cc))
        ->bcc(...createSymfonyAddresses($email->bcc))
        ->replyTo(...createSymfonyAddresses($email->replyTo))
        ->subject($email->subject ?? '')
        ->text($email->text ?? '')
        ->html($email->html ?? '');

    foreach ($email->attachments as $attachment) {
        if ($attachment->inline) {
            $symfonyEmail->embedFromPath($attachment->path, $attachment->name, $attachment->mimeType);
            continue;
        }
        $symfonyEmail->attachFromPath($attachment->path, $attachment->name, $attachment->mimeType);
    }

    return $symfonyEmail;
}

function createSymfonyAddress(EmailAddress $emailAddress): SymfonyAddress
{
    return new SymfonyAddress($emailAddress->address, $emailAddress->name ?? '');
}

function createSymfonyAddresses(array $emailAddresses): array
{
    return array_map(createSymfonyAddress(...), $emailAddresses);
}