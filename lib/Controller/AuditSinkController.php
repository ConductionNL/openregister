<?php

/**
 * The audit sink's health, as the operations console reads it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/audit-trail-shipped-and-purpose-bound/specs/enhanced-audit-trail/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use OCA\OpenRegister\Service\Audit\AuditSink;
use OCA\OpenRegister\Service\Audit\AuditSinkStatus;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Reports whether the trail is actually leaving the instance.
 *
 * Admin only, both ways. The path the trail is written to is infrastructure
 * detail an ordinary caller has no business learning, and acknowledging a gap
 * is an operator's decision about evidence.
 *
 * @spec openspec/changes/audit-trail-shipped-and-purpose-bound/specs/enhanced-audit-trail/spec.md
 */
class AuditSinkController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param string          $appName      App identifier.
	 * @param IRequest        $request      Active request.
	 * @param AuditSink       $sink         The configured sink.
	 * @param AuditSinkStatus $status       The sink's health.
	 * @param IUserSession    $userSession  Current user session.
	 * @param IGroupManager   $groupManager Group manager, for the admin gate.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly AuditSink $sink,
		private readonly AuditSinkStatus $status,
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * GET /api/audit/sink — is the trail reaching the log platform.
	 *
	 * `configured: false` is the default state of an instance that does not
	 * ship its trail, and it is reported as its own thing rather than as
	 * healthy. A sink nobody set up and a sink that works look identical from
	 * a boolean, and that ambiguity is the whole failure this change is about.
	 *
	 * @return JSONResponse The sink's configuration and health.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/audit-trail-shipped-and-purpose-bound/specs/enhanced-audit-trail/spec.md
	 */
	public function show(): JSONResponse {
		if ($this->isAdmin() === false) {
			return $this->forbidden();
		}

		$configured = $this->sink->isConfigured();
		$status = $this->status->read();

		return new JSONResponse(
			data: [
				'configured' => $configured,
				'path' => $this->sink->path(),
				'format' => $this->sink->format(),
				'healthy' => ($configured === false ? null : $status['healthy']),
				'lastSuccessAt' => $status['lastSuccessAt'],
				'lastFailureAt' => $status['lastFailureAt'],
				'lastError' => $status['lastError'],
				'unshipped' => $status['unshipped'],
				'acknowledgedAt' => $status['acknowledgedAt'],
			]
		);
	}//end show()

	/**
	 * POST /api/audit/sink/acknowledge — an operator says the gap is handled.
	 *
	 * The count does not clear itself on the next successful write. A gap that
	 * heals quietly is a gap whose size nobody ever learns, and the size is the
	 * number of audited acts the security operations centre never saw.
	 *
	 * @return JSONResponse The cleared status.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/audit-trail-shipped-and-purpose-bound/specs/enhanced-audit-trail/spec.md
	 */
	public function acknowledge(): JSONResponse {
		if ($this->isAdmin() === false) {
			return $this->forbidden();
		}

		return new JSONResponse(data: $this->status->acknowledge());
	}//end acknowledge()

	/**
	 * Whether the caller is an instance administrator.
	 *
	 * @return bool True when the caller is in the admin group.
	 *
	 * @spec openspec/changes/audit-trail-shipped-and-purpose-bound/specs/enhanced-audit-trail/spec.md
	 */
	private function isAdmin(): bool {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return false;
		}

		return in_array(
			needle: 'admin',
			haystack: $this->groupManager->getUserGroupIds($user),
			strict: true
		);
	}//end isAdmin()

	/**
	 * The refusal.
	 *
	 * @return JSONResponse HTTP 403.
	 *
	 * @spec openspec/changes/audit-trail-shipped-and-purpose-bound/specs/enhanced-audit-trail/spec.md
	 */
	private function forbidden(): JSONResponse {
		return new JSONResponse(
			data: ['error' => 'The audit sink status requires the admin group'],
			statusCode: Http::STATUS_FORBIDDEN
		);
	}//end forbidden()
}//end class
