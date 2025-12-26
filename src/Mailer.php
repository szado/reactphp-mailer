<?php

namespace Shado\React\Mailer;

use Clue\React\NDJson\Decoder;
use Clue\React\NDJson\Encoder;
use Evenement\EventEmitter;
use React\ChildProcess\Process;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use Throwable;
use function React\Async\await;

final class Mailer extends EventEmitter
{
    /** @var array<string, Deferred> */
    private array $pending = [];

    private Encoder $encoder;

    private int $counter = 0;

    /**
     * @throws MailerException
     */
    public function __construct(
        #[\SensitiveParameter]
        private readonly string $dsn,
        private readonly Process $worker,
    ) {
        $this->initialize();
    }

    public function __destruct()
    {
        $this->worker->terminate();
    }

    public function send(Email $email): void
    {
        await($this->sendAsync($email));
    }

    public function sendAsync(Email $email): PromiseInterface
    {
        return $this->sendToWorker('send', $email);
    }

    /**
     * @throws MailerException
     */
    private function initialize(): void
    {
        $this->encoder = new Encoder(
            $this->worker->stdin,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | (PHP_VERSION_ID >= 50606 ? JSON_PRESERVE_ZERO_FRACTION : 0)
        );

        $decoder = new Decoder($this->worker->stdout);

        $decoder->on('data', $this->handleResponse(...));

        $decoder->on('error', function (Throwable $error): void {
            $this->rejectAll(new MailerException("Invalid response from mailer worker: {$error->getMessage()}"));
        });

        $this->worker->stderr->on('data', function (string $chunk): void {
            $this->rejectAll(new MailerException(trim($chunk)));
        });

        $this->worker->on('exit', function (?int $code, ?int $term): void {
            $message = sprintf('Mailer worker stopped (code: %s, signal: %s)', $code ?? '-', $term ?? '-');
            $this->rejectAll(new MailerException($message));
            $this->emit('dead', [$code, $term]);
        });

        await($this->sendToWorker('dsn', $this->dsn));
    }

    /**
     * @return PromiseInterface<void, MailerException>
     */
    private function sendToWorker(string $type, mixed $payload): PromiseInterface
    {
        $deferred = new Deferred();

        if ($this->worker->isRunning()) {
            $id = (string)(++$this->counter);

            if ($this->encoder->write(['id' => $id, 'type' => $type, 'payload' => $payload])) {
                $this->pending[$id] = $deferred;
            } else {
                $deferred->reject(new MailerException('Failed to write to mailer worker'));
            }
        } else {
            $deferred->reject(new MailerException('Mailer process is dead'));
        }

        return $deferred->promise();
    }

    private function handleResponse(mixed $data): void
    {
        if (!is_array($data) || !isset($data['status'])) {
            $this->rejectAll(new MailerException('Malformed response from the worker'));
            return;
        }

        if (!isset($data['id']) && $data['status'] === 'error') {
            $error = $data['message'] ?? 'Unknown error';
            $this->rejectAll(new MailerException("[Worker] Fatal error: $error"));
            return;
        }

        if (!isset($data['id'])) {
            $this->rejectAll(new MailerException('Unexpected response from the worker'));
            return;
        }

        $id = (string)$data['id'];
        $deferred = $this->pending[$id] ?? null;
        unset($this->pending[$id]);

        if (!$deferred) {
            return;
        }

        if ($data['status'] === 'ok') {
            $deferred->resolve(null);
            return;
        }

        $error = $data['message'] ?? 'Unknown error';
        $deferred->reject(new MailerException("[Worker] $error"));
    }

    private function rejectAll(MailerException $exception): void
    {
        foreach ($this->pending as $deferred) {
            $deferred->reject($exception);
        }
        $this->pending = [];
    }
}