<?php

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Time and memory budget for a single request or cron tick.
 *
 * Every loop in the plugin is budget-driven rather than count-driven. A batch
 * size that works on one host times out on another, but "stop when 60% of the
 * available time is gone" is correct everywhere and needs no configuration.
 *
 * Usage:
 *
 *     $budget = Fsync_Budget::start();
 *     foreach ($items as $item) {
 *         process($item);
 *         $budget->tick();
 *         if ($budget->exhausted()) {
 *             break;   // caller returns its cursor and is called again
 *         }
 *     }
 */
final class Fsync_Budget
{
    /** Fraction of the execution limit we are willing to consume. */
    const TIME_FRACTION = 0.6;

    /** Fraction of the memory limit above which we stop taking on more work. */
    const MEMORY_FRACTION = 0.7;

    /** Batch size used before any measurement exists. */
    const INITIAL_BATCH = 25;

    const MIN_BATCH = 1;
    const MAX_BATCH = 500;

    /** @var float */
    private $started_at;

    /** @var float */
    private $deadline;

    /** @var int */
    private $memory_ceiling;

    /** @var int */
    private $processed = 0;

    /** @var string */
    private $stop_reason = '';

    /**
     * @param float|null $seconds Override the time slice; used by cron ticks
     *                            that want to stay well below the limit.
     * @return self
     */
    public static function start($seconds = null)
    {
        return new self($seconds);
    }

    /**
     * @param float|null $seconds
     */
    private function __construct($seconds = null)
    {
        $limit = Fsync_Env::execution_time() * self::TIME_FRACTION;
        if ($seconds !== null) {
            $limit = min($limit, (float) $seconds);
        }

        $this->started_at = microtime(true);
        $this->deadline = $this->started_at + max(1.0, $limit);

        $memory_limit = Fsync_Env::memory_limit();
        $this->memory_ceiling = $memory_limit === PHP_INT_MAX
            ? PHP_INT_MAX
            : (int) ($memory_limit * self::MEMORY_FRACTION);
    }

    /**
     * Record that one unit of work completed.
     *
     * @param int $count
     * @return void
     */
    public function tick($count = 1)
    {
        $this->processed += (int) $count;
    }

    /**
     * Whether the caller should stop and hand its cursor back.
     *
     * @return bool
     */
    public function exhausted()
    {
        if (microtime(true) >= $this->deadline) {
            $this->stop_reason = 'time';

            return true;
        }

        if ($this->memory_ceiling !== PHP_INT_MAX && memory_get_usage(true) >= $this->memory_ceiling) {
            $this->stop_reason = 'memory';

            return true;
        }

        return false;
    }

    /**
     * @return float Seconds consumed so far.
     */
    public function elapsed()
    {
        return microtime(true) - $this->started_at;
    }

    /**
     * @return float Seconds still available.
     */
    public function remaining()
    {
        return max(0.0, $this->deadline - microtime(true));
    }

    /**
     * @return int
     */
    public function processed()
    {
        return $this->processed;
    }

    /**
     * @return string One of "time", "memory" or "" when not exhausted.
     */
    public function stop_reason()
    {
        return $this->stop_reason;
    }

    /**
     * Batch size to suggest to the client for the next request.
     *
     * Converges on whatever the observed per-item cost supports, so a fast host
     * ramps up and a slow one backs off without either being configured. The
     * result is clamped and only allowed to double per round so that a single
     * unusually cheap batch cannot overshoot into a timeout.
     *
     * @param int $current_batch
     * @return int
     */
    public function suggest_batch($current_batch)
    {
        $current_batch = max(self::MIN_BATCH, (int) $current_batch);

        if ($this->processed < 1) {
            return $current_batch;
        }

        $per_item = $this->elapsed() / $this->processed;
        if ($per_item <= 0) {
            return min(self::MAX_BATCH, $current_batch * 2);
        }

        $window = Fsync_Env::execution_time() * self::TIME_FRACTION;
        $ideal = (int) floor($window / $per_item);

        $ideal = min($ideal, $current_batch * 2);
        $ideal = max($ideal, (int) floor($current_batch / 2));

        return max(self::MIN_BATCH, min(self::MAX_BATCH, $ideal));
    }

    /**
     * Shape returned by every resumable endpoint, so clients have one contract.
     *
     * @param bool $done
     * @param mixed $cursor
     * @param int $current_batch
     * @param array $extra
     * @return array
     */
    public function response($done, $cursor, $current_batch, $extra = array())
    {
        return array_merge(
            array(
                'done' => (bool) $done,
                'cursor' => $cursor,
                'processed' => $this->processed,
                'elapsed' => round($this->elapsed(), 3),
                'stop_reason' => $this->stop_reason,
                'suggested_batch' => $this->suggest_batch($current_batch),
            ),
            $extra
        );
    }
}
