<?php

/**
 * AppHost GenericReadinessController
 *
 * The readiness probe of an AppHost app: whether it may take traffic yet.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\AppHost\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/production-observability/specs/production-observability/spec.md#requirement-readiness-endpoint
 */

declare(strict_types=1);

namespace OCA\OpenRegister\AppHost\Controller;

use OCA\OpenRegister\AppHost\Observability\StorageHealthChecks;
use OCP\App\IAppManager;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Throwable;

/**
 * `GET /api/ready`: 200 `{"ready": true}` once the app may serve traffic, else
 * 503 with one reason.
 *
 * Distinct from health (`/api/health`, the liveness probe): readiness asks
 * whether this app's migrations have run for the code that is deployed, and
 * whether the database answers. While an upgrade is due the pod is kept out of
 * rotation rather than restarted. Public like health (ADR-006), and it reports a
 * reason code only, never an error message or a version.
 *
 * @spec openspec/changes/production-observability/specs/production-observability/spec.md#requirement-readiness-endpoint
 */
class GenericReadinessController extends Controller {

	public const REASON_MIGRATIONS_PENDING = 'migrations_pending';

	public const REASON_DATABASE_UNAVAILABLE = 'database_unavailable';

	/**
	 * Constructor.
	 *
	 * @param string              $appName    The app id.
	 * @param IRequest            $request    The request.
	 * @param IAppManager         $appManager Tells whether this app's upgrade is due.
	 * @param StorageHealthChecks $storage    The database probe the health check uses.
	 *
	 * @spec openspec/changes/production-observability/specs/production-observability/spec.md#requirement-readiness-endpoint
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly IAppManager $appManager,
		private readonly StorageHealthChecks $storage,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * Whether the app is ready to serve traffic.
	 *
	 * @return JSONResponse 200 `{"ready": true}`, or 503 `{"ready": false, "reason": ...}`.
	 *
	 * @spec openspec/changes/production-observability/specs/production-observability/spec.md#requirement-readiness-endpoint
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 240, period: 60)]
	public function index(): JSONResponse {
		$reason = $this->notReadyReason();
		if ($reason !== null) {
			return new JSONResponse(data: ['ready' => false, 'reason' => $reason], statusCode: Http::STATUS_SERVICE_UNAVAILABLE);
		}

		return new JSONResponse(data: ['ready' => true]);
	}//end index()

	/**
	 * Why the app is not ready, or null when it is.
	 *
	 * A check that throws counts as not ready (fail closed): a probe that cannot
	 * tell must not send traffic to the pod.
	 *
	 * @return string|null The reason code.
	 */
	private function notReadyReason(): ?string {
		try {
			if ($this->appManager->isUpgradeRequired($this->appName) === true) {
				return self::REASON_MIGRATIONS_PENDING;
			}
		} catch (Throwable $unknown) {
			unset($unknown);
			return self::REASON_MIGRATIONS_PENDING;
		}

		try {
			$this->storage->database();
		} catch (Throwable $down) {
			unset($down);
			return self::REASON_DATABASE_UNAVAILABLE;
		}

		return null;
	}//end notReadyReason()
}//end class
