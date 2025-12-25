<?php

declare(strict_types=1);

namespace Shado\React\Mailer;

use React\ChildProcess\Process;

class Factory
{
    /**
     * @param string|null $binary Path to the PHP binary to use for spawning worker processes. If null, the same binary as the current process will be used.
     */
    public function __construct(
        private ?string $binary = null,
    ) {
        $this->binary ??= $this->php();
    }

    /**
     * Create a Mailer by the DSN string.
     * Example: `smtp://user:pass@smtp.example.com:25`
     * @param string $dsn The DSN string for the mailer transport; see Symfony Mailer documentation for supported transports and formats.
     * @throws MailerException
     * @see https://symfony.com/doc/current/mailer.html#using-built-in-transports
     */
    public function byDsn(string $dsn): Mailer
    {
        $process = $this->spawnChildProcess();
        return new Mailer($dsn, $process);
    }

    private function spawnChildProcess(): Process
    {
        $cwd = null;
        $worker = \dirname(__DIR__) . '/res/mailer-worker.php';

        // launch worker process directly or inside Phar by mapping relative paths
        if (\class_exists('Phar', false) && ($phar = \Phar::running(false)) !== '') {
            $worker = '-r' . 'Phar::loadPhar(' . var_export($phar, true) . ');require(' . \var_export($worker, true) . ');';
        } else {
            $cwd = __DIR__ . '/../res';
            $worker = \basename($worker);
        }
        $command = 'exec ' . \escapeshellarg($this->binary) . ' ' . escapeshellarg($worker);

        // Try to get list of all open FDs (Linux/Mac and others)
        $fds = @\scandir('/dev/fd');

        // Otherwise try temporarily duplicating file descriptors in the range 0-1024 (FD_SETSIZE).
        // This is known to work on more exotic platforms and also inside chroot
        // environments without /dev/fd. Causes many syscalls, but still rather fast.
        // @codeCoverageIgnoreStart
        if ($fds === false) {
            $fds = [];
            for ($i = 0; $i <= 1024; ++$i) {
                $copy = @\fopen('php://fd/' . $i, 'r');
                if ($copy !== false) {
                    $fds[] = $i;
                    \fclose($copy);
                }
            }
        }
        // @codeCoverageIgnoreEnd

        // launch process with default STDIO pipes, but inherit STDERR
        $pipes = [
            ['pipe', 'r'],
            ['pipe', 'w'],
            \defined('STDERR') ? \STDERR : \fopen('php://stderr', 'w')
        ];

        // do not inherit open FDs by explicitly overwriting existing FDs with dummy files.
        // Accessing /dev/null with null spec requires PHP 7.4+, older PHP versions may be restricted due to open_basedir, so let's reuse STDERR here.
        // additionally, close all dummy files in the child process again
        foreach ($fds as $fd) {
            if ($fd > 2) {
                $pipes[$fd] = \PHP_VERSION_ID >= 70400 ? ['null'] : $pipes[2];
                $command .= ' ' . $fd . '>&-';
            }
        }

        // default `sh` only accepts single-digit FDs, so run in bash if needed
        if ($fds && \max($fds) > 9) {
            $command = 'exec bash -c ' . \escapeshellarg($command);
        }

        $process = new Process($command, $cwd, null, $pipes);
        $process->start();

        return $process;
    }

    /**
     * @see https://github.com/clue/reactphp-sqlite
     */
    private function php(): string
    {
        $binary = 'php';
        if (\PHP_SAPI === 'cli' || \PHP_SAPI === 'cli-server') {
            // use same PHP_BINARY in CLI mode, but do not use same binary for CGI/FPM
            $binary = \PHP_BINARY;
        } else {
            // if this is the php-cgi binary, check if we can execute the php binary instead
            $candidate = \str_replace('-cgi', '', \PHP_BINARY);
            if ($candidate !== \PHP_BINARY && @\is_executable($candidate)) {
                $binary = $candidate;
            }
        }

        // if `php` is a symlink to the php binary, use the shorter `php` name
        // this is purely cosmetic feature for the process list
        if ($binary !== 'php' && \realpath($this->whichBinary('php')) === $binary) {
            $binary = 'php';
        }

        return $binary;
    }

    private function whichBinary(string $bin): string
    {
        foreach (\explode(\PATH_SEPARATOR, \getenv('PATH')) as $path) {
            if (@\is_executable($path . \DIRECTORY_SEPARATOR . $bin)) {
                return $path . \DIRECTORY_SEPARATOR . $bin;
            }
        }
        return '';
    }
}