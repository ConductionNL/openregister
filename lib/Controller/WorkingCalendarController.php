<?php

/**
 * WorkingCalendarController — the year preview of an unsaved calendar.
 *
 * There is deliberately ONE endpoint here, and it writes nothing. Working
 * calendars are objects in the `flow-timers` register, so the objects API
 * already reads and writes them; a second CRUD surface would give the same
 * data a second RBAC model and a second validator, which is the shape this
 * whole change exists to remove.
 *
 * What the objects API cannot do is answer "what would this definition mean
 * in 2031?" before it is stored. An administrator editing a rule needs to see
 * the dates the rule produces while the edit is still a draft, so this
 * endpoint runs the rules over a year and returns the dates. Nothing is
 * persisted, nothing is cached, and the request body is the calendar.
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
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/working-calendar-admin/specs/flow-business-timers/spec.md#requirement-working-calendars-are-administered-under-nextcloud-admin-settings
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use OCA\OpenRegister\Exception\FlowTimerValidationException;
use OCA\OpenRegister\Service\Flow\Timer\WorkingCalendar;
use OCA\OpenRegister\Settings\OpenRegisterAdmin;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Previews the non-working dates a calendar definition produces in a year.
 *
 * @SuppressWarnings(PHPMD.StaticAccess) WorkingCalendar::fromArray() is the
 * value object's validating named constructor.
 */
class WorkingCalendarController extends Controller {

	/**
	 * The earliest year a preview will compute.
	 *
	 * Bounded on both sides because the year is the loop counter of the
	 * computus and the rule walk: an unbounded year from a request body is a
	 * denial of service with extra steps.
	 *
	 * @var integer
	 */
	public const MIN_YEAR = 1970;

	/**
	 * The latest year a preview will compute.
	 *
	 * @var integer
	 */
	public const MAX_YEAR = 2200;

	/**
	 * Constructor.
	 *
	 * @param string $appName The app id.
	 * @param IRequest $request The current request.
	 * @param IUserSession $userSession Resolves the caller.
	 * @param IGroupManager $groupManager Answers whether they are an administrator.
	 *
	 * @return void
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * The non-working dates an unsaved calendar definition produces in a year.
	 *
	 * The definition is validated on the way in by the same constructor the
	 * write path uses, so a preview of a calendar that could not be saved
	 * refuses with the message the save would have given rather than quietly
	 * previewing something else.
	 *
	 * 🔴 THE no-admin-required TAG IS DELIBERATELY ABSENT and is not spelled
	 * out here either, because a docblock that writes the literal tag DECLARES
	 * it. With it absent, Nextcloud's middleware requires an administrator
	 * before the controller runs, and requireAdmin() then answers a JSON 403
	 * rather than a middleware redirect. CSRF stays on: the panel posts through
	 * axios with Nextcloud's request token, and `AuthorizedAdminSetting` is the
	 * one attribute that declares "administrator" without also disabling it.
	 *
	 * @param array<string, mixed> $calendar The unsaved `working-calendar` definition.
	 * @param int $year The year to compute.
	 *
	 * @return JSONResponse The dates, or a refusal.
	 *
	 * @spec openspec/changes/working-calendar-admin/specs/flow-business-timers/spec.md#requirement-working-calendars-are-administered-under-nextcloud-admin-settings
	 */
	#[AuthorizedAdminSetting(settings: OpenRegisterAdmin::class)]
	public function preview(array $calendar = [], int $year = 0): JSONResponse {
		$refusal = $this->requireAdmin();
		if ($refusal !== null) {
			return $refusal;
		}

		if ($year < self::MIN_YEAR || $year > self::MAX_YEAR) {
			return new JSONResponse(
				data: [
					'error' => sprintf('Preview year must be between %d and %d.', self::MIN_YEAR, self::MAX_YEAR),
				],
				statusCode: 422
			);
		}

		try {
			$definition = WorkingCalendar::fromArray(definition: $calendar);
		} catch (FlowTimerValidationException $refused) {
			return new JSONResponse(
				data: [
					'error' => $refused->getMessage(),
					'errors' => ['code' => 'working-calendar-invalid', 'message' => $refused->getMessage()],
				],
				statusCode: 422
			);
		}

		$dates = [];
		foreach ($definition->nonWorkingDates(year: $year) as $date => $name) {
			$dates[] = ['date' => $date, 'name' => $name];
		}

		return new JSONResponse(
			data: [
				'slug' => $definition->getSlug(),
				'year' => $year,
				'workingWeekdays' => $definition->getWorkingWeekdays(),
				'hoursPerWorkingDay' => $definition->getHoursPerWorkingDay(),
				// Echoed back from what was VALIDATED, like the weekdays above
				// and for the same reason: a panel that previews the holidays
				// but prints the opening time straight from its own form
				// cannot tell the reader that a malformed one was defaulted.
				'dayStartsAt' => sprintf('%02d:%02d', intdiv($definition->getDayStartsAtMinute(), 60), ($definition->getDayStartsAtMinute() % 60)),
				'dayEndsAt' => sprintf('%02d:%02d', intdiv($definition->getDayEndsAtMinute(), 60), ($definition->getDayEndsAtMinute() % 60)),
				'timezone' => $definition->getTimezone(),
				'dates' => $dates,
				'total' => count($dates),
			]
		);
	}//end preview()

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
				data: ['error' => 'Forbidden: working calendars are administered by administrators'],
				statusCode: 403
			);
		}

		return null;
	}//end requireAdmin()
}//end class
