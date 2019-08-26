<?php declare(strict_types=1);

namespace Gos\Component\ReactAMQP\Tests;

use Gos\Component\ReactAMQP\Consumer;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;

/**
 * @author Jeremy Cook <jeremycook0@gmail.com>
 *
 * @requires extension amqp
 */
final class ConsumerTest extends TestCase
{
    /**
     * @var \AMQPQueue|MockObject
     */
    private $queue;

    /**
     * @var LoopInterface|MockObject
     */
    private $loop;

    /**
     * Counter to test the number of invokations of an observed object.
     *
     * @var int
     */
    private $counter = 0;

    /**
     * @var TimerInterface
     */
    private $timer;

    protected function setUp(): void
    {
        $this->queue = $this->createMock(\AMQPQueue::class);
        $this->loop = $this->createMock(LoopInterface::class);
        $this->timer = $this->createMock(TimerInterface::class);

        $this->loop->expects($this->any())
            ->method('addPeriodicTimer')
            ->willReturn($this->timer);
    }

    protected function tearDown(): void
    {
        $this->counter = 0;
    }

    /**
     * Allows the test class to be used as a callback by the consumer.
     *
     * Simply counts the number of times the invoke method is called.
     */
    public function __invoke(): void
    {
        ++$this->counter;
    }

    /**
     * @dataProvider intervalMaxSupplier
     */
    public function testConsumerIsInstantiatedAndRegisteredToTheLoop(float $interval, ?int $max): void
    {
        $this->loop->expects($this->once())
            ->method('addPeriodicTimer')
            ->with($this->identicalTo($interval), $this->isInstanceOf(Consumer::class));

        new Consumer($this->queue, $this->loop, $interval, $max);
    }

    /**
     * Basic test case that asserts that messages can be consumed from the queue.
     */
    public function testConsumingMessages(): void
    {
        $this->queue->expects($this->exactly(4))
            ->method('get')
            ->willReturnOnConsecutiveCalls('foo', 'bar', 'baz', false);

        $consumer = new Consumer($this->queue, $this->loop, 1.0);
        $consumer->on('consume', $this);
        $consumer();

        $this->assertSame(3, $this->counter);
    }

    /**
     * Asserts that supplying a value for the max number of messages to consume results in the Consumer returning.
     *
     * @dataProvider maxSupplier
     */
    public function testConsumingMessagesWithMaxCount(int $max): void
    {
        $this->queue->expects($this->exactly($max))
            ->method('get')
            ->willReturn('foobar');

        $consumer = new Consumer($this->queue, $this->loop, 1.0, $max);
        $consumer->on('consume', $this);
        $consumer();

        $this->assertSame($max, $this->counter);
    }

    /**
     * @dataProvider callSupplier
     */
    public function testTheConsumerProxiesMethodCalls($method, ...$args): void
    {
        $this->queue->expects($this->once())
            ->method($method)
            ->with($this->identicalTo(...$args));

        $consumer = new Consumer($this->queue, $this->loop, 1.0);
        $consumer->$method(...$args);
    }

    public function testClose(): void
    {
        $consumer = new Consumer($this->queue, $this->loop, 1.0);
        $consumer->on('end', $this);

        $this->loop->expects($this->once())
            ->method('cancelTimer')
            ->with($this->equalTo($this->timer));

        $consumer->close();

        $this->assertTrue($consumer->isClosed());
        $this->assertSame(1, $this->counter);
    }

    public function testInvokingConsumerAfterClosingIsNotAllowed(): void
    {
        $this->expectException(\BadMethodCallException::class);

        $consumer = new Consumer($this->queue, $this->loop, 1.0);
        $consumer->close();
        $consumer();
    }

    public function intervalMaxSupplier(): \Generator
    {
        yield [1, null];
        yield [1, 1];
        yield [0.05, 10];
    }

    public function maxSupplier(): \Generator
    {
        yield [1];
        yield [10];
        yield [45];
    }

    public function callSupplier(): \Generator
    {
        yield ['getArgument', 'foo'];
        yield ['nack', 'bar'];
        yield ['cancel', 'baz'];
    }
}
