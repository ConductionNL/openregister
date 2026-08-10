<?php

/**
 * UiController
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  OpenRegister
 * @package   OCA\OpenRegister\Controller
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2024 Conduction b.v.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
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
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/conductionnl/openregister
 *
 * @psalm-type     TemplateName = 'index'
 * @psalm-suppress UnusedClass
 *
 * @SuppressWarnings(PHPMD.TooManyMethods)       Every public method is a one-liner
 *     SPA-mount route stub required by the NC AppFramework router: each history-
 *     mode deep-link path needs its own named action so OC\Route\Router does not
 *     drop duplicate route names. Splitting into multiple controllers would not
 *     reduce complexity and would scatter the route registration.
 * @SuppressWarnings(PHPMD.TooManyPublicMethods) Same reason as TooManyMethods
 *     above: each SPA route requires its own public NC controller action.
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
     *
     * @spec openspec/changes/retrofit-2026-05-24-b-ctrl-misc/tasks.md#task-1
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
     *
     * @NoCSRFRequired
     *
     * @return TemplateResponse The SPA template response
     *
     * @phpstan-return TemplateResponse
     *
     * @psalm-return TemplateResponse<200|500, array<string, mixed>>
     *
     * @spec exclude Trivial SPA-mount route stub: delegates to makeSpaResponse() for client-side routing.
     *   SPA-mount contract owned by no-code-app-builder via retrofit-2026-05-24-b-ctrl-misc/tasks.md#task-1.
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
     *
     * @NoCSRFRequired
     *
     * @return TemplateResponse The SPA template response
     *
     * @phpstan-return TemplateResponse
     *
     * @psalm-return TemplateResponse<200|500, array<string, mixed>>
     *
     * @spec exclude Trivial SPA-mount route stub: delegates to makeSpaResponse() for client-side routing.
     *   SPA-mount contract owned by no-code-app-builder via retrofit-2026-05-24-b-ctrl-misc/tasks.md#task-1.
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
     *
     * @NoCSRFRequired
     *
     * @phpstan-return TemplateResponse
     *
     * @psalm-return TemplateResponse<200|500, array<string, mixed>>
     *
     * @return TemplateResponse The SPA template response
     *
     * @spec exclude Trivial SPA-mount route stub: delegates to makeSpaResponse() for client-side routing.
     *   SPA-mount contract owned by no-code-app-builder via retrofit-2026-05-24-b-ctrl-misc/tasks.md#task-1.
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
     *
     * @NoCSRFRequired
     *
     * @phpstan-return TemplateResponse
     *
     * @psalm-return TemplateResponse<200|500, array<string, mixed>>
     *
     * @return TemplateResponse The SPA template response
     *
     * @spec exclude Trivial SPA-mount route stub: delegates to makeSpaResponse() for client-side routing.
     *   SPA-mount contract owned by no-code-app-builder via retrofit-2026-05-24-b-ctrl-misc/tasks.md#task-1.
     */
    public function schemasDetails(): TemplateResponse
    {
        return $this->makeSpaResponse();
    }//end schemasDetails()

    /**
     * Returns the sources page template.
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @phpstan-return TemplateResponse
     *
     * @psalm-return TemplateResponse<200|500, array<string, mixed>>
     *
     * @return TemplateResponse The SPA template response
     *
     * @spec exclude Trivial SPA-mount route stub: delegates to makeSpaResponse() for client-side routing.
     *   SPA-mount contract owned by no-code-app-builder via retrofit-2026-05-24-b-ctrl-misc/tasks.md#task-1.
     */
    public function sources(): TemplateResponse
    {
        return $this->makeSpaResponse();
    }//end sources()

    /**
     * Returns the organisation page template.
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @phpstan-return TemplateResponse
     *
     * @psalm-return TemplateResponse<200|500, array<string, mixed>>
     *
     * @return TemplateResponse The SPA template response
     *
     * @spec exclude Trivial SPA-mount route stub: delegates to makeSpaResponse() for client-side routing.
     *   SPA-mount contract owned by no-code-app-builder via retrofit-2026-05-24-b-ctrl-misc/tasks.md#task-1.
     */
    public function organisation(): TemplateResponse
    {
        return $this->makeSpaResponse();
    }//end organisation()

    /**
     * Returns the objects page template.
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @phpstan-return TemplateResponse
     *
     * @psalm-return TemplateResponse<200|500, array<string, mixed>>
     *
     * @return TemplateResponse The SPA template response
     *
     * @spec exclude Trivial SPA-mount route stub: delegates to makeSpaResponse() for client-side routing.
     *   SPA-mount contract owned by no-code-app-builder via retrofit-2026-05-24-b-ctrl-misc/tasks.md#task-1.
     */
    public function objects(): TemplateResponse
    {
        return $this->makeSpaResponse();
    }//end objects()

    /**
     * Returns the standalone integrations view template.
     *
     * Used by the per-leaf screenshot harness so a single URL of the form
     * `/integrations/{register}/{schema}/{objectId}` lands on a Vue Router
     * route that mounts IntegrationsView.vue — bypassing ObjectDetails and
     * its legacy sub-resource plugin races.
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @phpstan-return TemplateResponse
     *
     * @psalm-return TemplateResponse<200|500, array<string, mixed>>
     *
     * @return TemplateResponse The SPA template response
     *
     * @spec openspec/changes/retrofit-2026-05-24-b-ctrl-misc/tasks.md#task-1
     */
    public function integrationsView(): TemplateResponse
    {
        return $this->makeSpaResponse();
    }//end integrationsView()

    /**
     * Returns the tables page template.
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @phpstan-return TemplateResponse
     *
     * @psalm-return TemplateResponse<200|500, array<string, mixed>>
     *
     * @return TemplateResponse The SPA template response
     *
     * @spec exclude Trivial SPA-mount route stub: delegates to makeSpaResponse() for client-side routing.
     *   SPA-mount contract owned by no-code-app-builder via retrofit-2026-05-24-b-ctrl-misc/tasks.md#task-1.
     */
    public function tables(): TemplateResponse
    {
        return $this->makeSpaResponse();
    }//end tables()

    /**
     * Returns the configurations page template.
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @phpstan-return TemplateResponse
     *
     * @psalm-return TemplateResponse<200|500, array<string, mixed>>
     *
     * @return TemplateResponse The SPA template response
     *
     * @spec exclude Trivial SPA-mount route stub: delegates to makeSpaResponse() for client-side routing.
     *   SPA-mount contract owned by no-code-app-builder via retrofit-2026-05-24-b-ctrl-misc/tasks.md#task-1.
     */
    public function configurations(): TemplateResponse
    {
        return $this->makeSpaResponse();
    }//end configurations()

    /**
     * Returns the deleted objects page template.
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @phpstan-return TemplateResponse
     *
     * @psalm-return TemplateResponse<200|500, array<string, mixed>>
     *
     * @return TemplateResponse The SPA template response
     *
     * @spec exclude Trivial SPA-mount route stub: delegates to makeSpaResponse() for client-side routing.
     *   SPA-mount contract owned by no-code-app-builder via retrofit-2026-05-24-b-ctrl-misc/tasks.md#task-1.
     */
    public function deleted(): TemplateResponse
    {
        return $this->makeSpaResponse();
    }//end deleted()

    /**
     * Returns the audit trail page template.
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @phpstan-return TemplateResponse
     *
     * @psalm-return TemplateResponse<200|500, array<string, mixed>>
     *
     * @return TemplateResponse The SPA template response
     *
     * @spec exclude Trivial SPA-mount route stub: delegates to makeSpaResponse() for client-side routing.
     *   SPA-mount contract owned by no-code-app-builder via retrofit-2026-05-24-b-ctrl-misc/tasks.md#task-1.
     */
    public function auditTrail(): TemplateResponse
    {
        return $this->makeSpaResponse();
    }//end auditTrail()

    /**
     * Returns the search trail page template.
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @phpstan-return TemplateResponse
     *
     * @psalm-return TemplateResponse<200|500, array<string, mixed>>
     *
     * @return TemplateResponse The SPA template response
     *
     * @spec exclude Trivial SPA-mount route stub: delegates to makeSpaResponse() for client-side routing.
     *   SPA-mount contract owned by no-code-app-builder via retrofit-2026-05-24-b-ctrl-misc/tasks.md#task-1.
     */
    public function searchTrail(): TemplateResponse
    {
        return $this->makeSpaResponse();
    }//end searchTrail()

    /**
     * Returns the webhooks page template.
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @phpstan-return TemplateResponse
     *
     * @psalm-return TemplateResponse<200|500, array<string, mixed>>
     *
     * @return TemplateResponse The SPA template response
     *
     * @spec exclude Trivial SPA-mount route stub: delegates to makeSpaResponse() for client-side routing.
     *   SPA-mount contract owned by no-code-app-builder via retrofit-2026-05-24-b-ctrl-misc/tasks.md#task-1.
     */
    public function webhooks(): TemplateResponse
    {
        return $this->makeSpaResponse();
    }//end webhooks()

    /**
     * Returns the webhook logs page template.
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @phpstan-return TemplateResponse
     *
     * @psalm-return TemplateResponse<200|500, array<string, mixed>>
     *
     * @return TemplateResponse The SPA template response
     *
     * @spec exclude Trivial SPA-mount route stub: delegates to makeSpaResponse() for client-side routing.
     *   SPA-mount contract owned by no-code-app-builder via retrofit-2026-05-24-b-ctrl-misc/tasks.md#task-1.
     */
    public function webhooksLogs(): TemplateResponse
    {
        return $this->makeSpaResponse();
    }//end webhooksLogs()

    /**
     * Returns the entities page template.
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @phpstan-return TemplateResponse
     *
     * @psalm-return TemplateResponse<200|500, array<string, mixed>>
     *
     * @return TemplateResponse The SPA template response
     *
     * @spec exclude Trivial SPA-mount route stub: delegates to makeSpaResponse() for client-side routing.
     *   SPA-mount contract owned by no-code-app-builder via retrofit-2026-05-24-b-ctrl-misc/tasks.md#task-1.
     */
    public function entities(): TemplateResponse
    {
        return $this->makeSpaResponse();
    }//end entities()

    /**
     * Returns the entity details page template.
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @phpstan-return TemplateResponse
     *
     * @psalm-return TemplateResponse<200|500, array<string, mixed>>
     *
     * @return TemplateResponse The SPA template response
     *
     * @spec exclude Trivial SPA-mount route stub: delegates to makeSpaResponse() for client-side routing.
     *   SPA-mount contract owned by no-code-app-builder via retrofit-2026-05-24-b-ctrl-misc/tasks.md#task-1.
     */
    public function entitiesDetails(): TemplateResponse
    {
        return $this->makeSpaResponse();
    }//end entitiesDetails()

    /**
     * Render the AVG / Verwerkingsregister UI.
     *
     * Serves the SPA shell for the AVG management surface (CRUD over
     * verwerkingsactiviteiten, verantwoordingsdocument, DSAR flows,
     * compliance audit). Frontend routing inside the SPA picks the
     * right view based on the URL path.
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @phpstan-return TemplateResponse
     *
     * @psalm-return TemplateResponse<200|500, array<string, mixed>>
     *
     * @return TemplateResponse The SPA template response
     *
     * @spec openspec/changes/retrofit-2026-05-24-b-ctrl-misc/tasks.md#task-1
     */
    public function avg(): TemplateResponse
    {
        return $this->makeSpaResponse();
    }//end avg()

    /**
     * Render the reports / rapportage list view.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @phpstan-return TemplateResponse
     *
     * @psalm-return TemplateResponse<200|500, array<string, mixed>>
     *
     * @return TemplateResponse The SPA template response
     *
     * @spec exclude Trivial SPA-mount route stub: delegates to makeSpaResponse() for client-side routing.
     *   SPA-mount contract owned by no-code-app-builder via retrofit-2026-05-24-b-ctrl-misc/tasks.md#task-1.
     */
    public function reports(): TemplateResponse
    {
        return $this->makeSpaResponse();

    }//end reports()

    /**
     * Render the single-dashboard view (`/reports/{id}`).
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @phpstan-return TemplateResponse
     *
     * @psalm-return TemplateResponse<200|500, array<string, mixed>>
     *
     * @return TemplateResponse The SPA template response
     *
     * @spec exclude Trivial SPA-mount route stub: delegates to makeSpaResponse() for client-side routing.
     *   SPA-mount contract owned by no-code-app-builder via retrofit-2026-05-24-b-ctrl-misc/tasks.md#task-1.
     */
    public function reportView(): TemplateResponse
    {
        return $this->makeSpaResponse();

    }//end reportView()

    /**
     * Render endpoints UI
     *
     * Serves the Single Page Application template for the endpoints management interface.
     * This route is used when users navigate to the endpoints section of the application.
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @phpstan-return TemplateResponse
     *
     * @psalm-return TemplateResponse<200|500, array<string, mixed>>
     *
     * @return TemplateResponse The SPA template response
     *
     * @spec exclude Trivial SPA-mount route stub: delegates to makeSpaResponse() for client-side routing.
     *   SPA-mount contract owned by no-code-app-builder via retrofit-2026-05-24-b-ctrl-misc/tasks.md#task-1.
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
     *
     * @NoCSRFRequired
     *
     * @phpstan-return TemplateResponse
     *
     * @psalm-return TemplateResponse<200|500, array<string, mixed>>
     *
     * @return TemplateResponse The SPA template response
     *
     * @spec exclude Trivial SPA-mount route stub: delegates to makeSpaResponse() for client-side routing.
     *   SPA-mount contract owned by no-code-app-builder via retrofit-2026-05-24-b-ctrl-misc/tasks.md#task-1.
     */
    public function endpointLogs(): TemplateResponse
    {
        return $this->makeSpaResponse();
    }//end endpointLogs()

    /**
     * Render templates UI
     *
     * Serves the Single Page Application template for the templates management interface.
     * This route is used when users navigate to the templates section of the application.
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @phpstan-return TemplateResponse
     *
     * @psalm-return TemplateResponse<200|500, array<string, mixed>>
     *
     * @return TemplateResponse The SPA template response
     *
     * @spec exclude Trivial SPA-mount route stub: delegates to makeSpaResponse() for client-side routing.
     */
    public function templates(): TemplateResponse
    {
        return $this->makeSpaResponse();
    }//end templates()

    /**
     * Render features & roadmap UI
     *
     * Serves the Single Page Application template for the features and roadmap interface.
     * This route is used when users navigate to the features & roadmap section of the application.
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @phpstan-return TemplateResponse
     *
     * @psalm-return TemplateResponse<200|500, array<string, mixed>>
     *
     * @return TemplateResponse The SPA template response
     *
     * @spec exclude Trivial SPA-mount route stub: delegates to makeSpaResponse() for client-side routing.
     */
    public function featuresRoadmap(): TemplateResponse
    {
        return $this->makeSpaResponse();
    }//end featuresRoadmap()

    /**
     * Render My-account UI
     *
     * Serves the Single Page Application template for the per-user account page
     * (`/mijn-account`). Mounted by the manifest as `myAccount` and reached via
     * the settings menu. Without this route the hard-load and deep-link 404
     * server-side. See ConductionNL/openregister#1962.
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @phpstan-return TemplateResponse
     *
     * @psalm-return TemplateResponse<200|500, array<string, mixed>>
     *
     * @return TemplateResponse The SPA template response
     *
     * @spec exclude Trivial SPA-mount route stub: delegates to makeSpaResponse() for client-side routing.
     */
    public function myAccount(): TemplateResponse
    {
        return $this->makeSpaResponse();
    }//end myAccount()

    /**
     * Render Application-detail UI
     *
     * Serves the Single Page Application template for `/applications/{id}`.
     * Without this route the hard-load and deep-link 404 server-side. See
     * ConductionNL/openregister#1962.
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @phpstan-return TemplateResponse
     *
     * @psalm-return TemplateResponse<200|500, array<string, mixed>>
     *
     * @return TemplateResponse The SPA template response
     *
     * @spec exclude Trivial SPA-mount route stub: delegates to makeSpaResponse() for client-side routing.
     */
    public function applicationDetails(): TemplateResponse
    {
        return $this->makeSpaResponse();
    }//end applicationDetails()

    /**
     * Render Object-detail (deep-link) UI
     *
     * Serves the Single Page Application template for
     * `/objects/{register}/{schema}/{id}`. Has its own action name (distinct
     * from `ui#objects`) so OC's `OC\Route\Router` duplicate-route-name guard
     * does not drop one of the two — same trick as `ui#integrationsView` at
     * `appinfo/routes.php`. See ConductionNL/openregister#1962.
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @phpstan-return TemplateResponse
     *
     * @psalm-return TemplateResponse<200|500, array<string, mixed>>
     *
     * @return TemplateResponse The SPA template response
     *
     * @spec exclude Trivial SPA-mount route stub: delegates to makeSpaResponse() for client-side routing.
     */
    public function objectDetail(): TemplateResponse
    {
        return $this->makeSpaResponse();
    }//end objectDetail()
}//end class
