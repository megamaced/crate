<?php

declare(strict_types=1);

namespace OCA\Crate\Tests\L10n;

use OCP\IL10N;

final class Fixture
{
    public function __construct(private IL10N $l)
    {
    }

    public function messages(string $name, int $count): array
    {
        return [
            $this->l->t('Server text %s', [$name]),
            $this->l->n('%n server item', '%n server items', $count),
        ];
    }
}
