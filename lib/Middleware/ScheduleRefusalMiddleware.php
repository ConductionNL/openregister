<?php

/**
 * Tells the caller of a publish which schedules were refused.
 *
 * A publish reaches OpenRegister as an object write from any app's controller,
 * buildiq's promote among them, so this middleware is global and answers in a
 * header: every response shape stays as it is.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Middleware
 * @package  OCA\OpenRegister\Middleware
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/changes/apphost-schedule-flow-action/specs/apphost-scheduling/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Middleware;

use OCA\OpenRegister\AppHost\Scheduling\ScheduleRefusals;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Middleware;

/**
 * Adds this request's schedule refusals to its response.
 *
 * @spec openspec/changes/apphost-schedule-flow-action/specs/apphost-scheduling/spec.md
 */
class ScheduleRefusalMiddleware extends Middleware {
	/**
	 * The response header, a JSON array of reasons.
	 */
	public const HEADER = 'X-OpenRegister-Schedule-Refused';

	/**
	 * Constructor.
	 *
	 * @param ScheduleRefusals $refusals This request's refusals.
	 */
	public function __construct(
		private readonly ScheduleRefusals $refusals,
	) {
	}//end __construct()

	/**
	 * Add the refusals, when there are any.
	 *
	 * @param Controller $controller The controller that answered.
	 * @param string $methodName The controller method that answered.
	 * @param Response $response The answer.
	 *
	 * @return Response The same answer.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The signature is the Middleware contract.
	 *
	 * @spec openspec/changes/apphost-schedule-flow-action/specs/apphost-scheduling/spec.md
	 */
	public function afterController(Controller $controller, string $methodName, Response $response): Response {
		$refusals = $this->refusals->all();
		if ($refusals !== []) {
			$response->addHeader(self::HEADER, (string)json_encode($refusals));
		}

		return $response;
	}//end afterController()
}//end class
