<?php

declare(strict_types=1);

namespace OCA\Crate\Search;

use OCA\Crate\Db\MediaItemMapper;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\Search\IProvider;
use OCP\Search\ISearchQuery;
use OCP\Search\SearchResult;
use OCP\Search\SearchResultEntry;

class Provider implements IProvider
{
    public function __construct(
        private readonly MediaItemMapper $mapper,
        private readonly IURLGenerator $urlGenerator,
        private readonly IL10N $l,
    ) {
    }

    public function getId(): string
    {
        return 'crate';
    }

    public function getName(): string
    {
        return $this->l->t('Crate — Physical Media');
    }

    public function getOrder(string $route, array $routeParameters): int
    {
        // Appear after Files (0) and Talk (5) results
        return 50;
    }

    public function search(IUser $user, ISearchQuery $query): SearchResult
    {
        $term  = $query->getTerm();
        $items = $this->mapper->search($user->getUID(), $term);

        $appUrl = $this->urlGenerator->linkToRouteAbsolute('crate.page.index');

        $entries = array_map(function ($item) use ($appUrl) {
            // Thumbnail via the artwork proxy; fall back to empty string
            $thumb = '';
            if (!empty($item->getArtworkPath())) {
                $thumb = $this->urlGenerator->linkToRouteAbsolute(
                    'crate.artwork.get',
                    ['itemId' => $item->getId()],
                );
            }

            $subline = $item->getFormat();
            if ($item->getYear()) {
                $subline .= ', ' . $item->getYear();
            }
            if ($item->getLabel()) {
                $subline .= ' · ' . $item->getLabel();
            }

            return new SearchResultEntry(
                thumbnailUrl: $thumb,
                title: $item->getArtist() . ' — ' . $item->getTitle(),
                subline: $subline,
                resourceUrl: $appUrl,
                rounded: true,
            );
        }, $items);

        return SearchResult::complete($this->getName(), $entries);
    }
}
