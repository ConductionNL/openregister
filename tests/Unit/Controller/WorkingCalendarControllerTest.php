<?php

/**
 * WorkingCalendarControllerTest
 *
 * Golden test pinning the working-calendar preview: the admin refusals, the
 * year bounds, the invalid-definition refusal and the full preview body.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <dev@conduction.nl>
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/working-calendar-admin/specs/flow-business-timers/spec.md#requirement-working-calendars-are-administered-under-nextcloud-admin-settings
 */

declare(strict_types=1);

namespace Unit\Controller;

use OCA\OpenRegister\Controller\WorkingCalendarController;
use OCA\OpenRegister\Service\Flow\Timer\WorkingCalendars;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @coversDefaultClass \OCA\OpenRegister\Controller\WorkingCalendarController
 */
class WorkingCalendarControllerTest extends TestCase {

	private IUserSession&MockObject $userSession;

	private IGroupManager&MockObject $groupManager;

	private WorkingCalendarController $controller;

	protected function setUp(): void {
		$this->userSession = $this->createMock(IUserSession::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->controller = new WorkingCalendarController(
			$this->createMock(IRequest::class),
			$this->userSession,
			$this->groupManager,
			new WorkingCalendars()
		);
	}

	private function asUser(bool $admin): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->userSession->method('getUser')->willReturn($user);
		$this->groupManager->method('isAdmin')->with('alice')->willReturn($admin);
	}

	private static function definition(): array {
		return [
			'slug' => 'golden',
			'title' => 'Golden',
			'workingWeekdays' => [1, 2, 3, 4],
			'hoursPerWorkingDay' => 7.5,
			'rules' => [
				['kind' => 'easter', 'offset' => 1, 'name' => 'Easter Monday'],
				['kind' => 'fixed', 'month' => 1, 'day' => 1, 'name' => 'New Year'],
			],
			'exceptions' => [],
			'dayStartsAt' => '08:30',
			'dayEndsAt' => '16:05',
			'timezone' => 'Europe/Amsterdam',
		];
	}

	public function testNoUserIsA401(): void {
		$this->userSession->method('getUser')->willReturn(null);

		$response = $this->controller->preview(self::definition(), 2027);

		$this->assertSame(401, $response->getStatus());
		$this->assertSame(['error' => 'Authentication required'], $response->getData());
	}

	public function testNonAdminIsA403EvenWithABadYear(): void {
		$this->asUser(false);

		$response = $this->controller->preview([], 0);

		$this->assertSame(403, $response->getStatus());
		$this->assertSame(
			['error' => 'Forbidden: working calendars are administered by administrators'],
			$response->getData()
		);
	}

	public function testYearOutsideTheBoundsIsA422(): void {
		$this->asUser(true);
		$expected = ['error' => 'Preview year must be between 1970 and 2200.'];

		foreach ([0, 1969, 2201] as $year) {
			$response = $this->controller->preview(self::definition(), $year);
			$this->assertSame(422, $response->getStatus());
			$this->assertSame($expected, $response->getData());
		}
	}

	public function testBoundaryYearsAreAccepted(): void {
		$this->asUser(true);

		$this->assertSame(200, $this->controller->preview(self::definition(), 1970)->getStatus());
		$this->assertSame(200, $this->controller->preview(self::definition(), 2200)->getStatus());
	}

	public function testInvalidDefinitionIsA422WithTheValidationMessage(): void {
		$this->asUser(true);
		$message = 'A working calendar requires a non-empty slug.';

		$response = $this->controller->preview(['slug' => ''], 2027);

		$this->assertSame(422, $response->getStatus());
		$this->assertSame(
			[
				'error' => $message,
				'errors' => ['code' => 'working-calendar-invalid', 'message' => $message],
			],
			$response->getData()
		);
	}

	public function testPreviewEchoesTheValidatedDefinitionAndItsDates(): void {
		$this->asUser(true);

		$response = $this->controller->preview(self::definition(), 2027);

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(
			[
				'slug' => 'golden',
				'year' => 2027,
				'workingWeekdays' => [1, 2, 3, 4],
				'hoursPerWorkingDay' => 7.5,
				'dayStartsAt' => '08:30',
				'dayEndsAt' => '16:00',
				'timezone' => 'Europe/Amsterdam',
				'dates' => [
					['date' => '2027-01-01', 'name' => 'New Year'],
					['date' => '2027-03-29', 'name' => 'Easter Monday'],
				],
				'total' => 2,
			],
			$response->getData()
		);
	}
}
