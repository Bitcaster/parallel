<?php declare(strict_types = 1);

namespace Amp\Parallel\Worker;

use Interop\Async\Loop;

class BasicEnvironment implements Environment {
    /**
     * @var array
     */
    private $data = [];

    /**
     * @var array
     */
    private $ttl = [];

    /**
     * @var array
     */
    private $expire = [];

    /**
     * @var \SplPriorityQueue
     */
    private $queue;

    /**
     * @var string
     */
    private $timer;

    public function __construct() {
        $this->queue = new \SplPriorityQueue;

        $this->timer = Loop::repeat(1000, function () {
            $time = \time();
            while (!$this->queue->isEmpty()) {
                $key = $this->queue->top();

                if (isset($this->expire[$key])) {
                    if ($time <= $this->expire[$key]) {
                        break;
                    }

                    unset($this->data[$key], $this->expire[$key], $this->ttl[$key]);
                }

                $this->queue->extract();
            }

            if ($this->queue->isEmpty()) {
                Loop::disable($this->timer);
            }
        });
    
        Loop::disable($this->timer);
        Loop::unreference($this->timer);
    }

    /**
     * @param string $key
     *
     * @return bool
     */
    public function exists(string $key): bool {
        return isset($this->data[$key]);
    }

    /**
     * @param string $key
     *
     * @return mixed|null Returns null if the key does not exist.
     */
    public function get(string $key) {
        if (isset($this->ttl[$key]) && 0 !== $this->ttl[$key]) {
            $this->expire[$key] = time() + $this->ttl[$key];
            $this->queue->insert($key, -$this->expire[$key]);
        }

        return isset($this->data[$key]) ? $this->data[$key] : null;
    }
    
    /**
     * @param string $key
     * @param mixed $value Using null for the value deletes the key.
     * @param int $ttl Number of seconds until data is automatically deleted. Use 0 for unlimited TTL.
     */
    public function set(string $key, $value, int $ttl = 0) {
        if (null === $value) {
            $this->delete($key);
            return;
        }

        $ttl = (int) $ttl;
        if (0 > $ttl) {
            $ttl = 0;
        }

        if (0 !== $ttl) {
            $this->ttl[$key] = $ttl;
            $this->expire[$key] = time() + $ttl;
            $this->queue->insert($key, -$this->expire[$key]);

            Loop::enable($this->timer);
        } else {
            unset($this->expire[$key], $this->ttl[$key]);
        }

        $this->data[$key] = $value;
    }

    /**
     * @param string $key
     */
    public function delete(string $key) {
        $key = (string) $key;
        unset($this->data[$key], $this->expire[$key], $this->ttl[$key]);
    }

    /**
     * Alias of exists().
     *
     * @param $key
     *
     * @return bool
     */
    public function offsetExists($key) {
        return $this->exists($key);
    }

    /**
     * Alias of get().
     *
     * @param string $key
     *
     * @return mixed
     */
    public function offsetGet($key) {
        return $this->get($key);
    }

    /**
     * Alias of set() with $ttl = 0.
     *
     * @param string $key
     * @param mixed $value
     */
    public function offsetSet($key, $value) {
        $this->set($key, $value);
    }

    /**
     * Alias of delete().
     *
     * @param string $key
     */
    public function offsetUnset($key) {
        $this->delete($key);
    }

    /**
     * @return int
     */
    public function count(): int {
        return count($this->data);
    }

    /**
     * Removes all values.
     */
    public function clear() {
        $this->data = [];
        $this->expire = [];
        $this->ttl = [];

        Loop::disable($this->timer);
        $this->queue = new \SplPriorityQueue;
    }
}
