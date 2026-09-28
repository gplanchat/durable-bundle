<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Bundle\Handler;

use Gplanchat\Durable\Exception\ActivityAttemptDeferred;
use Gplanchat\Durable\Transport\ActivityMessage;
use Gplanchat\Durable\Worker\ActivityMessageProcessor;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

/**
 * Consumes {@see ActivityMessage} through Symfony Messenger (configured activities transport).
 */
final class ActivityRunHandler
{
    public function __construct(
        private readonly ActivityMessageProcessor $activityMessageProcessor,
    ) {}

    public function __invoke(ActivityMessage $message): void
    {
        // The core decided this failure is not worth another attempt, and journalled it: Messenger
        // must not retry it either, and its failure transport is where an operator looks (#341).
        // A retry from there is answered by the journal, not run again.
        try {
            $failure = $this->activityMessageProcessor->process($message);
        } catch (ActivityAttemptDeferred $deferred) {
            // Another worker holds the attempt: Messenger retries a recoverable failure whatever
            // `max_retries` says, so the copy runs once the holder's claim is gone (#590). The delay
            // argument arrived after 6.4, whose constructor refuses a fourth argument: there, the
            // transport's retry strategy spaces the retries.
            throw (new \ReflectionClass(RecoverableMessageHandlingException::class))->hasMethod('getRetryDelay')
                ? new RecoverableMessageHandlingException($deferred->getMessage(), 0, $deferred, ActivityAttemptDeferred::RETRY_AFTER_SECONDS * 1000)
                : new RecoverableMessageHandlingException($deferred->getMessage(), 0, $deferred);
        }
        if (null !== $failure) {
            throw new UnrecoverableMessageHandlingException($failure->getMessage(), 0, $failure);
        }
    }
}
