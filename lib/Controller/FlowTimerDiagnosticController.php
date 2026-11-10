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
use OCA\OpenRegister\AppInfo\Application;
use OCA\OpenRegister\Controller\Support\FlowTimerDiagnosticControllerServices;
use OCA\OpenRegister\Controller\Support\TermExplanationRequest;
use OCA\OpenRegister\Exception\FlowTimerValidationException;
use OCA\OpenRegister\Service\Flow\Timer\TermDiagnosticInterface;
use OCA\OpenRegister\Service\Flow\Timer\TermQuery;
use OCA\OpenRegister\Service\Flow\Timer\WorkingCalendarDefinitionsInterface;
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
	 * Who is asking.
	 *
	 * @var IUserSession
	 */
	private readonly IUserSession $userSession;

	/**
	 * Whether they are an administrator.
	 *
	 * @var IGroupManager
	 */
	private readonly IGroupManager $groupManager;

	/**
	 * The engine, narrating.
	 *
	 * @var TermDiagnosticInterface
	 */
	private readonly TermDiagnosticInterface $diagnostic;

	/**
	 * Builds and validates the calendar definition.
	 *
	 * @var WorkingCalendarDefinitionsInterface
	 */
	private readonly WorkingCalendarDefinitionsInterface $calendars;

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param FlowTimerDiagnosticControllerServices $services The controller's collaborators.
	 */
	public function __construct(
		IRequest $request,
		FlowTimerDiagnosticControllerServices $services,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
		$this->userSession = $services->userSession;
		$this->groupManager = $services->groupManager;
		$this->diagnostic = $services->diagnostic;
		$this->calendars = $services->calendars;
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
	 * @param string $anchorAt The anchor instant.
	 * @param array<string, mixed> $sla `{value, unit, rollToWorkingDay?}`.
	 * @param array<int, mixed> $ladder Optional rungs.
	 *
	 * @return JSONResponse The walk, or a refusal.
	 *
	 * @spec openspec/changes/term-engine-diagnostic/specs/flow-business-timers/spec.md
	 */
	#[AuthorizedAdminSetting(settings: OpenRegisterAdmin::class)]
	public function explain(array $calendar = [], string $anchorAt = '', array $sla = [], array $ladder = []): JSONResponse {
		return $this->explainAsAdmin(term: new TermExplanationRequest(calendar: $calendar, anchorAt: $anchorAt, sla: $sla, ladder: $ladder));
	}//end explain()

	/**
	 * Explain a term for an administrator; anyone else is refused before anything is read.
	 *
	 * @param TermExplanationRequest $term The calendar, anchor, SLA and ladder as sent.
	 *
	 * @return JSONResponse The walk, or a refusal.
	 *
	 * @spec openspec/changes/term-engine-diagnostic/specs/flow-business-timers/spec.md
	 */
	private function explainAsAdmin(TermExplanationRequest $term): JSONResponse {
		$refusal = $this->requireAdmin();
		if ($refusal !== null) {
			return $refusal;
		}

		try {
			return new JSONResponse(data: $this->diagnostic->explain(query: $this->termQuery(term: $term)));
		} catch (FlowTimerValidationException $refused) {
			// A calendar that cannot be saved is not explainable, for the save path's own reason.
			return $this->refused(message: $refused->getMessage());
		}
	}//end explainAsAdmin()

	/**
	 * Parse a request into the query the diagnostic walks; the anchor is read first.
	 *
	 * @param TermExplanationRequest $term The calendar, anchor, SLA and ladder as sent.
	 *
	 * @return TermQuery The parsed query.
	 *
	 * @throws FlowTimerValidationException When the anchor or the calendar cannot be read.
	 *
	 * @spec openspec/changes/term-engine-diagnostic/specs/flow-business-timers/spec.md
	 */
	private function termQuery(TermExplanationRequest $term): TermQuery {
		$anchor = $this->readAnchor(anchorAt: $term->anchorAt);

		return new TermQuery(
			calendar: $this->calendars->parse(definition: $term->calendar),
			anchor: $anchor,
			sla: $term->sla,
			ladder: $term->ladder
		);
	}//end termQuery()

	/**
	 * Read the anchor instant the term is explained from.
	 *
	 * A missing anchor is refused rather than defaulted to now. Both refusals
	 * are raised as the validation exception explain() already answers with
	 * a 422, so they reach the caller exactly as before.
	 *
	 * @param string $anchorAt The anchor as sent.
	 *
	 * @return DateTimeImmutable The anchor.
	 *
	 * @throws FlowTimerValidationException When the anchor is missing or unreadable.
	 */
	private function readAnchor(string $anchorAt): DateTimeImmutable {
		if ($anchorAt === '') {
			throw new FlowTimerValidationException(message: 'An anchor moment is required; the diagnostic explains a term from a date you choose.');
		}

		try {
			return new DateTimeImmutable($anchorAt);
		} catch (Exception $e) {
			throw new FlowTimerValidationException(message: sprintf('The anchor "%s" could not be read as a moment.', $anchorAt));
		}
	}//end readAnchor()

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
