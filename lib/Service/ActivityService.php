<?php

declare(strict_types=1);

namespace OCA\Crate\Service;

use OCA\Crate\AppInfo\Application;
use OCA\Crate\Db\MediaItem;
use OCP\Activity\IManager as IActivityManager;
use Psr\Log\LoggerInterface;

class ActivityService
{
    public function __construct(
        private readonly IActivityManager $activityManager,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function itemCreated(MediaItem $item, string $userId): void
    {
        $this->publish('item_created', $item, $userId);
    }

    public function itemUpdated(MediaItem $item, string $userId): void
    {
        $this->publish('item_updated', $item, $userId);
    }

    public function itemDeleted(MediaItem $item, string $userId): void
    {
        $this->publish('item_deleted', $item, $userId);
    }

    public function itemEnriched(MediaItem $item, string $userId): void
    {
        $this->publish('item_enriched', $item, $userId);
    }

    private function publish(string $subject, MediaItem $item, string $userId): void
    {
        try {
            $event = $this->activityManager->generateEvent();
            $event->setApp(Application::APP_ID)
                ->setType('crate')
                ->setAuthor($userId)
                ->setAffectedUser($userId)
                ->setTimestamp(time())
                ->setSubject($subject, [
                    'title' => $item->getTitle(),
                    'artist' => $item->getArtist(),
                    'category' => $item->getCategory(),
                ])
                ->setObject('media_item', $item->getId(), $item->getTitle());
            $this->activityManager->publish($event);
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to publish activity event: ' . $e->getMessage(), [
                'app' => Application::APP_ID,
                'exception' => $e,
            ]);
        }
    }
}
