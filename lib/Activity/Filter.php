<?php

declare(strict_types=1);

namespace OCA\Crate\Activity;

use OCA\Crate\AppInfo\Application;
use OCP\Activity\IFilter;
use OCP\IL10N;
use OCP\IURLGenerator;

class Filter implements IFilter
{
    public function __construct(
        private readonly IL10N $l,
        private readonly IURLGenerator $urlGenerator,
    ) {
    }

    public function getIdentifier(): string
    {
        return Application::APP_ID;
    }

    public function getName(): string
    {
        return $this->l->t('Crate');
    }

    public function getPriority(): int
    {
        return 65;
    }

    public function getIcon(): string
    {
        return $this->urlGenerator->getAbsoluteURL(
            $this->urlGenerator->imagePath(Application::APP_ID, 'app.svg'),
        );
    }

    public function filterTypes(array $types): array
    {
        $types[] = 'crate';
        return $types;
    }

    public function allowedApps(): array
    {
        return [Application::APP_ID];
    }
}
