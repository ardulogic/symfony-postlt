<?php

namespace App\Shared\Tests\Support;

use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Worker;

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
