<?php

/**
 * OpenRegister Audit Settings Controller
 *
 * The one instance-wide audit setting this change introduces: the window
 * within which consecutive edits by one actor merge into one entry.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Controller
 * @package   OCA\OpenRegister\Controller\Settings
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://www.OpenRegister.app
 *
 * @spec openspec/changes/repeating-groups-and-recorded-corrections/specs/enhanced-audit-trail/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller\Settings;

use Exception;
use OCA\OpenRegister\Service\Audit\AuditAggregationService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Reads and writes the audit aggregation window.
 *
 * Admin-only, by carrying no `NoAdminRequired`: deciding that three edits are
 * recorded as one is a decision about what the trail says, and only the person
 * accountable for the trail should be making it.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller\Settings
 *
 * @psalm-suppress UnusedClass
 */
class AuditSettingsController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param string $appName The app name.
	 * @param IRequest $request The request.
	 * @param AuditAggregationService $aggregation The window.
	 */
	public function __construct(
		$appName,
		IRequest $request,
		private readonly AuditAggregationService $aggregation,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * Read the aggregation window.
	 *
	 * Answers the window in seconds, the maximum one may be set to, and
	 * whether anything is merging at all. The maximum travels with the value
	 * so an administrator's form can say what the ceiling is instead of
	 * discovering it by being refused.
	 *
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse The window.
	 *
	 * @spec openspec/changes/repeating-groups-and-recorded-corrections/specs/enhanced-audit-trail/spec.md
	 */
	public function getAggregationSettings(): JSONResponse {
		try {
			$window = $this->aggregation->windowSeconds();

			return new JSONResponse(
				data: [
					'windowSeconds' => $window,
					'maximumSeconds' => AuditAggregationService::MAXIMUM_WINDOW_SECONDS,
					'merging' => ($window > 0),
				]
			);
		} catch (Exception $e) {
			return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 500);
		}
	}//end getAggregationSettings()

	/**
	 * Set the aggregation window.
	 *
	 * Zero switches merging off, which is where every instance starts. A
	 * window above the ceiling is clamped rather than refused, and the answer
	 * says what was stored, so a form never reports a window that is not the
	 * one in force.
	 *
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse The window that was stored.
	 *
	 * @spec openspec/changes/repeating-groups-and-recorded-corrections/specs/enhanced-audit-trail/spec.md
	 */
	public function updateAggregationSettings(): JSONResponse {
		$seconds = $this->request->getParam('windowSeconds');
		if (is_numeric($seconds) === false) {
			return new JSONResponse(
				data: ['error' => "Send the window in seconds under 'windowSeconds'. Zero switches merging off."],
				statusCode: 400
			);
		}

		try {
			$stored = $this->aggregation->setWindowSeconds((int)$seconds);

			return new JSONResponse(
				data: [
					'windowSeconds' => $stored,
					'maximumSeconds' => AuditAggregationService::MAXIMUM_WINDOW_SECONDS,
					'merging' => ($stored > 0),
				]
			);
		} catch (Exception $e) {
			return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 500);
		}
	}//end updateAggregationSettings()
}//end class
