<?php

namespace Amp\Parallel\Worker;

use Amp\CancelledException;

final class TaskCancelledException extends CancelledException implements TaskFailureThrowable
{
    private TaskFailureThrowable $failure;

    public function __construct(TaskFailureThrowable $exception)
    {
        parent::__construct($exception);
        $this->failure = $exception;
    }

    public function getOriginalClassName(): string
    {
        return $this->failure->getOriginalClassName();
    }

    public function getOriginalMessage(): string
    {
        return $this->failure->getOriginalMessage();
    }

    public function getOriginalCode(): string|int
    {
        return $this->failure->getOriginalCode();
    }

    public function getOriginalTrace(): array
    {
        return $this->failure->getOriginalTrace();
    }

    public function getOriginalTraceAsString(): string
    {
        return $this->failure->getOriginalTraceAsString();
    }
}
