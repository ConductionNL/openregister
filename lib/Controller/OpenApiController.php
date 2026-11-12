<?php

/**
 * OpenRegister OpenAPI document controller.
 *
 * Publishes the app's OpenAPI document at `/api/openapi.json`, as the NL API
 * Design Rules require (/core/publish-openapi).
 *
 * @category  Controller
 * @package   OCA\OpenRegister\Controller
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://OpenRegister.app
 *
 * @spec openspec/specs/openapi-generation/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use OCA\OpenRegister\AppInfo\Application;
use OCP\App\IAppManager;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use stdClass;

/**
 * GET /api/openapi.json.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://OpenRegister.app
 *
 * @spec openspec/specs/openapi-generation/spec.md
 *
 * @psalm-suppress UnusedClass Instantiated by the Nextcloud router.
 */
class OpenApiController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest    $request    The request.
	 * @param IAppManager $appManager Resolves the app path and the installed version.
	 *
	 * @psalm-suppress PossiblyUnusedMethod Instantiated via Nextcloud dependency injection.
	 */
	public function __construct(
		IRequest $request,
		private readonly IAppManager $appManager,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The OpenAPI document of this app, readable from any origin.
	 *
	 * The document describes the API, not its data, so it is public. Its
	 * `info.version` is the installed app version, which is also what every
	 * API response carries in `API-Version`.
	 *
	 * @return JSONResponse The document, or 404 when it is not installed.
	 *
	 * @PublicPage
	 * @NoCSRFRequired
	 *
	 * @spec openspec/specs/openapi-generation/spec.md
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 120, period: 60)]
	public function show(): JSONResponse {
		$document = $this->document();
		$response = new JSONResponse(
			data: ['error' => 'The OpenAPI document is not installed'],
			statusCode: 404
		);
		if ($document !== null) {
			$info = $document->info;
			$info->version = $this->appManager->getAppVersion(appId: Application::APP_ID);
			$response = new JSONResponse(data: $document);
		}

		$response->addHeader(name: 'Access-Control-Allow-Origin', value: '*');

		return $response;
	}//end show()

	/**
	 * The installed OpenAPI document, decoded with empty JSON objects kept as objects.
	 *
	 * @return stdClass|null The document, or null when it is missing or not a document.
	 *
	 * @psalm-suppress MixedAssignment json_decode() is mixed; the result is checked with instanceof before use.
	 *
	 * @spec openspec/specs/openapi-generation/spec.md
	 */
	private function document(): ?stdClass {
		$path = $this->appManager->getAppPath(appId: Application::APP_ID).'/openapi.json';
		if (is_readable($path) === false) {
			return null;
		}

		$document = json_decode((string) file_get_contents($path), false);
		if ($document instanceof stdClass && ($document->info ?? null) instanceof stdClass) {
			return $document;
		}

		return null;
	}//end document()
}//end class
