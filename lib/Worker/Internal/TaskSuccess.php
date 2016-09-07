<?php declare(strict_types = 1);

namespace Amp\Parallel\Worker\Internal;

use Amp\Success;
use Interop\Async\Awaitable;

class TaskSuccess extends TaskResult {
    /** @var mixed Result of task. */
    private $result;
    
    public function __construct(string $id, $result) {
        parent::__construct($id);
        $this->result = $result;
    }
    
    public function getAwaitable(): Awaitable {
        return new Success($this->result);
    }
}
