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
 * UiController serves SPA entry for history-mode deep links
 *
 * Controller for serving Single Page Application (SPA) templates with history-mode
 * routing support. Provides endpoints for various UI routes that all serve the
 * same SPA template with permissive Content Security Policy.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 *
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2024 Conduction b.v.
 * @license   https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/conductionnl/openregister
 *
 * @psalm-type     TemplateName = 'index'
 * @psalm-suppress UnusedClass
 */
class UiController extends Controller
{


    /**
     * Constructor for UiController
     *
     * Initializes controller with application name and request object.
     * Calls parent constructor to set up base controller functionality.
     *
     * @param string   $appName The application name
     * @param IRequest $request The HTTP request object
     *
     * @return void
     */
    public function __construct(string $appName, IRequest $request)
    {
        // Call parent constructor to initialize base controller.
        parent::__construct(appName: $appName, request: $request);

    }//end __construct()


    /**
     * Returns the base SPA template response with permissive connect-src for API calls
     *
     * Creates template response for Single Page Application with Content Security Policy
     * configured to allow API connections. Used by all UI route methods to serve the SPA.
     * Returns error template if rendering fails.
     *
     * @return TemplateResponse Template response for SPA page
     *
     * @phpstan-return TemplateResponse
     *
     * @psalm-return TemplateResponse<200|500, array<string, mixed>>
     */
    private function makeSpaResponse(): TemplateResponse
    {
        try {
            // Create template response for SPA index page.
            $response = new TemplateResponse(
                    appName: $this->appName,
                    templateName: 'index',
                    params: []
            );

            // Configure Content Security Policy to allow API connections.
            // Permissive connect-src is necessary for frontend to make API calls.
            $csp = new ContentSecurityPolicy();
            $csp->addAllowedConnectDomain('*');
            $response->setContentSecurityPolicy($csp);

            // Return successful template response.
            return $response;
        } catch (\Exception $e) {
            // Return error template if rendering fails.
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
     * Returns the registers page template
     *
     * Serves SPA template for registers list page. All routing is handled
     * client-side by the Single Page Application.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return TemplateResponse The template response
     *
     * @phpstan-return TemplateResponse
     * @psalm-return   TemplateResponse
     */
    public function registers(): TemplateResponse
    {
        // Return SPA template response (routing handled client-side).
        return $this->makeSpaResponse();

    }//end registers()


    /**
     * Returns the register details page template
     *
     * Serves SPA template for register details page. All routing is handled
     * client-side by the Single Page Application.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return TemplateResponse The template response
     *
     * @phpstan-return TemplateResponse
     * @psalm-return   TemplateResponse
     */
    public function registersDetails(): TemplateResponse
    {
        // Return SPA template response (routing handled client-side).
        return $this->makeSpaResponse();

    }//end registersDetails()


    /**
     * Returns the schemas page template
     *
     * Serves SPA template for schemas list page. All routing is handled
     * client-side by the Single Page Application.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return TemplateResponse The template response
     *
     * @phpstan-return TemplateResponse
     * @psalm-return   TemplateResponse
     */
    public function schemas(): TemplateResponse
    {
        // Return SPA template response (routing handled client-side).
        return $this->makeSpaResponse();

    }//end schemas()


    /**
     * Returns the schema details page template
     *
     * Serves SPA template for schema details page. All routing is handled
     * client-side by the Single Page Application.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return TemplateResponse The template response
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


    /**
     * Render endpoints UI
     *
     * Serves the Single Page Application template for the endpoints management interface.
     * This route is used when users navigate to the endpoints section of the application.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return TemplateResponse The template response.
     *
     * @phpstan-return TemplateResponse
     * @psalm-return   TemplateResponse
     */
    public function endpoints(): TemplateResponse
    {
        return $this->makeSpaResponse();

    }//end endpoints()


    /**
     * Render endpoint logs UI
     *
     * Serves the Single Page Application template for the endpoint logs interface.
     * This route is used when users navigate to the endpoint logs section.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return TemplateResponse The template response.
     *
     * @phpstan-return TemplateResponse
     * @psalm-return   TemplateResponse
     */
    public function endpointLogs(): TemplateResponse
    {
        return $this->makeSpaResponse();

    }//end endpointLogs()


}//end class
