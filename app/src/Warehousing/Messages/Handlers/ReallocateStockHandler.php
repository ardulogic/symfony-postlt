<?php

namespace App\Warehousing\Messages\Handlers;

use App\Warehousing\Messages\ReallocateStockJob;
use App\Warehousing\Repository\StockReservationRepository;
use App\Warehousing\Repository\StockItemRepository;
use App\Warehousing\Service\StockReservationService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class ReallocateStockHandler
{
    public function __construct(
        private EntityManagerInterface     $em,
        private LockFactory                $locks,
        private StockItemRepository        $stockRepo,
        private StockReservationRepository $resRepo,
        private StockReservationService    $stockService,
        #[Autowire(service: 'monolog.logger.allocation')]
        private LoggerInterface            $logger,

    )
    {
    }

    public function __invoke(ReallocateStockJob $msg): void
    {
        // Prevent parallel reallocation bursts stepping on each other
        $lockKey = sprintf('reallocate:%s', $msg->id);
        $lock = $this->locks->createLock($lockKey, ttl: 30.0);

        if (!$lock->acquire()) {
            $this->logger?->debug('Reallocation lock busy; skipping run', ['lock' => $lockKey]);
            return;
        }

        try {
            $this->runOnce($msg);
        } finally {
            $lock->release();
        }
    }

    private function runOnce(ReallocateStockJob $msg): void
    {
        // Delegate to service (which wraps in a transaction and returns a VO)
        $result = $this->stockService->reallocate($msg->affectedSkus);

        $this->logger->debug('Stock Re-Allocation', $result->toArray());

    }

}
