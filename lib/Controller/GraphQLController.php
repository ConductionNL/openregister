<?php

/**
 * GraphQL API Controller.
 *
 * Provides a GraphQL endpoint at /api/graphql and an interactive
 * GraphiQL explorer at /api/graphql/explorer.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/retrofit-2026-04-30-annotate-openregister/tasks.md#task-46
 * @spec openspec/changes/retrofit-2026-04-30-annotate-openregister/tasks.md#task-47
 */

namespace OCA\OpenRegister\Controller;

use OC\Security\CSP\ContentSecurityPolicyNonceManager;
use OC\Security\CSRF\CsrfTokenManager;
use OCA\OpenRegister\Service\GraphQL\GraphQLService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\ContentSecurityPolicy;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use OCP\IURLGenerator;

/**
 * Controller for the GraphQL API endpoint.
 *
 * Accepts POST requests with GraphQL queries (JSON body) and returns
 * standard GraphQL responses. Also serves the GraphiQL explorer UI.
 *
 * @psalm-suppress UnusedClass - Registered via routes.php
 */
class GraphQLController extends Controller
{
    /**
     * GraphQLController constructor.
     *
     * @param string                            $appName        Application name
     * @param IRequest                          $request        Request object
     * @param GraphQLService                    $graphQLService GraphQL service
     * @param IURLGenerator                     $urlGenerator   URL generator for route linking
     * @param ContentSecurityPolicyNonceManager $nonceManager   CSP nonce provider
     * @param CsrfTokenManager                  $csrfManager    CSRF token manager
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly GraphQLService $graphQLService,
        private readonly IURLGenerator $urlGenerator,
        private readonly ContentSecurityPolicyNonceManager $nonceManager,
        private readonly CsrfTokenManager $csrfManager
    ) {
        parent::__construct(appName: $appName, request: $request);

    }//end __construct()

    /**
     * Execute a GraphQL query.
     *
     * Accepts a JSON body with: query (string), variables (object), operationName (string).
     *
     * @return JSONResponse The GraphQL response
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @PublicPage
     *
     * @CORS
     *
     * @spec openspec/changes/retrofit-2026-04-30-annotate-openregister/tasks.md#task-46
     */
    public function execute(): JSONResponse
    {
        $body = file_get_contents('php://input');
        $data = json_decode($body, true);

        if ($data === null || isset($data['query']) === false) {
            return new JSONResponse(
                [
                    'errors' => [
                        [
                            'message'    => 'Request body must be JSON with a "query" field',
                            'extensions' => ['code' => 'BAD_REQUEST'],
                        ],
                    ],
                ],
                400
            );
        }

        $query         = $data['query'];
        $variables     = ($data['variables'] ?? null);
        $operationName = ($data['operationName'] ?? null);

        $result = $this->graphQLService->execute($query, $variables, $operationName);

        // Determine HTTP status: 200 for data (even with partial errors), 400 for query errors only.
        $status  = 200;
        $headers = [];
        if (isset($result['data']) === false && isset($result['errors']) === true) {
            $firstCode = ($result['errors'][0]['extensions']['code'] ?? null);
            $status    = 400;
            if ($firstCode === 'RATE_LIMITED') {
                $status     = 429;
                $retryAfter = ($result['errors'][0]['extensions']['retryAfter'] ?? 60);
                $headers['Retry-After'] = (string) $retryAfter;
            }
        }

        $response = new JSONResponse($result, $status);
        foreach ($headers as $name => $value) {
            $response->addHeader($name, $value);
        }

        return $response;

    }//end execute()

    /**
     * Serve the GraphiQL interactive explorer.
     *
     * @return Response The HTML response with GraphiQL
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @spec openspec/changes/retrofit-2026-04-30-annotate-openregister/tasks.md#task-47
     */
    public function explorer(): Response
    {
        $html = $this->getGraphiQLHtml();

        // Create a response that renders raw HTML.
        // @psalm-suppress MissingTemplateParam.
        $response = new class ($html) extends Response {

            /**
             * The HTML content to render.
             *
             * @var string
             */
            private string $html;

            /**
             * Constructor.
             *
             * @param string $html The HTML content
             */
            public function __construct(string $html)
            {
                parent::__construct();
                $this->html = $html;
                // @phpcs:ignore CustomSniffs.Functions.NamedParameters
                $this->addHeader('Content-Type', 'text/html; charset=utf-8');
            }//end __construct()

            /**
             * Render the HTML body.
             *
             * @return string The HTML
             */
            public function render(): string
            {
                return $this->html;
            }//end render()
        };

        // Relax CSP to allow CDN-hosted GraphiQL assets.
        $csp = new ContentSecurityPolicy();
        $csp->addAllowedScriptDomain('https://unpkg.com');
        $csp->addAllowedScriptDomain('\'unsafe-inline\'');
        $csp->addAllowedStyleDomain('https://unpkg.com');
        $csp->addAllowedFontDomain('https://unpkg.com');
        $csp->addAllowedConnectDomain('\'self\'');
        $csp->allowInlineStyle(true);
        $csp->allowEvalScript(true);
        $response->setContentSecurityPolicy($csp);

        return $response;

    }//end explorer()

    /**
     * Get the GraphiQL HTML page.
     *
     * Uses the CDN-hosted GraphiQL for simplicity.
     *
     * @return string The HTML content
     */
    private function getGraphiQLHtml(): string
    {
        $endpoint = $this->urlGenerator->linkToRoute('openregister.graphQL.execute');

        // Ensure /index.php is included for environments without URL rewriting.
        if (str_contains(haystack: $endpoint, needle: '/index.php') === false) {
            $endpoint = '/index.php'.$endpoint;
        }

        // Get CSP nonce for inline scripts and CSRF request token for auth.
        $nonce        = $this->nonceManager->getNonce();
        $requestToken = $this->csrfManager->getToken()->getEncryptedValue();

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <title>OpenRegister GraphQL Explorer</title>
    <style nonce="{$nonce}">
        body { height: 100vh; margin: 0; overflow: hidden; }
        #graphiql { height: 100vh; }
    </style>
    <link rel="stylesheet" href="https://unpkg.com/graphiql@3/graphiql.min.css" />
</head>
<body>
    <div id="graphiql">Loading...</div>
    <script nonce="{$nonce}" src="https://unpkg.com/react@18/umd/react.production.min.js" crossorigin></script>
    <script nonce="{$nonce}" src="https://unpkg.com/react-dom@18/umd/react-dom.production.min.js" crossorigin></script>
    <script nonce="{$nonce}" src="https://unpkg.com/graphiql@3/graphiql.min.js" crossorigin></script>
    <script nonce="{$nonce}">
        const fetcher = GraphiQL.createFetcher({
            url: '{$endpoint}',
            headers: {
                'requesttoken': '{$requestToken}',
            },
        });

        const root = ReactDOM.createRoot(document.getElementById('graphiql'));
        root.render(
            React.createElement(GraphiQL, {
                fetcher: fetcher,
                defaultEditorToolsVisibility: true,
            })
        );
    </script>
</body>
</html>
HTML;

    }//end getGraphiQLHtml()
}//end class
