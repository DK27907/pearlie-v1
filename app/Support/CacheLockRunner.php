<?php

namespace App\Support;

use Closure;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockTimeoutException;

class CacheLockRunner
{
    public static function run(Lock $lock, int $timeoutSeconds, Closure $callback): mixed
    {
        $deadline = hrtime();
        $deadline[0] += $timeoutSeconds;
        $firstAttempt = true;

        while (true) {
            if (! $firstAttempt && self::deadlineReached($deadline)) {
                throw new LockTimeoutException;
            }

            if ($lock->get()) {
                break;
            }

            if (self::deadlineReached($deadline)) {
                throw new LockTimeoutException;
            }

            $firstAttempt = false;
            usleep(250_000);
        }

        try {
            return $callback();
        } finally {
            $lock->release();
        }
    }

    /**
     * @param  array{0: int, 1: int}  $deadline
     */
    private static function deadlineReached(array $deadline): bool
    {
        $now = hrtime();

        return $now[0] > $deadline[0]
            || ($now[0] === $deadline[0] && $now[1] >= $deadline[1]);
    }
}
