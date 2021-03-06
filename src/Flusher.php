<?php

declare(strict_types=1);

/*
 * (c) Jeroen van den Enden <info@endroid.nl>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Endroid\Flusher;

use Doctrine\ORM\EntityManagerInterface;
use Endroid\Flusher\Exception\PendingFlushesException;
use Symfony\Component\Stopwatch\Stopwatch;

class Flusher
{
    /** @var EntityManagerInterface */
    private $manager;

    /** @var float */
    private $stepSize;

    /** @var int */
    private $batchSize = 1;

    /** @var array<float> */
    private $ratios = [];

    /** @var bool */
    private $hasPendingFlushes = false;

    public function __construct(EntityManagerInterface $manager, float $stepSize = 1.5)
    {
        $this->manager = $manager;
        $this->stepSize = $stepSize;
    }

    public function getManager(): EntityManagerInterface
    {
        return $this->manager;
    }

    public function flush(): void
    {
        $count = count($this->manager->getUnitOfWork()->getScheduledEntityInsertions()) + $this->manager->getUnitOfWork()->size();

        // Only flush upon latest of the current batch
        if ($count < $this->batchSize) {
            $this->hasPendingFlushes = true;

            return;
        }

        $stopwatch = new Stopwatch();
        $stopwatch->start('flush');

        $this->manager->flush();
        $this->manager->clear();

        $event = $stopwatch->stop('flush');

        $this->hasPendingFlushes = false;

        $this->updateBatchSize($count, (int) $event->getPeriods()[0]->getDuration());
    }

    public function finish(): void
    {
        $this->manager->flush();

        $this->hasPendingFlushes = false;
    }

    protected function updateBatchSize(int $count, int $duration): void
    {
        $ratio = $duration / $count;

        $this->ratios[$this->batchSize] = $ratio;

        /** @var int $minBatchSize */
        $minBatchSize = array_search(min($this->ratios), $this->ratios);

        $this->batchSize = $minBatchSize;

        // Best batch size is the maximum batch size: try a higher value
        if ($this->batchSize == max(array_keys($this->ratios))) {
            $this->increaseBatchSize();
        }
    }

    protected function increaseBatchSize(): void
    {
        $this->batchSize = (int) ceil($this->batchSize * $this->stepSize);
    }

    public function __destruct()
    {
        if ($this->hasPendingFlushes) {
            throw new PendingFlushesException('Please call finish() to ensure all flushes are executed');
        }
    }
}
