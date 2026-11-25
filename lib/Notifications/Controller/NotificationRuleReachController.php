<?php

/**
 * NotificationRuleReachController.
 *
 *   GET /api/notification-rule-reach
 *       → the notification rules that resolved to no recipients, how often in
 *         their latest run and when that last happened, read from the
 *         persisted RuleReachLedger.
 *
 * ADMINISTRATORS ONLY, AND BY THE MIDDLEWARE. The method carries no
 * `#[NoAdminRequired]`, so Nextcloud refuses anybody else before the
 * controller is constructed: the list names every rule on the instance.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Notifications\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/a-rule-that-reaches-nobody-says-so/specs/notificatie-engine/spec.md#requirement-the-record-reaches-a-person-not-only-a-log-req-rrn-03
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Notifications\Controller;

use OCA\OpenRegister\Kernel\Contracts\AppIdentity;
use OCA\OpenRegister\Notifications\Service\Notification\RuleReachLedger;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * The administrator's view of rules that reached nobody.
 *
 * @spec openspec/changes/a-rule-that-reaches-nobody-says-so/specs/notificatie-engine/spec.md#requirement-the-record-reaches-a-person-not-only-a-log-req-rrn-03
 */
class NotificationRuleReachController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest        $request The request.
	 * @param RuleReachLedger $ledger  The persisted reach record.
	 */
	public function __construct(
		IRequest $request,
		private readonly RuleReachLedger $ledger,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The rules that reached nobody, newest first.
	 *
	 * @return JSONResponse 200 with `rulesReachingNobody`, `ruleCount`, `occurrences` and `needsAPerson`.
	 *
	 * @spec openspec/changes/a-rule-that-reaches-nobody-says-so/specs/notificatie-engine/spec.md#requirement-the-record-reaches-a-person-not-only-a-log-req-rrn-03
	 */
	public function index(): JSONResponse {
		return new JSONResponse(data: $this->ledger->report());
	}//end index()
}//end class
