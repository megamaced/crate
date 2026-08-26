<?php

declare(strict_types=1);

namespace OCA\Crate\Tests\Unit;

use OCA\Crate\Controller\HomeController;
use OCA\Crate\Db\MediaItem;
use OCA\Crate\Db\MediaItemMapper;
use OCA\Crate\Service\CategoryVisibilityService;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

/**
 * The home feed's `categories` is a map keyed by category and clients are
 * typed against that shape. PHP has a single array type, so an empty map
 * encodes as a JSON array unless it is made an object on the way out — and the
 * only user who ever sees an empty map is one with nothing owned yet, which is
 * the worst moment for a client to fail to parse the response.
 */
#[AllowMockObjectsWithoutExpectations]
class HomeFeedTest extends TestCase
{
    public function testEmptyFeedStillEncodesCategoriesAsAnObject(): void
    {
        $json = $this->encodedFeed();

        self::assertStringContainsString('"categories":{}', $json);
    }

    public function testPopulatedFeedKeepsCategoriesKeyedByCategory(): void
    {
        $json = $this->encodedFeed($this->item(1, 'music'), $this->item(2, 'game'));

        self::assertStringContainsString('"categories":{"music":{', $json);
        self::assertStringContainsString('"game":{', $json);
    }

    /** JSON of the home response, as a client receives it. */
    private function encodedFeed(MediaItem ...$owned): string
    {
        $mapper = $this->createMock(MediaItemMapper::class);
        $mapper->method('findAll')->willReturn($owned);

        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('alice');
        $session = $this->createMock(IUserSession::class);
        $session->method('getUser')->willReturn($user);

        $visibility = $this->createMock(CategoryVisibilityService::class);
        $visibility->method('hidden')->willReturn([]);

        $controller = new HomeController(
            'crate',
            $this->createMock(IRequest::class),
            $mapper,
            $session,
            $visibility,
        );

        return (string) json_encode($controller->home()->getData());
    }

    private function item(int $id, string $category): MediaItem
    {
        $item = new MediaItem();
        $item->setId($id);
        $item->setUserId('alice');
        $item->setTitle('Title ' . $id);
        $item->setCategory($category);
        $item->setStatus('owned');
        return $item;
    }
}
