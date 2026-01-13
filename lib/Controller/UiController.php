<?php

namespace OCA\OpenRegister\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\ContentSecurityPolicy;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;

/**
 * UiController that serves SPA entry for history-mode deep links.
 *
 * @psalm-type TemplateName = 'index'
 */
class UiController extends Controller
{
    /**
     * @param string $appName
     * @param IRequest $request
     */
    public function __construct(string $appName, IRequest $request)
    {
        parent::__construct($appName, $request);
    }

    /**
     * Returns the base SPA template response with permissive connect-src for API calls.
     *
     * @phpstan-return TemplateResponse
     * @psalm-return TemplateResponse
     */
    private function makeSpaResponse(): TemplateResponse
    {
        try {
            $response = new TemplateResponse(
                $this->appName,
                'index',
                []
            );

            $csp = new ContentSecurityPolicy();
            $csp->addAllowedConnectDomain('*');
            $response->setContentSecurityPolicy($csp);

            return $response;
        } catch (\Exception $e) {
            return new TemplateResponse(
                $this->appName,
                'error',
                ['error' => $e->getMessage()],
                '500'
            );
        }
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @phpstan-return TemplateResponse
     * @psalm-return TemplateResponse
     */
    public function registers(): TemplateResponse
    {
        return $this->makeSpaResponse();
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @phpstan-return TemplateResponse
     * @psalm-return TemplateResponse
     */
    public function registersDetails(): TemplateResponse
    {
        return $this->makeSpaResponse();
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @phpstan-return TemplateResponse
     * @psalm-return TemplateResponse
     */
    public function schemas(): TemplateResponse
    {
        return $this->makeSpaResponse();
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @phpstan-return TemplateResponse
     * @psalm-return TemplateResponse
     */
    public function schemasDetails(): TemplateResponse
    {
        return $this->makeSpaResponse();
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @phpstan-return TemplateResponse
     * @psalm-return TemplateResponse
     */
    public function sources(): TemplateResponse
    {
        return $this->makeSpaResponse();
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @phpstan-return TemplateResponse
     * @psalm-return TemplateResponse
     */
    public function organisation(): TemplateResponse
    {
        return $this->makeSpaResponse();
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @phpstan-return TemplateResponse
     * @psalm-return TemplateResponse
     */
    public function objects(): TemplateResponse
    {
        return $this->makeSpaResponse();
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @phpstan-return TemplateResponse
     * @psalm-return TemplateResponse
     */
    public function tables(): TemplateResponse
    {
        return $this->makeSpaResponse();
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @phpstan-return TemplateResponse
     * @psalm-return TemplateResponse
     */
    public function chat(): TemplateResponse
    {
        return $this->makeSpaResponse();
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @phpstan-return TemplateResponse
     * @psalm-return TemplateResponse
     */
    public function configurations(): TemplateResponse
    {
        return $this->makeSpaResponse();
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @phpstan-return TemplateResponse
     * @psalm-return TemplateResponse
     */
    public function deleted(): TemplateResponse
    {
        return $this->makeSpaResponse();
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @phpstan-return TemplateResponse
     * @psalm-return TemplateResponse
     */
    public function auditTrail(): TemplateResponse
    {
        return $this->makeSpaResponse();
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @phpstan-return TemplateResponse
     * @psalm-return TemplateResponse
     */
    public function searchTrail(): TemplateResponse
    {
        return $this->makeSpaResponse();
    }
}
