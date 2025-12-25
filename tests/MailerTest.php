<?php

declare(strict_types=1);

namespace Shado\Tests\React\Mailer;

use PHPUnit\Framework\TestCase;
use React\ChildProcess\Process;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use ReflectionProperty;
use Shado\React\Mailer\Mailer;
use Shado\React\Mailer\MailerException;

final class MailerTest extends TestCase
{
    public function testHandleResponseResolvesMatchingPromise(): void
    {
        [$mailer, $promise] = $this->createMailerWithPending('1');

        $resolved = null;
        $rejected = null;

        $promise->then(
            function (mixed $value) use (&$resolved): void {
                $resolved = $value;
            },
            function (mixed $reason) use (&$rejected): void {
                $rejected = $reason;
            },
        );

        $this->invokeHandleResponse($mailer, ['id' => '1', 'status' => 'ok']);

        $this->assertNull($rejected);
        $this->assertNull($resolved);
    }

    public function testHandleResponseRejectsSpecificPendingPromiseOnError(): void
    {
        [$mailer, $promise] = $this->createMailerWithPending('2');

        $resolved = null;
        $rejected = null;

        $promise->then(
            function (mixed $value) use (&$resolved): void {
                $resolved = $value;
            },
            function (mixed $reason) use (&$rejected): void {
                $rejected = $reason;
            },
        );

        $this->invokeHandleResponse($mailer, ['id' => '2', 'status' => 'error', 'message' => 'Transport down']);

        $this->assertNull($resolved);
        $this->assertInstanceOf(MailerException::class, $rejected);
        $this->assertSame('[Worker] Transport down', $rejected->getMessage());
    }

    public function testFatalWorkerErrorRejectsAllPendingPromises(): void
    {
        [$mailer, $promise] = $this->createMailerWithPending('3');

        $resolved = null;
        $rejected = null;

        $promise->then(
            function (mixed $value) use (&$resolved): void {
                $resolved = $value;
            },
            function (mixed $reason) use (&$rejected): void {
                $rejected = $reason;
            },
        );

        $this->invokeHandleResponse($mailer, ['status' => 'error', 'message' => 'Unexpected failure']);

        $this->assertNull($resolved);
        $this->assertInstanceOf(MailerException::class, $rejected);
        $this->assertSame('[Worker] Fatal error: Unexpected failure', $rejected->getMessage());
    }

    /**
     * @return array{0: Mailer, 1: PromiseInterface}
     */
    private function createMailerWithPending(string $id): array
    {
        $deferred = new Deferred();

        $process = $this->getMockBuilder(Process::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['terminate'])
            ->getMock();

        $mailer = (new \ReflectionClass(Mailer::class))->newInstanceWithoutConstructor();

        $this->setProperty($mailer, 'pending', [$id => $deferred]);
        $this->setProperty($mailer, 'counter', 0);
        $this->setProperty($mailer, 'dsn', 'smtp://example.com');
        $this->setProperty($mailer, 'worker', $process);

        return [$mailer, $deferred->promise()];
    }

    private function invokeHandleResponse(Mailer $mailer, array $payload): void
    {
        $method = new \ReflectionMethod(Mailer::class, 'handleResponse');
        $method->invoke($mailer, $payload);
    }

    private function setProperty(Mailer $mailer, string $property, mixed $value): void
    {
        $reflectionProperty = new ReflectionProperty(Mailer::class, $property);
        $reflectionProperty->setValue($mailer, $value);
    }
}