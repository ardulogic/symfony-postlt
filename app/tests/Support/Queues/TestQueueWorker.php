<?php

namespace App\Tests\Support\Queues;

use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Worker;

/**
 * Note!
 * This has to be here, because otherwise it's also loaded on dev/prod
 * environments and kills the worker as soon as queue empties
 * Make sure this file is loaded only on test env!
 */
class TestQueueWorker
{

    public static function doQueuedJobs($c): void
    {
        $receiver   = $c->get('messenger.transport.async'); // InMemoryTransport
        $bus        = $c->get(MessageBusInterface::class);
        $logger     = $c->get(LoggerInterface::class);

        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber(new StopWhenEmptySubscriber());

        $worker = new Worker(['async' => $receiver], $bus, $dispatcher, $logger);
        $worker->run(['sleep' => 0]);
    }
}
