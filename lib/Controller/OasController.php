<?php

/**
 * Class OasController
 *
 * Controller for generating OpenAPI Specifications (OAS) for registers in the OpenRegister app.
 * Provides endpoints to generate OAS for a single register or all registers.
 *
 * @category Controller
 * @package  OCA\OpenRegister\AppInfo
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

namespace OCA\OpenRegister\Controller;

use OC\Security\CSP\ContentSecurityPolicyNonceManager;
use OCA\OpenRegister\Service\OasService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\ContentSecurityPolicy;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IURLGenerator;
use Exception;

/**
 * OasController class.
 *
 * Controller for generating OpenAPI Specifications (OAS) for registers.
 *
 * @psalm-suppress UnusedClass - This controller is registered via routes.php and used by Nextcloud's routing system
 */
class OasController extends Controller
{

    /**
     * OAS service instance
     *
     * @var OasService
     */
    private readonly OasService $oasService;

    /**
     * URL generator for building API URLs
     *
     * @var IURLGenerator
     */
    private readonly IURLGenerator $urlGenerator;

    /**
     * CSP nonce manager for inline script nonces
     *
     * @var ContentSecurityPolicyNonceManager
     */
    private readonly ContentSecurityPolicyNonceManager $nonceManager;

    /**
     * OasController constructor
     *
     * @param string                            $appName      Application name
     * @param IRequest                          $request      Request object
     * @param OasService                        $oasService   OAS service instance
     * @param IURLGenerator                     $urlGenerator URL generator for building API URLs
     * @param ContentSecurityPolicyNonceManager $nonceManager CSP nonce manager
     */
    public function __construct(
        string $appName,
        IRequest $request,
        OasService $oasService,
        IURLGenerator $urlGenerator,
        ContentSecurityPolicyNonceManager $nonceManager
    ) {
        parent::__construct(appName: $appName, request: $request);
        $this->oasService   = $oasService;
        $this->urlGenerator = $urlGenerator;
        $this->nonceManager = $nonceManager;
    }//end __construct()

    /**
     * Generate OAS for all registers
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @PublicPage
     *
     * @return JSONResponse
     *
     * @psalm-return JSONResponse<200|500, array<string, mixed>, array<never, never>>
     */
    public function generateAll(): JSONResponse
    {
        try {
            // Generate OAS for all registers.
            $oasData = $this->oasService->createOas();
            return new JSONResponse(data: $oasData);
        } catch (Exception $e) {
            return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 500);
        }
    }//end generateAll()

    /**
     * Generate OAS for a specific register
     *
     * @param string $id The register slug or identifier.
     *
     * @return JSONResponse OAS specification JSON response.
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @PublicPage
     *
     * @psalm-return JSONResponse<200|500, array<string, mixed>, array<never, never>>
     */
    public function generate(string $id): JSONResponse
    {
        try {
            // Generate OAS for the specified register.
            $oasData = $this->oasService->createOas($id);
            return new JSONResponse(data: $oasData);
        } catch (Exception $e) {
            return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 500);
        }
    }//end generate()

    /**
     * Serve interactive ReDoc API documentation for all registers
     *
     * Renders an HTML page with embedded ReDoc viewer that loads the OAS
     * specification from the local API endpoint. This works on localhost
     * and production environments.
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @PublicPage
     *
     * @return DataDisplayResponse HTML page with ReDoc viewer.
     *
     * @psalm-return DataDisplayResponse<200, array<string, mixed>>
     */
    public function docsAll(): DataDisplayResponse
    {
        $oasUrl = $this->urlGenerator->linkToRouteAbsolute(
            routeName: 'openregister.oas.generateAll'
        );

        return $this->buildReDocResponse(
            oasUrl: $oasUrl,
            title: 'OpenRegister API Documentation'
        );
    }//end docsAll()

    /**
     * Serve interactive ReDoc API documentation for a specific register
     *
     * Renders an HTML page with embedded ReDoc viewer that loads the OAS
     * specification for a specific register from the local API endpoint.
     *
     * @param string $id The register slug or identifier.
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @PublicPage
     *
     * @return DataDisplayResponse HTML page with ReDoc viewer.
     *
     * @psalm-return DataDisplayResponse<200, array<string, mixed>>
     */
    public function docs(string $id): DataDisplayResponse
    {
        $oasUrl = $this->urlGenerator->linkToRouteAbsolute(
            routeName: 'openregister.oas.generate',
            arguments: ['id' => $id]
        );

        return $this->buildReDocResponse(
            oasUrl: $oasUrl,
            title: 'Register API Documentation'
        );
    }//end docs()

    /**
     * Build a DataDisplayResponse containing the ReDoc HTML viewer
     *
     * Creates a standalone HTML page with embedded ReDoc that loads the
     * OAS specification from the given URL. Sets appropriate CSP headers
     * to allow the ReDoc CDN script and styles.
     *
     * @param string $oasUrl The URL to the OAS JSON specification.
     * @param string $title  The page title for the documentation.
     *
     * @return DataDisplayResponse HTML response with ReDoc viewer.
     *
     * @psalm-return DataDisplayResponse<200, array<string, mixed>>
     */
    private function buildReDocResponse(string $oasUrl, string $title): DataDisplayResponse
    {
        $escapedUrl   = htmlspecialchars($oasUrl, ENT_QUOTES, 'UTF-8');
        $escapedTitle = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
        $nonce        = htmlspecialchars($this->nonceManager->getNonce(), ENT_QUOTES, 'UTF-8');

        $html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8"/>
    <meta name="viewport" content="width=device-width, initial-scale=1"/>
    <title>{$escapedTitle}</title>
    <link href="https://fonts.googleapis.com/css?family=Montserrat:300,400,700|Roboto:300,400,700" rel="stylesheet">
    <style>body { margin: 0; padding: 0; }</style>
</head>
<body>
    <div id="redoc-container"></div>
    <script nonce="{$nonce}" src="https://cdn.redoc.ly/redoc/latest/bundles/redoc.standalone.js"></script>
    <script nonce="{$nonce}">
        Redoc.init('{$escapedUrl}', {
            suppressWarnings: true,
            scrollYOffset: 0,
            hideDownloadButton: false
        }, document.getElementById('redoc-container'));
    </script>
</body>
</html>
HTML;

        $response = new DataDisplayResponse($html);
        $response->addHeader('Content-Type', 'text/html; charset=utf-8');

        // Allow ReDoc CDN and Google Fonts.
        $csp = new ContentSecurityPolicy();
        $csp->addAllowedScriptDomain('cdn.redoc.ly');
        $csp->addAllowedStyleDomain('fonts.googleapis.com');
        $csp->addAllowedStyleDomain("'unsafe-inline'");
        $csp->addAllowedFontDomain('fonts.gstatic.com');
        $csp->addAllowedConnectDomain("'self'");
        $csp->addAllowedImageDomain('data:');
        $csp->addAllowedWorkerSrcDomain('blob:');
        $response->setContentSecurityPolicy($csp);

        return $response;
    }//end buildReDocResponse()
}//end class
