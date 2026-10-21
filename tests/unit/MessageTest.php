<?php

declare(strict_types=1);

namespace OCA\Crate\Tests\Unit;

use OCA\Crate\Controller\MediaController;
use OCA\Crate\Exception\UserFacingException;
use OCA\Crate\Message;
use OCA\Crate\Service\EnrichmentResult;
use OCA\Crate\Service\EnrichmentService;
use OCA\Crate\Service\MarketValueService;
use OCA\Crate\Service\MediaService;
use OCA\Crate\Service\RecommendationService;
use OCP\AppFramework\Http;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Services describe what went wrong without a language; the response is
 * translated for whoever asked, while logs keep the English.
 */
class MessageTest extends TestCase
{
    public function testAMessageIsTranslatedWhenShownAndEnglishInLogs(): void
    {
        $message = Message::t('Already shared with this user.');

        self::assertSame('Déjà partagé avec cet utilisateur.', $message->translate(new FrenchL10N()));
        self::assertSame('Already shared with this user.', (string) $message);
    }

    public function testAMessageInsideAnotherIsTranslatedToo(): void
    {
        $row = Message::t('Row %1$s: %2$s — skipped', [7, Message::t('purchase price requires a currency')]);

        self::assertSame(
            'Ligne 7 : le prix d’achat exige une devise — ignorée',
            $row->translate(new FrenchL10N()),
        );
        self::assertSame('Row 7: purchase price requires a currency — skipped', (string) $row);
    }

    public function testAPluralPicksTheFormForItsCount(): void
    {
        $one = Message::n('%n row skipped', '%n rows skipped', 1);
        $three = Message::n('%n row skipped', '%n rows skipped', 3);

        self::assertSame('1 ligne ignorée', $one->translate(new FrenchL10N()));
        self::assertSame('3 lignes ignorées', $three->translate(new FrenchL10N()));
        self::assertSame('1 row skipped', (string) $one);
        self::assertSame('3 rows skipped', (string) $three);
    }

    public function testAUserFacingExceptionCarriesItsStatusAndLogsInEnglish(): void
    {
        $e = new UserFacingException(Message::t('Already shared with this user.'), Http::STATUS_CONFLICT);

        self::assertSame(Http::STATUS_CONFLICT, $e->status());
        self::assertSame('Already shared with this user.', $e->getMessage());
        self::assertSame('Déjà partagé avec cet utilisateur.', $e->userMessage()->translate(new FrenchL10N()));
    }

    public function testAControllerAnswersInTheRequestingUsersLanguage(): void
    {
        $enrichment = $this->createStub(EnrichmentService::class);
        $enrichment->method('enrich')
            ->willReturn(EnrichmentResult::error(Message::t('No TMDB ID available.'), Http::STATUS_NOT_FOUND));
        $user = $this->createStub(IUser::class);
        $user->method('getUID')->willReturn('alice');
        $session = $this->createStub(IUserSession::class);
        $session->method('getUser')->willReturn($user);

        $controller = new MediaController(
            'crate',
            $this->createStub(IRequest::class),
            $this->createStub(MediaService::class),
            $enrichment,
            $this->createStub(MarketValueService::class),
            $this->createStub(RecommendationService::class),
            $session,
            $this->createStub(IConfig::class),
            new FrenchL10N(),
        );

        $response = $controller->enrich(5);

        self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
        self::assertSame(['error' => 'Aucun identifiant TMDB disponible.'], $response->getData());
    }
}
