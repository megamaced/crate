<?php

declare(strict_types=1);

namespace OCA\Crate\Controller;

use OCA\Crate\Exception\UserFacingException;
use OCP\AppFramework\Http\DataResponse;
use OCP\IL10N;

/**
 * Reports a UserFacingException in the requesting user's language, with the
 * status it carries.
 *
 * @property IL10N $l
 */
trait ReportsUserFacingErrors
{
    private function userError(UserFacingException $e): DataResponse
    {
        return new DataResponse(['error' => $e->userMessage()->translate($this->l)], $e->status());
    }
}
