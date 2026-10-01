<?php

declare(strict_types=1);

namespace ShieldLabs\Tests\Support;

/**
 * Runs `php -S` with a router script for integration tests.
 */
final class PhpServer
{
    /** @var resource|null */
    private $process;

    /** @var array<int, resource> */
    private array $pipes = [];

    public readonly string $url;
    private readonly string $logFile;

    /**
     * @param array<string, string> $env extra environment variables for the server
     * @param array<string, string> $ini ini settings; by default errors are displayed on stderr (the log), not in responses
     */
    public function __construct(string $router, array $env = [], array $ini = [])
    {
        $port = self::freePort();
        $this->url = 'http://127.0.0.1:' . $port;
        $this->logFile = tempnam(sys_get_temp_dir(), 'shieldlabs-server-') ?: sys_get_temp_dir() . '/shieldlabs-server.log';
        $environment = array_merge(self::currentEnvironment(), $env);
        $command = [\PHP_BINARY];
        foreach ($ini + ['display_errors' => 'stderr'] as $name => $value) {
            $command[] = '-d';
            $command[] = $name . '=' . $value;
        }
        array_push($command, '-S', '127.0.0.1:' . $port, $router);
        $process = proc_open(
            $command,
            [0 => ['pipe', 'r'], 1 => ['file', $this->logFile, 'a'], 2 => ['file', $this->logFile, 'a']],
            $this->pipes,
            \dirname($router),
            $environment,
        );
        if ($process === false) {
            throw new \RuntimeException('Could not start php -S');
        }
        $this->process = $process;
        $deadline = microtime(true) + 10;
        while (microtime(true) < $deadline) {
            $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
            if ($socket !== false) {
                fclose($socket);

                return;
            }
            usleep(50_000);
        }
        $this->stop();

        throw new \RuntimeException('php -S did not start: ' . $this->log());
    }

    public function log(): string
    {
        return (string) @file_get_contents($this->logFile);
    }

    public function stop(): void
    {
        if ($this->process !== null) {
            foreach ($this->pipes as $pipe) {
                if (\is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            proc_terminate($this->process);
            proc_close($this->process);
            $this->process = null;
            @unlink($this->logFile);
        }
    }

    public function __destruct()
    {
        $this->stop();
    }

    private static function freePort(): int
    {
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($server === false) {
            throw new \RuntimeException('No free port: ' . $errstr);
        }
        $name = (string) stream_socket_get_name($server, false);
        fclose($server);

        return (int) substr($name, (int) strrpos($name, ':') + 1);
    }

    /**
     * @return array<string, string>
     */
    private static function currentEnvironment(): array
    {
        $environment = [];
        foreach (getenv() as $name => $value) {
            if (!str_starts_with((string) $name, 'SHIELDLABS_')) {
                $environment[(string) $name] = (string) $value;
            }
        }

        return $environment;
    }
}
