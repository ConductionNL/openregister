<?php

/**
 * Unit tests for OperationsConsoleController — the console's wire contract.
 *
 * Three reads, the shape each one answers, and the posture that keeps them
 * administrators-only. The posture assertion is not decoration: this
 * controller has no in-body admin check by design, so the attribute list IS
 * the authorization, and adding `#[NoAdminRequired]` in a later refactor
 * would open every one of these routes to any signed-in user without a single
 * test going red.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/admin-operations-console/specs/operations-console/spec.md
 */

declare(strict_types=1);

namespace Unit\Controller;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Controller\OperationsConsoleController;
use OCA\OpenRegister\Service\OperationsConsoleService;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class OperationsConsoleControllerTest extends TestCase {

	/**
	 * The read model the controller delegates to.
	 *
	 * @var OperationsConsoleService
	 */
	private OperationsConsoleService $console;

	/**
	 * The request, whose parameters each test sets.
	 *
	 * @var IRequest
	 */
	private IRequest $request;

	/**
	 * The request parameters for the test in hand.
	 *
	 * @var array<string, mixed>
	 */
	private array $params = [];

	protected function setUp(): void {
		parent::setUp();

		$this->params = [];
		$this->console = $this->createMock(OperationsConsoleService::class);
		$this->request = $this->createMock(IRequest::class);

		$this->request->method('getParam')->willReturnCallback(
			function (string $key, $default = null) {
				return ($this->params[$key] ?? $default);
			}
		);
	}

	private function controller(): OperationsConsoleController {
		return new OperationsConsoleController('openregister', $this->request, $this->console);
	}

	public function testTheConsoleAnswersItsWindowAndItsPanes(): void {
		$this->console->method('panes')->willReturn(
			[
				'window' => ['hours' => 24, 'since' => '2026-09-15T11:00:00+00:00'],
				'panes' => [
					['id' => 'jobs', 'total' => 5, 'attention' => 1],
					['id' => 'notifications', 'total' => 12, 'attention' => 2],
					['id' => 'rule-runs', 'total' => 3, 'attention' => 0],
				],
			]
		);

		$response = $this->controller()->index();
		$data = $response->getData();

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(24, $data['window']['hours']);
		$this->assertSame(
			['jobs', 'notifications', 'rule-runs'],
			array_column($data['panes'], 'id')
		);
	}

	public function testTheWindowIsReadFromTheRequest(): void {
		$this->params['hours'] = '168';
		$this->console->expects($this->once())->method('panes')->with(168)->willReturn([]);

		$this->controller()->index();
	}

	public function testAMalformedWindowFallsBackRatherThanReadingAsZero(): void {
		$this->params['hours'] = 'last tuesday';
		$this->console->expects($this->once())
			->method('panes')
			->with(OperationsConsoleService::DEFAULT_WINDOW_HOURS)
			->willReturn([]);

		$this->controller()->index();
	}

	public function testTheJobPaneCarriesTheRowsAndTheUnobservedJobs(): void {
		$this->params['state'] = 'failed';
		$this->console->expects($this->once())
			->method('jobs')
			->with('failed', 50)
			->willReturn(
				[
					'results' => [['id' => 1, 'state' => 'failed']],
					'registered' => [['name' => 'ArchivalRetentionTask', 'observed' => false]],
					'unobserved' => [['name' => 'ArchivalRetentionTask', 'observed' => false]],
				]
			);

		$data = $this->controller()->jobs()->getData();

		$this->assertSame('failed', $data['results'][0]['state']);
		$this->assertSame('ArchivalRetentionTask', $data['unobserved'][0]['name']);
	}

	public function testAnEmptyStateFilterIsNoFilterRatherThanAStateCalledNothing(): void {
		$this->params['state'] = '';
		$this->console->expects($this->once())->method('jobs')->with(null, 50)->willReturn([]);

		$this->controller()->jobs();
	}

	public function testTheRuleRunsReadCarriesTheRunsAndTheRulesHoldingAnError(): void {
		$this->console->method('ruleRuns')->willReturn(
			[
				'results' => [['ruleId' => 'rule-a', 'verdict' => 'error']],
				'holdingAnError' => [['ruleId' => 'rule-a', 'lastError' => 'no such property']],
			]
		);

		$data = $this->controller()->ruleRuns()->getData();

		$this->assertSame('rule-a', $data['results'][0]['ruleId']);
		$this->assertSame('no such property', $data['holdingAnError'][0]['lastError']);
	}

	/**
	 * The posture, asserted rather than assumed.
	 *
	 * @param string $method The controller method.
	 *
	 * @dataProvider consoleReads
	 */
	public function testTheConsoleIsNotReachableByANonAdministrator(string $method): void {
		$reflected = new ReflectionMethod(OperationsConsoleController::class, $method);

		$this->assertSame(
			[],
			$reflected->getAttributes(NoAdminRequired::class),
			$method.'() carries #[NoAdminRequired], which hands the whole operations console to any signed-in user. '
			.'This controller has no in-body admin check by design; the middleware is the barrier.'
		);
		$this->assertSame([], $reflected->getAttributes(PublicPage::class), $method.'() is reachable anonymously.');
	}

	/**
	 * The console's three reads.
	 *
	 * @return array<string, array<int, string>> The methods.
	 */
	public static function consoleReads(): array {
		return [
			'panes' => ['index'],
			'jobs' => ['jobs'],
			'rule runs' => ['ruleRuns'],
		];
	}
}
