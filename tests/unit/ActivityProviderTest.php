<?php

declare(strict_types=1);

namespace OCA\Crate\Tests\Unit;

use OCA\Crate\Activity\Provider;
use OCP\Activity\Exceptions\UnknownActivityException;
use OCP\Activity\IEvent;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use PHPUnit\Framework\TestCase;

/**
 * The activity entries are the only place outside unified search that hands out
 * SPA deep links, so these tests pin the link format the Activity app renders.
 */
class ActivityProviderTest extends TestCase
{
    private const APP_URL = 'https://cloud.example/index.php/apps/crate/';

    /** @var array<string, mixed> */
    private array $richParameters = [];

    private string $eventLink = '';

    public function testItemCreatedLinksToTheItemDetailView(): void
    {
        $this->parseEvent('item_created', 42, ['title' => 'OK Computer', 'artist' => 'Radiohead', 'category' => 'music']);

        self::assertSame('highlight', $this->richParameters['item']['type']);
        self::assertSame('Radiohead – OK Computer', $this->richParameters['item']['name']);
        self::assertSame(self::APP_URL . '#/detail/42', $this->richParameters['item']['link']);
        self::assertSame(self::APP_URL . '#/detail/42', $this->eventLink);
    }

    public function testDeletedItemLinksToItsCategoryRatherThanAMissingDetailPage(): void
    {
        $this->parseEvent('item_deleted', 7, ['title' => 'Alien', 'artist' => 'Ridley Scott', 'category' => 'film']);

        // The SPA's category routes are plural; the stored category is not.
        self::assertSame(self::APP_URL . '#/films', $this->richParameters['item']['link']);
        self::assertSame(self::APP_URL . '#/films', $this->eventLink);
    }

    public function testUnknownCategoryFallsBackToTheAppRoot(): void
    {
        $this->parseEvent('item_deleted', 7, ['title' => 'Mystery', 'artist' => '', 'category' => 'vinyl']);

        self::assertSame(self::APP_URL, $this->richParameters['item']['link']);
    }

    public function testUnknownSubjectIsRejected(): void
    {
        $this->expectException(UnknownActivityException::class);
        $this->parseEvent('item_teleported', 1, ['title' => 'Nope', 'artist' => '', 'category' => 'music']);
    }

    /**
     * @param array<string, string> $subjectParameters
     */
    private function parseEvent(string $subject, int $objectId, array $subjectParameters): void
    {
        $l = $this->createStub(IL10N::class);
        $l->method('t')->willReturnCallback(
            static fn (string $text, $parameters = []): string => vsprintf($text, (array)$parameters),
        );

        $l10nFactory = $this->createStub(IFactory::class);
        $l10nFactory->method('get')->willReturn($l);

        $urlGenerator = $this->createStub(IURLGenerator::class);
        $urlGenerator->method('linkToRouteAbsolute')->willReturn(self::APP_URL);
        $urlGenerator->method('imagePath')->willReturn('/apps/crate/img/app.svg');
        $urlGenerator->method('getAbsoluteURL')->willReturnCallback(
            static fn (string $path): string => 'https://cloud.example' . $path,
        );

        $event = $this->createStub(IEvent::class);
        $event->method('getApp')->willReturn('crate');
        $event->method('getSubject')->willReturn($subject);
        $event->method('getSubjectParameters')->willReturn($subjectParameters);
        $event->method('getObjectId')->willReturn($objectId);
        $event->method('setRichSubject')->willReturnCallback(
            function (string $richSubject, array $parameters = []) use ($event): IEvent {
                $this->richParameters = $parameters;
                return $event;
            },
        );
        $event->method('setLink')->willReturnCallback(
            function (string $link) use ($event): IEvent {
                $this->eventLink = $link;
                return $event;
            },
        );

        (new Provider($l10nFactory, $urlGenerator))->parse('en', $event);
    }
}
