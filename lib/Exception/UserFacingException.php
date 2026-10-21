<?php

declare(strict_types=1);

namespace OCA\Crate\Exception;

use OCA\Crate\Message;
use OCP\AppFramework\Http;

/**
 * A failure the user should be told about, in their own language.
 *
 * It carries its message untranslated (see Message) and the HTTP status of
 * the response that reports it, so a controller decides neither by matching
 * text. getMessage() is the English text, for logs.
 */
final class UserFacingException extends \RuntimeException
{
    /** @param Http::STATUS_* $status */
    public function __construct(
        private Message $userMessage,
        private int $status = Http::STATUS_BAD_REQUEST,
        ?\Throwable $previous = null,
    ) {
        parent::__construct((string) $userMessage, 0, $previous);
    }

    public function userMessage(): Message
    {
        return $this->userMessage;
    }

    /** @return Http::STATUS_* */
    public function status(): int
    {
        return $this->status;
    }
}
