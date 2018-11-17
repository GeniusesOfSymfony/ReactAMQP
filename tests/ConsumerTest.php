<?php declare(strict_types=1);

namespace Gos\Component\ReactAMQP\Tests;

use Gos\Component\ReactAMQP\Consumer;
use PHPUnit\Framework\TestCase;
use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;

/**
 * Test case for the consumer class.
 *
 * @author  Jeremy Cook <jeremycook0@gmail.com>
 */
class ConsumerTest extends TestCase
{
    /**
     * Mock queue object.
     *
     * @var \AMQPQueue
     */
    protected $queue;

    /**
     * Mock loop object.
     *
     * @var LoopInterface
     */
    protected $loop;

    /**
     * Counter to test the number of invokations of an observed object.
     *
     * @var int
     */
    protected $counter = 0;

    /**
     * @var TimerInterface
     */
    protected $timer;

    /**
     * Bootstrap the test case.
     */
    protected function setUp(): void
    {
        $this->queue = $this->getMockBuilder(\AMQPQueue::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->loop = $this->getMockBuilder(LoopInterface::class)
            ->getMock();

        $this->timer = $this->getMockBuilder(TimerInterface::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->loop->expects($this->any())->method('addPeriodicTimer')
            ->will($this->returnValue($this->timer));
    }

    /**
     * Tear down resets the counter after each test method has run.
     */
    protected function tearDown(): void
    {
        $this->counter = 0;
    }

    /**
     * Allows the test class to be used as a callback by the consumer. Simply
     * counts the number of times the invoke method is called.
     */
    public function __invoke(): void
    {
        ++$this->counter;
    }

    /**
     * Tests the constructor for the consumer class.
     *
     * @param float|null $interval Interval for the loop
     * @param int|null $max      Max number of messages to consume
     *
     * @dataProvider IntervalMaxSupplier
     */
    public function test__construct(?float $interval, ?int $max): void
    {
        $this->loop->expects($this->once())
            ->method('addPeriodicTimer')
            ->with($this->identicalTo($interval), $this->isInstanceOf(Consumer::class));
        $consumer = new Consumer($this->queue, $this->loop, $interval, $max);
        $this->assertAttributeSame($this->queue, 'queue', $consumer);
        $this->assertAttributeSame($this->loop, 'loop', $consumer);
        $this->assertAttributeSame($max, 'max', $consumer);
    }

    /**
     * Basic test case that asserts that messages can be consumed from the
     * queue.
     * @throws \AMQPChannelException|\AMQPConnectionException
     */
    public function testConsumingMessages(): void
    {
        $this->queue->expects($this->exactly(4))
            ->method('get')
            ->will($this->onConsecutiveCalls('foo', 'bar', 'baz', false));
        $consumer = new Consumer($this->queue, $this->loop, 1.0);
        $consumer->on('consume', $this);
        $consumer();
        $this->assertSame(3, $this->counter);
    }

    /**
     * Asserts that supplying a value for the max number of messages to consume
     * results in the Consumer returning.
     *
     * @param int $max
     *
     * @throws \AMQPChannelException|\AMQPConnectionException
     * 
     * @dataProvider MaxSupplier
     */
    public function testConsumingMessagesWithMaxCount($max): void
    {
        $this->queue->expects($this->exactly($max))
            ->method('get')
            ->will($this->returnValue('foobar'));
        $consumer = new Consumer($this->queue, $this->loop, 1.0, $max);
        $consumer->on('consume', $this);
        $consumer();
        $this->assertSame($max, $this->counter);
    }

    /**
     * Asserts that calling unknown methods on the consumer object results in
     * these being passed through to the internal queue object.
     *
     * @param string $method Method name
     * @param string $arg    Argument to pass
     *
     * @dataProvider CallSupplier
     */
    public function test__call($method, $arg): void
    {
        $this->queue->expects($this->once())
            ->method($method)
            ->with($this->identicalTo($arg));
        $consumer = new Consumer($this->queue, $this->loop, 1.0);
        $consumer->$method($arg);
    }

    /**
     * Tests the close method of the consumer.
     */
    public function testClose(): void
    {
        $consumer = new Consumer($this->queue, $this->loop, 1.0);
        $consumer->on('end', $this);
        $this->loop->expects($this->once())
            ->method('cancelTimer')
            ->with($this->equalTo($this->timer));
        $consumer->close();
        $this->assertAttributeSame(true, 'closed', $consumer);
        $this->assertAttributeSame(null, 'queue', $consumer);
        $this->assertSame(1, $this->counter);
    }

    /**
     * Asserts that an exception is thrown when trying to invoke a consumer
     * after closing it.
     *
     * @depends testClose
     * @expectedException \BadMethodCallException
     * @throws \AMQPChannelException|\AMQPConnectionException
     */
    public function testInvokingConsumerAfterClosing(): void
    {
        $consumer = new Consumer($this->queue, $this->loop, 1.0);
        $consumer->close();
        $consumer();
    }

    /**
     * Data provider with interval and max iteration values.
     *
     * @return array
     */
    public static function IntervalMaxSupplier(): array 
    {
        return [
            [1, null],
            [1, 1],
            [0.05, 10],
        ];
    }

    /**
     * Data provider with values for the max number of messages to consume.
     *
     * @return array
     */
    public static function MaxSupplier(): array 
    {
        return [
            [1],
            [10],
            [45],
        ];
    }

    /**
     * Data provider with arguments for the test that tests the consumers
     * __call method.
     *
     * @return array
     */
    public static function CallSupplier(): array 
    {
        return [
            ['getArgument', 'foo'],
            ['nack', 'bar'],
            ['cancel', 'baz'],
        ];
    }
}
