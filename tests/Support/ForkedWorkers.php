<?php

declare(strict_types=1);

namespace rajmundtoth0\HybridCache\Tests\Support;

use Closure;
use RuntimeException;
use Throwable;

/**
 * Runs a callback in several forked processes at the same time, the way PHP-FPM
 * workers on one or more nodes would. Each child inherits the booted application but
 * opens its own Redis connection, and keeps its own copy of any array store, so every
 * child also behaves as an independent node-local cache.
 *
 * The children start together (a start barrier, not a correctness delay) to maximise
 * overlap. The assertions run in the parent, against the shared server state.
 */
final class ForkedWorkers
{
    /**
     * @param Closure(int): void $work receives the worker index
     * @return list<string> errors reported by the children
     */
    public static function run(int $workers, Closure $work): array
    {
        if (! function_exists('pcntl_fork')) {
            throw new RuntimeException('pcntl is required to run forked workers.');
        }

        // Never share a live socket with the children.
        app('redis')->purge();

        $errorDir = sys_get_temp_dir().'/hybrid-cache-workers-'.bin2hex(random_bytes(6));
        mkdir($errorDir);
        $startAt = microtime(true) + 0.2;
        $pids = [];

        for ($worker = 0; $worker < $workers; $worker++) {
            $pid = pcntl_fork();

            if ($pid === -1) {
                throw new RuntimeException('Could not fork a worker.');
            }

            if ($pid === 0) {
                self::child($worker, $work, $startAt, $errorDir);
            }

            $pids[] = $pid;
        }

        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
        }

        app('redis')->purge();

        $errors = [];

        foreach (glob($errorDir.'/*') ?: [] as $file) {
            $errors[] = (string) file_get_contents($file);
            unlink($file);
        }

        rmdir($errorDir);

        return $errors;
    }

    /**
     * @param Closure(int): void $work
     */
    private static function child(int $worker, Closure $work, float $startAt, string $errorDir): never
    {
        try {
            while (microtime(true) < $startAt) {
                usleep(1_000);
            }

            $work($worker);
        } catch (Throwable $error) {
            file_put_contents($errorDir.'/'.$worker, $error::class.': '.$error->getMessage().' @ '.$error->getFile().':'.$error->getLine());
        }

        // Leave immediately: running PHPUnit's shutdown handlers in a child would
        // report the test a second time.
        posix_kill(posix_getpid(), SIGKILL);

        throw new RuntimeException('unreachable');
    }
}
