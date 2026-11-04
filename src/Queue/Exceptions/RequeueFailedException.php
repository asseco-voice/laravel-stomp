<?php

namespace Asseco\Stomp\Queue\Exceptions;

use RuntimeException;

/** The next attempt of a job that retries on STOMP could not be sent to the broker, so the job is failed. */
class RequeueFailedException extends RuntimeException
{
}
