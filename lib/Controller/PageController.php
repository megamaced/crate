<?php

declare(strict_types=1);

namespace OCA\Crate\Controller;

use OCA\Crate\CrateImageHosts;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\ContentSecurityPolicy;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;

class PageController extends Controller
{
    public function __construct(string $appName, IRequest $request)
    {
        parent::__construct($appName, $request);
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function index(): TemplateResponse
    {
        $response = new TemplateResponse('crate', 'index');

        // Search results and the artwork preview render thumbnails straight
        // from the enrichment CDNs. The policy is attached to this response
        // rather than contributed globally, so the extra img-src hosts apply
        // to Crate's page alone and not to every other app's. Nextcloud merges
        // it with the instance default, which is why it starts from a populated
        // ContentSecurityPolicy.
        $csp = new ContentSecurityPolicy();
        foreach (CrateImageHosts::ALL as $host) {
            $csp->addAllowedImageDomain('https://' . $host);
        }
        $response->setContentSecurityPolicy($csp);

        return $response;
    }
}
