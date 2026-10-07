<?php

/**
 * The diagnostic endpoint: admin only, write-free, and refusing with the same
 * message the save path would have given.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
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
 * @spec openspec/changes/term-engine-diagnostic/specs/flow-business-timers/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\FlowTimerDiagnosticController;
use OCA\OpenRegister\Service\Flow\Timer\SlaCalculator;
use OCA\OpenRegister\Service\Flow\Timer\TermDiagnostic;
use OCA\OpenRegister\Tests\Unit\Service\Flow\Timer\WorkingCalendarTest;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Verifies the write-free, admin-only diagnostic endpoint.
 */
class FlowTimerDiagnosticControllerTest extends TestCase {

	/**
	 * A controller for a caller.
	 *
	 * @param string|null $uid     The caller, null for anonymous.
	 * @param bool        $isAdmin Whether they are an administrator.
	 *
	 * @return FlowTimerDiagnosticController The controller.
	 */
	private function controller(?string $uid, bool $isAdmin): FlowTimerDiagnosticController {
		$session = $this->createMock(IUserSession::class);
		$groups = $this->createMock(IGroupManager::class);

		if ($uid === null) {
			$session->method('getUser')->willReturn(null);
		}

		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
			$session->method('getUser')->willReturn($user);
		}

		$groups->method('isAdmin')->willReturn($isAdmin);

		return new FlowTimerDiagnosticController(
			'openregister',
			$this->createMock(IRequest::class),
			$session,
			$groups,
			new TermDiagnostic(calculator: new SlaCalculator())
		);
	}//end controller()

	/**
	 * 🔴 The least privileged principal that should be refused: an ordinary
	 * logged-in user.
	 *
	 * The calendar being explained may name an organisation the caller is not
	 * in, so "logged in" is not the bar.
	 *
	 * @return void
	 */
	public function testAnOrdinaryUserIsRefused(): void {
		$response = $this->controller(uid: 'anja', isAdmin: false)->explain(
			calendar: WorkingCalendarTest::nlNational(),
			anchorAt: '2026-04-02T09:00:00+02:00',
			sla: ['value' => 2, 'unit' => SlaCalculator::UNIT_BUSINESS_DAYS]
		);

		$this->assertSame(403, $response->getStatus(), 'a logged-in non-administrator must be refused');
	}//end testAnOrdinaryUserIsRefused()

	/**
	 * And an anonymous caller is refused with 401, not 403.
	 *
	 * @return void
	 */
	public function testAnAnonymousCallerIsRefused(): void {
		$response = $this->controller(uid: null, isAdmin: false)->explain(
			calendar: WorkingCalendarTest::nlNational(),
			anchorAt: '2026-04-02T09:00:00+02:00',
			sla: ['value' => 2, 'unit' => SlaCalculator::UNIT_BUSINESS_DAYS]
		);

		$this->assertSame(401, $response->getStatus());
	}//end testAnAnonymousCallerIsRefused()

	/**
	 * The control: an administrator gets the walk.
	 *
	 * Without it, the refusals above could be passing on a controller that
	 * refuses everyone.
	 *
	 * @return void
	 */
	public function testAnAdministratorGetsTheWalk(): void {
		$response = $this->controller(uid: 'beheerder', isAdmin: true)->explain(
			calendar: WorkingCalendarTest::nlNational(),
			anchorAt: '2026-04-02T09:00:00+02:00',
			sla: ['value' => 2, 'unit' => SlaCalculator::UNIT_BUSINESS_DAYS]
		);

		$this->assertSame(200, $response->getStatus());

		$data = $response->getData();
		$this->assertSame('2026-04-08', substr((string)$data['firesAt'], 0, 10));
		$this->assertNotSame([], $data['skipped'], 'and the working with it');
	}//end testAnAdministratorGetsTheWalk()

	/**
	 * A missing anchor is refused, rather than defaulted to now.
	 *
	 * @return void
	 */
	public function testAMissingAnchorIsRefusedRatherThanDefaulted(): void {
		$response = $this->controller(uid: 'beheerder', isAdmin: true)->explain(
			calendar: WorkingCalendarTest::nlNational(),
			anchorAt: '',
			sla: ['value' => 2, 'unit' => SlaCalculator::UNIT_BUSINESS_DAYS]
		);

		$this->assertSame(422, $response->getStatus(), 'the whole point is a date you CHOOSE');
	}//end testAMissingAnchorIsRefusedRatherThanDefaulted()

	/**
	 * An unreadable anchor is refused, naming it.
	 *
	 * @return void
	 */
	public function testAnUnreadableAnchorIsRefused(): void {
		$response = $this->controller(uid: 'beheerder', isAdmin: true)->explain(
			calendar: WorkingCalendarTest::nlNational(),
			anchorAt: 'volgende week dinsdag misschien',
			sla: ['value' => 2, 'unit' => SlaCalculator::UNIT_BUSINESS_DAYS]
		);

		$this->assertSame(422, $response->getStatus());
	}//end testAnUnreadableAnchorIsRefused()

	/**
	 * A calendar that could not be SAVED is not explainable, and refuses with
	 * the message the save would have given.
	 *
	 * @return void
	 */
	public function testACalendarThatCouldNotBeSavedIsNotExplainable(): void {
		$broken = WorkingCalendarTest::nlNational();
		unset($broken['hoursPerWorkingDay']);

		$response = $this->controller(uid: 'beheerder', isAdmin: true)->explain(
			calendar: $broken,
			anchorAt: '2026-04-02T09:00:00+02:00',
			sla: ['value' => 2, 'unit' => SlaCalculator::UNIT_BUSINESS_DAYS]
		);

		$this->assertSame(422, $response->getStatus());
		$this->assertStringContainsString(
			'hoursPerWorkingDay',
			(string)$response->getData()['error'],
			'one explanation of why, not two'
		);
	}//end testACalendarThatCouldNotBeSavedIsNotExplainable()

	/**
	 * 🔴 Nothing on the controller's path can write.
	 *
	 * @return void
	 */
	public function testNothingOnThePathCanWrite(): void {
		$types = [];
		foreach ((new ReflectionClass(FlowTimerDiagnosticController::class))->getConstructor()->getParameters() as $parameter) {
			$types[] = (string)$parameter->getType();
		}

		$this->assertSame(
			['string', IRequest::class, IUserSession::class, IGroupManager::class, TermDiagnostic::class],
			$types,
			'a mapper or a connection here would be something that could arm a timer or write a ledger row'
		);
	}//end testNothingOnThePathCanWrite()
}//end class
