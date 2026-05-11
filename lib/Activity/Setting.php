<?php

declare(strict_types=1);

namespace OCA\Crate\Activity;

use OCA\Crate\AppInfo\Application;
use OCP\Activity\ActivitySettings;
use OCP\IL10N;

class Setting extends ActivitySettings
{
    public function __construct(
        private readonly IL10N $l,
    ) {
    }

    public function getIdentifier(): string
    {
        return 'crate';
    }

    public function getName(): string
    {
        return $this->l->t('Crate media collection changes');
    }

    public function getGroupIdentifier(): string
    {
        return Application::APP_ID;
    }

    public function getGroupName(): string
    {
        return $this->l->t('Crate');
    }

    public function getPriority(): int
    {
        return 50;
    }

    public function canChangeMail(): bool
    {
        return true;
    }

    public function isDefaultEnabledMail(): bool
    {
        return false;
    }

    public function canChangeNotification(): bool
    {
        return true;
    }

    public function isDefaultEnabledNotification(): bool
    {
        return false;
    }
}
