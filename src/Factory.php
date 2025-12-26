<?php

declare(strict_types=1);

namespace Shado\React\Mailer;

use React\ChildProcess\Process;

class Factory
{
    public function __construct(
        /**
         * @var string|null Path to the PHP binary to use for spawning worker processes. If null, the same binary as the current process will be used.
         */
        private ?string $binary = null,

        /**
         * For testing purposes only: path to the worker script to use.
         * @internal
         */
        private ?string $workerScript = null,
    ) {
        $this->binary ??= $this->php();
        $this->workerScript ??= \dirname(__DIR__) . '/res/mailer-worker.php';
    }

    /**
     * Create a Mailer by the DSN string.
     * Example: `smtp://user:pass@smtp.example.com:25`
     * @param string $dsn The DSN string for the mailer transport; see Symfony Mailer documentation for supported transports and formats.
     * @throws MailerException
     * @see https://symfony.com/doc/current/mailer.html#using-built-in-transports
     */
    public function create(string $dsn): Mailer
    {
        $process = $this->spawnChildProcess();
        return new Mailer($dsn, $process);
    }

    /**
     * @see https://github.com/clue/reactphp-sqlite
     */
    private function spawnChildProcess(): Process
    {
        $cwd = null;

        // launch worker process directly or inside Phar by mapping relative paths
        // ported from clue/reactphp-sqlite, but simplified
        if (\class_exists(\Phar::class, false) && ($phar = \Phar::running(false)) !== '') {
            $inline = 'Phar::loadPhar(' . \var_export($phar, true) . ');require(' . \var_export($this->workerScript, true) . ');';
            $command = 'exec ' . \escapeshellarg($this->binary) . ' -r ' . \escapeshellarg($inline);
        } else {
            $cwd = __DIR__ . '/../res';
            $command = 'exec ' . \escapeshellarg($this->binary) . ' ' . \escapeshellarg(\basename($this->workerScript));
        }

        // Try to get list of all open FDs (Linux/Mac and others)
        $fds = @\scandir('/dev/fd');

        // Otherwise try temporarily duplicating file descriptors in the range 0-1024 (FD_SETSIZE).
        // This is known to work on more exotic platforms and also inside chroot
        // environments without /dev/fd. Causes many syscalls, but still rather fast.
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

        // Normalize FDs: scandir() returns strings plus "." and ".."
        $fdNums = [];
        foreach ($fds as $fd) {
            if (\is_int($fd)) {
                $fdNums[] = $fd;
                continue;
            }
            if (\is_string($fd) && $fd !== '' && \ctype_digit($fd)) {
                $fdNums[] = (int)$fd;
            }
        }
        $fdNums = \array_values(\array_unique($fdNums));

        // launch process with default STDIO pipes (stdin/stdout/stderr)
        // IMPORTANT: stderr MUST be a pipe because Mailer attaches listeners to $process->stderr
        $pipes = [
            ['pipe', 'r'], // stdin
            ['pipe', 'w'], // stdout
            ['pipe', 'w'], // stderr
        ];

        // do not inherit open FDs by explicitly overwriting existing FDs with dummy files.
        // Accessing /dev/null with null spec requires PHP 7.4+, older PHP versions may be restricted due to open_basedir.
        foreach ($fdNums as $fd) {
            if ($fd > 2) {
                $pipes[$fd] = (\PHP_VERSION_ID >= 70400) ? ['null'] : ['pipe', 'w'];
                $command .= ' ' . $fd . '>&-';
            }
        }

        // default `sh` only accepts single-digit FDs, so run in bash if needed
        if ($fdNums && \max($fdNums) > 9) {
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