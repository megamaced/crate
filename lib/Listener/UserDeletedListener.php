<?php

declare(strict_types=1);

namespace OCA\Crate\Listener;

use OCA\Crate\Service\MediaService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\User\Events\UserDeletedEvent;
use Psr\Log\LoggerInterface;

/**
 * Clears a deleted account's Crate data. None of the app's tables carry a
 * foreign key to the accounts table, so nothing removes those rows on our
 * behalf: without this the items, playlists and files survive unreachable,
 * and the share rows stay live on both sides.
 *
 * @template-implements IEventListener<UserDeletedEvent>
 */
class UserDeletedListener implements IEventListener
{
    public function __construct(
        private readonly MediaService $mediaService,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function handle(Event $event): void
    {
        if (!($event instanceof UserDeletedEvent)) {
            return;
        }

        $userId = $event->getUser()->getUID();
        try {
            $this->mediaService->purgeUser($userId);
        } catch (\Throwable $e) {
            // The account is already gone by the time this fires, so failing
            // loudly would only break the admin's deletion; leave a record
            // an administrator can act on instead.
            $this->logger->error('Failed to purge Crate data for deleted user {user}: {msg}', [
                'user' => $userId,
                'msg'  => $e->getMessage(),
                'app'  => 'crate',
            ]);
        }
    }
}
