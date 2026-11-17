<?php

/**
 * GraphQL API Controller.
 *
 * Provides a GraphQL endpoint at /api/graphql and an interactive
 * GraphiQL explorer at /api/graphql/explorer.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/graphql-api/spec.md
 * @spec openspec/specs/graphql-api/spec.md
 *
 * @spec openspec/changes/retrofit-2026-05-24-b-ctrl-graphql-rt-dash/tasks.md#task-1
 * @spec openspec/changes/retrofit-2026-05-24-b-ctrl-graphql-rt-dash/tasks.md#task-2
 */

namespace OCA\OpenRegister\Search\Controller;

use OCA\OpenRegister\AppInfo\Application;
use OCA\OpenRegister\Search\Controller\Support\GraphQLControllerServices;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\ContentSecurityPolicy;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;

/**
 * Controller for the GraphQL API endpoint.
 *
 * Accepts POST requests with GraphQL queries (JSON body) and returns
 * standard GraphQL responses. Also serves the GraphiQL explorer UI.
 *
 * @psalm-suppress UnusedClass - Registered via routes.php
 */
class GraphQLController extends Controller {

	/**
	 * GraphQLController constructor.
	 *
	 * @param IRequest                  $request  Request object
	 * @param GraphQLControllerServices $services The GraphQL executor and the explorer page's services
	 */
	public function __construct(
		IRequest $request,
		private readonly GraphQLControllerServices $services,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);

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
	 * @spec openspec/specs/graphql-api/spec.md
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-ctrl-graphql-rt-dash/tasks.md#task-1
	 */
	#[AnonRateLimit(limit: 30, period: 60)]
	#[UserRateLimit(limit: 240, period: 60)]
	public function execute(): JSONResponse {
		$body = file_get_contents('php://input');
		$data = json_decode($body, true);

		if ($data === null || isset($data['query']) === false) {
			return $this->badRequestResponse();
		}

		$query = $data['query'];
		$variables = ($data['variables'] ?? null);
		$operationName = ($data['operationName'] ?? null);

		$result = $this->services->graphQLService->execute($query, $variables, $operationName);

		return $this->resultResponse(result: $result);
	}//end execute()

	/**
	 * The 400 for a request body that is not JSON with a `query` field.
	 *
	 * @return JSONResponse
	 */
	private function badRequestResponse(): JSONResponse {
		return new JSONResponse(
			[
				'errors' => [
					[
						'message' => 'Request body must be JSON with a "query" field',
						'extensions' => ['code' => 'BAD_REQUEST'],
					],
				],
			],
			400
		);
	}//end badRequestResponse()

	/**
	 * The response for an executed query, with a Retry-After header when it was rate limited.
	 *
	 * @param array<string, mixed> $result The GraphQL result.
	 *
	 * @return JSONResponse
	 */
	private function resultResponse(array $result): JSONResponse {
		$status = $this->statusOf(result: $result);

		$response = new JSONResponse($result, $status);
		if ($status === 429) {
			$retryAfter = ($result['errors'][0]['extensions']['retryAfter'] ?? 60);
			$response->addHeader('Retry-After', (string)$retryAfter);
		}

		return $response;
	}//end resultResponse()

	/**
	 * The HTTP status of a GraphQL result.
	 *
	 * 200 for data, even with partial errors. 400 for errors without data, or for a
	 * request the first error refuses. 429 when that error is a rate limit.
	 *
	 * @param array<string, mixed> $result The GraphQL result.
	 *
	 * @return int The status.
	 *
	 * @spec openspec/specs/graphql-api/spec.md
	 */
	private function statusOf(array $result): int {
		$firstCode = ($result['errors'][0]['extensions']['code'] ?? null);
		if (isset($result['errors']) === false
			|| (isset($result['data']) === true && $firstCode !== 'BAD_REQUEST')
		) {
			return 200;
		}

		if ($firstCode === 'RATE_LIMITED') {
			return 429;
		}

		return 400;
	}//end statusOf()

	/**
	 * Serve the GraphiQL interactive explorer.
	 *
	 * The explorer is bundled with the app (`openregister-graphqlExplorer.js`), so the page runs under
	 * Nextcloud's default policy: no outside script domain, no inline or eval script. Only the editor's
	 * workers, which the bundle starts from this instance, are allowed explicitly.
	 *
	 * @return TemplateResponse The explorer page
	 *
	 * @auth admin-only the explorer runs any query the administrator types, so only an administrator opens it
	 *
	 * @NoCSRFRequired
	 * @no-admin-idor-exempt No per-object resource: returns the explorer page shell; no data access.
	 *
	 * @spec openspec/specs/graphql-api/spec.md
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-ctrl-graphql-rt-dash/tasks.md#task-2
	 *
	 * @spec openspec/changes/api-explorer-in-the-app/tasks.md#task-1.2
	 */
	public function explorer(): TemplateResponse {
		$response = new TemplateResponse(appName: Application::APP_ID, templateName: 'graphqlExplorer', params: []);
		$response->setContentSecurityPolicy($this->explorerPolicy());

		return $response;
	}//end explorer()

	/**
	 * The explorer's content security policy: Nextcloud's default, plus workers from this instance.
	 *
	 * @return ContentSecurityPolicy
	 *
	 * @spec openspec/changes/api-explorer-in-the-app/tasks.md#task-1.2
	 */
	private function explorerPolicy(): ContentSecurityPolicy {
		$csp = new ContentSecurityPolicy();
		$csp->addAllowedWorkerSrcDomain(domain: '\'self\'');

		return $csp;
	}//end explorerPolicy()
}//end class
