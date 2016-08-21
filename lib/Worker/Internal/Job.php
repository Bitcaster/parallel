<?php

namespace Amp\Concurrent\Worker\Internal;

use Amp\Concurrent\Worker\Task;

class Job {
    /** @var string */
    private $id;
    
    /** @var \Amp\Concurrent\Worker\Task */
    private $task;
    
    public function __construct(Task $task) {
        $this->task = $task;
        $this->id = \spl_object_hash($this->task);
    }
    
    public function getId(): string {
        return $this->id;
    }
    
    public function getTask(): Task {
        return $this->task;
    }
}
