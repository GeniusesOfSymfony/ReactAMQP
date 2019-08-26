<?php declare(strict_types=1);

namespace Gos\Component\ReactAMQP;

use Evenement\EventEmitter;
use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;

/**
 * Class to publish messages to an AMQP exchange.
 *
 * @author  Jeremy Cook <jeremycook0@gmail.com>
 */
final class Producer extends EventEmitter implements \Countable, \IteratorAggregate
{
    /**
     * @var \AMQPExchange
     */
    private $exchange;

    /**
     * @var LoopInterface
     */
    private $loop;

    /**
     * @var bool
     */
    private $closed = false;

    /**
     * @var array
     */
    private $messages = [];

    /**
     * @var TimerInterface
     */
    private $timer;

    public function __construct(\AMQPExchange $exchange, LoopInterface $loop, float $interval)
    {
        $this->exchange = $exchange;
        $this->loop = $loop;
        $this->timer = $this->loop->addPeriodicTimer($interval, $this);
    }

    public function count(): int
    {
        return \count($this->messages);
    }

    public function getIterator(): array
    {
        return $this->messages;
    }

    /**
     * Publishes a message to an AMQP exchange.
     *
     * Has the same method signature as the exchange object's publish method.
     *
     * @throws \BadMethodCallException if the producer connection has been closed
     */
    public function publish(string $message, string $routingKey, int $flags = 0 /* AMQP_NOPARAM */, array $attributes = []): void
    {
        if ($this->closed) {
            throw new \BadMethodCallException('This Producer object is closed and cannot send any more messages.');
        }

        $this->messages[] = [
            'message' => $message,
            'routingKey' => $routingKey,
            'flags' => $flags,
            'attributes' => $attributes,
        ];
    }

    /**
     * Handles publishing outgoing messages.
     *
     * @throws \AMQPChannelException
     * @throws \AMQPConnectionException
     * @throws \BadMethodCallException  if the consumer connection has been closed
     */
    public function __invoke(): void
    {
        if ($this->closed) {
            throw new \BadMethodCallException('This Producer object is closed and cannot send any more messages.');
        }

        foreach ($this->messages as $key => $message) {
            try {
                $this->exchange->publish($message['message'], $message['routingKey'], $message['flags'], $message['attributes']);
                unset($this->messages[$key]);
                $this->emit('produce', array_values($message));
            } catch (\AMQPExchangeException $e) {
                $this->emit('error', [$e]);
            }
        }
    }

    /**
     * Allows calls to unknown methods to be passed through to the exchange store.
     *
     * @return mixed
     */
    public function __call($method, $args)
    {
        return \call_user_func_array([$this->exchange, $method], $args);
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        $this->emit('end', [$this]);
        $this->loop->cancelTimer($this->timer);
        $this->removeAllListeners();
        $this->exchange = null;
        $this->closed = true;
    }

    public function isClosed(): bool
    {
        return true === $this->closed;
    }
}
