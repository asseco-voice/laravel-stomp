<?php

namespace Asseco\Stomp\Queue\Contracts;

/**
 * Opt-in to Laravel's retry semantics on the STOMP driver.
 *
 * Implement it on a queued job class, or on the listener class of a queued listener. The job then gets its own
 * $tries / tries(), $backoff / backoff(), retryUntil() and $maxExceptions, counts attempts from 1, and is re-sent to
 * the queue it came from (with the backoff as a broker scheduled delay) when it throws or releases itself. When it
 * gives up, failed() runs and a failed_jobs row is written. If a re-send is not accepted by the broker, the job is
 * failed instead of lost.
 *
 * Jobs that do not implement it behave as before (auto_tries, auto_backoff and fail_job_requeue apply to them).
 */
interface RetriesOnStomp
{
}
