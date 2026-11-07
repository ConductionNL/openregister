<?php

/**
 * Golden tests for FlowTimerDiagnosticController.
 *
 * Pins the exact body of every refusal, the order in which the checks
 * refuse, and the full walk an administrator receives, so a refactor of
 * explain() can be proven behaviour-preserving. Anchors are fixed instants;
 * nothing here reads the clock.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

use DateTimeImmutable;
use OCA\OpenRegister\Controller\FlowTimerDiagnosticController;
use OCA\OpenRegister\Service\Flow\Timer\SlaCalculator;
use OCA\OpenRegister\Service\Flow\Timer\TermDiagnostic;
use OCA\OpenRegister\Service\Flow\Timer\WorkingCalendar;
use OCA\OpenRegister\Tests\Unit\Service\Flow\Timer\WorkingCalendarTest;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Golden tests for FlowTimerDiagnosticController.
 */
class FlowTimerDiagnosticControllerGoldenTest extends TestCase {
	private const ANCHOR = '2026-04-02T09:00:00+02:00';

	private const SLA = ['value' => 2, 'unit' => SlaCalculator::UNIT_BUSINESS_DAYS];

	/**
	 * A controller for an administrator, an ordinary user or nobody.
	 *
	 * @param string $who One of 'admin', 'user', 'anonymous'.
	 *
	 * @return FlowTimerDiagnosticController
	 */
	private function controllerFor(string $who): FlowTimerDiagnosticController {
		$session = $this->createMock(IUserSession::class);
		$groups = $this->createMock(IGroupManager::class);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('u1');
		$session->method('getUser')->willReturn($who === 'anonymous' ? null : $user);
		$groups->method('isAdmin')->willReturnCallback(fn (string $uid): bool => $who === 'admin' && $uid === 'u1');

		return new FlowTimerDiagnosticController(
			'openregister',
			$this->createMock(IRequest::class),
			$session,
			$groups,
			new TermDiagnostic(calculator: new SlaCalculator())
		);
	}

	/**
	 * The 422 body for a reason.
	 *
	 * @param string $message The reason.
	 *
	 * @return array<string, mixed>
	 */
	private function refusal(string $message): array {
		return ['error' => $message, 'errors' => ['code' => 'term-diagnostic-refused', 'message' => $message]];
	}

	/**
	 * A calendar the save path would refuse.
	 *
	 * @return array<string, mixed>
	 */
	private function brokenCalendar(): array {
		$broken = WorkingCalendarTest::nlNational();
		unset($broken['hoursPerWorkingDay']);

		return $broken;
	}

	public function testAnonymousIs401BeforeAnyOtherCheck(): void {
		$response = $this->controllerFor('anonymous')->explain(calendar: $this->brokenCalendar(), anchorAt: '');

		$this->assertSame(401, $response->getStatus());
		$this->assertSame(['error' => 'Authentication required'], $response->getData());
	}

	public function testOrdinaryUserIs403BeforeAnyOtherCheck(): void {
		$response = $this->controllerFor('user')->explain(calendar: $this->brokenCalendar(), anchorAt: '');

		$this->assertSame(403, $response->getStatus());
		$this->assertSame(
			['error' => 'Forbidden: the term diagnostic reads calendars that may not be yours'],
			$response->getData()
		);
	}

	public function testMissingAnchorIsRefusedBeforeTheCalendarIsRead(): void {
		$response = $this->controllerFor('admin')->explain(calendar: $this->brokenCalendar(), anchorAt: '', sla: self::SLA);

		$this->assertSame(422, $response->getStatus());
		$this->assertSame(
			$this->refusal('An anchor moment is required; the diagnostic explains a term from a date you choose.'),
			$response->getData()
		);
	}

	public function testUnreadableAnchorIsRefusedBeforeTheCalendarIsRead(): void {
		$response = $this->controllerFor('admin')->explain(calendar: $this->brokenCalendar(), anchorAt: 'not a date', sla: self::SLA);

		$this->assertSame(422, $response->getStatus());
		$this->assertSame($this->refusal('The anchor "not a date" could not be read as a moment.'), $response->getData());
	}

	public function testBrokenCalendarCarriesTheSavePathMessage(): void {
		$expected = null;
		try {
			WorkingCalendar::fromArray(definition: $this->brokenCalendar());
		} catch (\InvalidArgumentException $e) {
			$expected = $e->getMessage();
		}

		$response = $this->controllerFor('admin')->explain(calendar: $this->brokenCalendar(), anchorAt: self::ANCHOR, sla: self::SLA);

		$this->assertIsString($expected);
		$this->assertSame(422, $response->getStatus());
		$this->assertSame($this->refusal($expected), $response->getData());
	}

	public function testInvalidSlaIsRefusedWithTheEngineMessage(): void {
		$response = $this->controllerFor('admin')->explain(
			calendar: WorkingCalendarTest::nlNational(),
			anchorAt: self::ANCHOR,
			sla: ['value' => 2, 'unit' => 'fortnights']
		);

		$this->assertSame(422, $response->getStatus());
		$data = $response->getData();
		$this->assertSame('term-diagnostic-refused', $data['errors']['code']);
		$this->assertSame($data['error'], $data['errors']['message']);
		$this->assertNotSame('', $data['error']);
	}

	public function testAdministratorGetsExactlyTheEngineWalk(): void {
		$expected = (new TermDiagnostic(calculator: new SlaCalculator()))->explain(
			calendar: WorkingCalendar::fromArray(definition: WorkingCalendarTest::nlNational()),
			anchor: new DateTimeImmutable(self::ANCHOR),
			sla: self::SLA,
			ladder: []
		);

		$response = $this->controllerFor('admin')->explain(
			calendar: WorkingCalendarTest::nlNational(),
			anchorAt: self::ANCHOR,
			sla: self::SLA
		);

		$this->assertSame(200, $response->getStatus());
		$this->assertEquals($expected, $response->getData());
		$this->assertSame('2026-04-08', substr((string)$response->getData()['firesAt'], 0, 10));
	}
}
