<?php

namespace Shado\Tests\React\Mailer;

use React\ChildProcess\Process;
use React\Stream\ReadableStreamInterface;
use React\Stream\WritableStreamInterface;
use ReflectionClass;
use Shado\React\Mailer\Factory;
use PHPUnit\Framework\TestCase;

class FactoryTest extends TestCase
{
    public function testSpawnChildProcessCreatesProcessWithAllIo(): void
    {
        $factory = new Factory(workerScript: __DIR__ . '/fixtures/dummy-worker.php');
        $ref = new ReflectionClass($factory);
        $method = $ref->getMethod('spawnChildProcess');
        /** @var Process $process */
        $process = $method->invoke($factory);

        try {
            $this->assertInstanceOf(Process::class, $process);
            $this->assertInstanceOf(WritableStreamInterface::class, $process->stdin);
            $this->assertInstanceOf(ReadableStreamInterface::class, $process->stdout);
            $this->assertInstanceOf(ReadableStreamInterface::class, $process->stderr);
        } finally {
            $process->terminate(\SIGKILL);
        }
    }
}
