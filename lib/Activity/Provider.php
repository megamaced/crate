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

        $richParams = [
            'item' => [
                'type' => 'highlight',
                'id' => (string) $event->getObjectId(),
                'name' => $itemLabel,
            ],
        ];

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
}
