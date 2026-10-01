<?php

declare(strict_types=1);

namespace ShieldLabs\Tests\Support;

use ShieldLabs\Internal\Warning;

/**
 * What error_log() wrote while a callback ran.
 */
final class ErrorLog
{
    private function __construct(public readonly string $contents) {}

    /**
     * Runs $callback with error_log() output sent to a temporary file, after making the
     * SDK forget the warnings it already logged. Call it inside the test method:
     * PHPUnit 11 and later redirect error_log() themselves while the method runs.
     *
     * @param \Closure(): void $callback
     */
    public static function during(\Closure $callback): self
    {
        $file = tempnam(sys_get_temp_dir(), 'shieldlabs-log-');
        if ($file === false) {
            throw new \RuntimeException('Cannot create a temporary log file');
        }
        $previous = ini_set('error_log', $file);
        Warning::reset();
        try {
            $callback();
        } finally {
            ini_set('error_log', $previous === false ? '' : $previous);
            $contents = (string) file_get_contents($file);
            @unlink($file);
        }

        return new self($contents);
    }

    /**
     * The SDK warnings, without their prefix.
     *
     * @return list<string>
     */
    public function warnings(): array
    {
        $warnings = [];
        foreach (explode("\n", $this->contents) as $line) {
            $position = strpos($line, Warning::PREFIX);
            if ($position !== false) {
                $warnings[] = substr($line, $position + \strlen(Warning::PREFIX));
            }
        }

        return $warnings;
    }
}
