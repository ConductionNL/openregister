<?php

/**
 * Ask the term engine what it would do, and read its working (row Q8.18).
 *
 * 🔴 IT WRITES NOTHING (D-2). No timer, no ledger event, no audit row. That is
 * structural rather than promised: this controller holds a session, a group
 * manager and {@see TermDiagnostic}, and the diagnostic in turn holds only the
 * calculator. There is no mapper and no connection anywhere on the path, so
 * there is nothing here that COULD arm a timer.
 *
 * 🔑 IT TAKES A CALENDAR DEFINITION, NOT A SLUG, exactly as its neighbour
 * `WorkingCalendarController::preview()` does. A slug would mean reading the
 * calendar object, which is a read through the object stack on a path whose
 * whole promise is that it touches nothing. The admin panel already has the
 * definition in hand, because it is the page that lists them.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/term-engine-diagnostic/specs/flow-business-timers/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use DateTimeImmutable;
use Exception;
use OCA\OpenRegister\Exception\FlowTimerValidationException;
use OCA\OpenRegister\Service\Flow\Timer\TermDiagnostic;
use OCA\OpenRegister\Service\Flow\Timer\WorkingCalendar;
use OCA\OpenRegister\Settings\OpenRegisterAdmin;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * The read-only term-engine diagnostic.
 *
 * @spec openspec/changes/term-engine-diagnostic/specs/flow-business-timers/spec.md
 */
class FlowTimerDiagnosticController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param string          $appName      The app id.
	 * @param IRequest        $request      The request.
	 * @param IUserSession    $userSession  Who is asking.
	 * @param IGroupManager   $groupManager Whether they are an administrator.
	 * @param TermDiagnostic  $diagnostic   The engine, narrating.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
		private readonly TermDiagnostic $diagnostic,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * Explain what arming this SLA against this anchor would compute.
	 *
	 * 🔴 THE no-admin-required TAG IS DELIBERATELY ABSENT and is not spelled
	 * out here either, because a docblock that writes the literal tag DECLARES
	 * it. With it absent, Nextcloud's middleware requires an administrator
	 * before the controller runs. Administrator-only because the calendar may
	 * name an organisation the caller is not in (D-2).
	 *
	 * @param array<string, mixed> $calendar The `working-calendar` definition.
	 * @param string               $anchorAt The anchor instant.
	 * @param array<string, mixed> $sla      `{value, unit, rollToWorkingDay?}`.
	 * @param array<int, mixed>    $ladder   Optional rungs.
	 *
	 * @return JSONResponse The walk, or a refusal.
	 *
	 * @spec openspec/changes/term-engine-diagnostic/specs/flow-business-timers/spec.md
	 */
	#[AuthorizedAdminSetting(settings: OpenRegisterAdmin::class)]
	public function explain(
		array $calendar = [],
		string $anchorAt = '',
		array $sla = [],
		array $ladder = []
	): JSONResponse {
		$refusal = $this->requireAdmin();
		if ($refusal !== null) {
			return $refusal;
		}

		if ($anchorAt === '') {
			return $this->refused(message: 'An anchor moment is required; the diagnostic explains a term from a date you choose.');
		}

		try {
			$anchor = new DateTimeImmutable($anchorAt);
		} catch (Exception $e) {
			return $this->refused(message: sprintf('The anchor "%s" could not be read as a moment.', $anchorAt));
		}

		try {
			$resolved = WorkingCalendar::fromArray(definition: $calendar);

			return new JSONResponse(
				data: $this->diagnostic->explain(
					calendar: $resolved,
					anchor: $anchor,
					sla: $sla,
					ladder: $ladder
				)
			);
		} catch (FlowTimerValidationException $refused) {
			// The SAME message the save path would have given, rather than a
			// diagnostic-specific one: a calendar that cannot be saved must
			// not be explainable, and the reader should meet one explanation
			// of why, not two.
			return $this->refused(message: $refused->getMessage());
		}
	}//end explain()

	/**
	 * A 422 carrying the reason.
	 *
	 * @param string $message The reason.
	 *
	 * @return JSONResponse The refusal.
	 */
	private function refused(string $message): JSONResponse {
		return new JSONResponse(
			data: [
				'error' => $message,
				'errors' => ['code' => 'term-diagnostic-refused', 'message' => $message],
			],
			statusCode: 422
		);
	}//end refused()

	/**
	 * Refuse a caller who is not an administrator.
	 *
	 * @return JSONResponse|null A refusal, or null when the caller may proceed.
	 */
	private function requireAdmin(): ?JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['error' => 'Authentication required'], statusCode: 401);
		}

		if ($this->groupManager->isAdmin($user->getUID()) === false) {
			return new JSONResponse(
				data: ['error' => 'Forbidden: the term diagnostic reads calendars that may not be yours'],
				statusCode: 403
			);
		}

		return null;
	}//end requireAdmin()
}//end class
