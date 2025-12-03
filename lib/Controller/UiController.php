<?php

/**
 * UiController
 *
 * @category  OpenRegister
 * @package   OCA\OpenRegister\Controller
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2024 Conduction b.v.
 * @license   https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 * @version   GIT: <git_id>
 * @link      https://github.com/conductionnl/openregister
 */

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
/**
 * @psalm-suppress UnusedClass
 */

class UiController extends Controller
{


    /**
     * Constructor for UiController.
     *
     * @param string   $appName The application name.
     * @param IRequest $request The request object.
     *
     * @return void
     */
    public function __construct(string $appName, IRequest $request)
    {
        parent::__construct(appName: $appName, request: $request);

    }//end __construct()


    /**
     * Returns the base SPA template response with permissive connect-src for API calls.
     *
     * @return TemplateResponse The template response.
     *
     * @phpstan-return TemplateResponse
     * @psalm-return   TemplateResponse
     */
    private function makeSpaResponse(): TemplateResponse
    {
        try {
            $response = new TemplateResponse(
                    appName: $this->appName,
                    templateName: 'index',
                    params: []
            );

            $csp = new ContentSecurityPolicy();
            $csp->addAllowedConnectDomain('*');
            $response->setContentSecurityPolicy($csp);

            return $response;
        } catch (\Exception $e) {
            $response = new TemplateResponse(
                    appName: $this->appName,
                    templateName: 'error',
                    params: ['error' => $e->getMessage()]
            );
            $response->setStatus(500);
            return $response;
        }//end try

    }//end makeSpaResponse()


    /**
     * Returns the registers page template.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return TemplateResponse The template response.
     *
     * @phpstan-return TemplateResponse
     * @psalm-return   TemplateResponse
     */
    public function registers(): TemplateResponse
    {
        return $this->makeSpaResponse();

    }//end registers()


    /**
     * Returns the register details page template.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return TemplateResponse The template response.
     *
     * @phpstan-return TemplateResponse
     * @psalm-return   TemplateResponse
     */
    public function registersDetails(): TemplateResponse
    {
        return $this->makeSpaResponse();

    }//end registersDetails()


    /**
     * Returns the schemas page template.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return TemplateResponse The template response.
     *
     * @phpstan-return TemplateResponse
     * @psalm-return   TemplateResponse
     */
    public function schemas(): TemplateResponse
    {
        return $this->makeSpaResponse();

    }//end schemas()


    /**
     * Returns the schema details page template.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return TemplateResponse The template response.
     *
     * @phpstan-return TemplateResponse
     * @psalm-return   TemplateResponse
     */
    public function schemasDetails(): TemplateResponse
    {
        return $this->makeSpaResponse();

    }//end schemasDetails()


    /**
     * Returns the sources page template.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return TemplateResponse The template response.
     *
     * @phpstan-return TemplateResponse
     * @psalm-return   TemplateResponse
     */
    public function sources(): TemplateResponse
    {
        return $this->makeSpaResponse();

    }//end sources()


    /**
     * Returns the organisation page template.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return TemplateResponse The template response.
     *
     * @phpstan-return TemplateResponse
     * @psalm-return   TemplateResponse
     */
    public function organisation(): TemplateResponse
    {
        return $this->makeSpaResponse();

    }//end organisation()


    /**
     * Returns the objects page template.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return TemplateResponse The template response.
     *
     * @phpstan-return TemplateResponse
     * @psalm-return   TemplateResponse
     */
    public function objects(): TemplateResponse
    {
        return $this->makeSpaResponse();

    }//end objects()


    /**
     * Returns the tables page template.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return TemplateResponse The template response.
     *
     * @phpstan-return TemplateResponse
     * @psalm-return   TemplateResponse
     */
    public function tables(): TemplateResponse
    {
        return $this->makeSpaResponse();

    }//end tables()


    /**
     * Returns the chat page template.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return TemplateResponse The template response.
     *
     * @phpstan-return TemplateResponse
     * @psalm-return   TemplateResponse
     */
    public function chat(): TemplateResponse
    {
        return $this->makeSpaResponse();

    }//end chat()


    /**
     * Returns the configurations page template.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return TemplateResponse The template response.
     *
     * @phpstan-return TemplateResponse
     * @psalm-return   TemplateResponse
     */
    public function configurations(): TemplateResponse
    {
        return $this->makeSpaResponse();

    }//end configurations()


    /**
     * Returns the deleted objects page template.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return TemplateResponse The template response.
     *
     * @phpstan-return TemplateResponse
     * @psalm-return   TemplateResponse
     */
    public function deleted(): TemplateResponse
    {
        return $this->makeSpaResponse();

    }//end deleted()


    /**
     * Returns the audit trail page template.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return TemplateResponse The template response.
     *
     * @phpstan-return TemplateResponse
     * @psalm-return   TemplateResponse
     */
    public function auditTrail(): TemplateResponse
    {
        return $this->makeSpaResponse();

    }//end auditTrail()


    /**
     * Returns the search trail page template.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return TemplateResponse The template response.
     *
     * @phpstan-return TemplateResponse
     * @psalm-return   TemplateResponse
     */
    public function searchTrail(): TemplateResponse
    {
        return $this->makeSpaResponse();

    }//end searchTrail()


    /**
     * Returns the webhooks page template.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return TemplateResponse The template response.
     *
     * @phpstan-return TemplateResponse
     * @psalm-return   TemplateResponse
     */
    public function webhooks(): TemplateResponse
    {
        return $this->makeSpaResponse();

    }//end webhooks()


    /**
     * Returns the webhook logs page template.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return TemplateResponse The template response.
     *
     * @phpstan-return TemplateResponse
     * @psalm-return   TemplateResponse
     */
    public function webhooksLogs(): TemplateResponse
    {
        return $this->makeSpaResponse();

    }//end webhooksLogs()


    /**
     * Returns the entities page template.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return TemplateResponse The template response.
     *
     * @phpstan-return TemplateResponse
     * @psalm-return   TemplateResponse
     */
    public function entities(): TemplateResponse
    {
        return $this->makeSpaResponse();

    }//end entities()


}//end class
