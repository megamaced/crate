<?php

declare(strict_types=1);

namespace OCA\Crate\Activity;

use OCA\Crate\AppInfo\Application;
use OCP\Activity\Exceptions\UnknownActivityException;
use OCP\Activity\IEvent;
use OCP\Activity\IProvider;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;

class Provider implements IProvider
{
    public function __construct(
        private readonly IFactory $l10nFactory,
        private readonly IURLGenerator $urlGenerator,
    ) {
    }

    public function parse($language, IEvent $event, ?IEvent $previousEvent = null): IEvent
    {
        if ($event->getApp() !== Application::APP_ID) {
            throw new UnknownActivityException();
        }

        $l = $this->l10nFactory->get(Application::APP_ID, $language);
        $params = $event->getSubjectParameters();
        $title = $params['title'] ?? '';
        $artist = $params['artist'] ?? '';
        $category = $params['category'] ?? '';

        $itemLabel = $artist ? "$artist – $title" : $title;

        // A deleted item has no detail page left to open, so those entries point
        // at the category it was removed from instead.
        $link = $event->getSubject() === 'item_deleted'
            ? $this->categoryLink($category)
            : $this->itemLink((int) $event->getObjectId());

        $richParams = [
            'item' => [
                'type' => 'highlight',
                'id' => (string) $event->getObjectId(),
                'name' => $itemLabel,
                // 'highlight' renders as a plain <a> in the Activity app once a
                // link is present, which is what makes the entry clickable.
                'link' => $link,
            ],
        ];

        $event->setLink($link);

        switch ($event->getSubject()) {
            case 'item_created':
                $event->setRichSubject(
                    $l->t('You added {item} to your %s collection', [$category]),
                    $richParams,
                );
                $event->setParsedSubject(
                    $l->t('You added %1$s to your %2$s collection', [$itemLabel, $category]),
                );
                break;

            case 'item_updated':
                $event->setRichSubject(
                    $l->t('You updated {item} in your %s collection', [$category]),
                    $richParams,
                );
                $event->setParsedSubject(
                    $l->t('You updated %1$s in your %2$s collection', [$itemLabel, $category]),
                );
                break;

            case 'item_deleted':
                $event->setRichSubject(
                    $l->t('You removed {item} from your %s collection', [$category]),
                    $richParams,
                );
                $event->setParsedSubject(
                    $l->t('You removed %1$s from your %2$s collection', [$itemLabel, $category]),
                );
                break;

            case 'item_enriched':
                $event->setRichSubject(
                    $l->t('You enriched {item} with metadata', []),
                    $richParams,
                );
                $event->setParsedSubject(
                    $l->t('You enriched %1$s with metadata', [$itemLabel]),
                );
                break;

            default:
                throw new UnknownActivityException();
        }

        $event->setIcon($this->urlGenerator->getAbsoluteURL(
            $this->urlGenerator->imagePath(Application::APP_ID, 'app.svg'),
        ));

        return $event;
    }

    /**
     * Deep link to an item's detail view in the Crate SPA — the same
     * `#/detail/{id}` route the unified-search provider hands out.
     */
    private function itemLink(int $itemId): string
    {
        return $this->urlGenerator->linkToRouteAbsolute('crate.page.index') . '#/detail/' . $itemId;
    }

    /**
     * Deep link to a category view. The SPA's hash routes are plural, while the
     * stored category is singular; an unknown value falls back to the app root.
     */
    private function categoryLink(string $category): string
    {
        $routes = [
            'music' => 'music',
            'film' => 'films',
            'book' => 'books',
            'game' => 'games',
            'comic' => 'comics',
        ];

        $base = $this->urlGenerator->linkToRouteAbsolute('crate.page.index');
        return isset($routes[$category]) ? $base . '#/' . $routes[$category] : $base;
    }
}
