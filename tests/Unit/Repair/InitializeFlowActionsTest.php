<?php

/**
 * Unit tests for InitializeFlowActions: the openregister action matrix is
 * seeded from the app's own lib/actions.seed.json.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Repair;

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

use OCA\OpenRegister\AppHost\Repair\GenericInitializeActions;
use OCA\OpenRegister\Repair\InitializeFlowActions;
use OCA\OpenRegister\Service\OpenRegisterActionAuthService;
use OCP\App\IAppManager;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\Repair\InitializeFlowActions
 * @uses \OCA\OpenRegister\AppHost\Repair\GenericInitializeActions
 */
final class InitializeFlowActionsTest extends TestCase {

	public function testItIsTheGenericStepForOpenregister(): void {
		$step = new InitializeFlowActions(
			$this->createMock(OpenRegisterActionAuthService::class),
			$this->createMock(IAppManager::class),
			$this->createMock(LoggerInterface::class)
		);

		$this->assertInstanceOf(GenericInitializeActions::class, $step);
		$this->assertSame('Initialize openregister action-authorization matrix (ADR-023)', $step->getName());
	}

	public function testAnEmptyMatrixIsSeededFromTheAppsOwnSeedFile(): void {
		$root = dirname(__DIR__, 3);
		$seeded = json_decode((string)file_get_contents($root . '/lib/actions.seed.json'), true)['actions'];
		$apps = $this->createMock(IAppManager::class);
		$apps->expects($this->once())->method('getAppPath')->with('openregister')->willReturn($root);
		$auth = $this->createMock(OpenRegisterActionAuthService::class);
		$auth->method('getMatrix')->willReturn([]);
		$auth->expects($this->once())->method('setMatrix')->with($seeded);
		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('info')
			->with(sprintf('Seeded action matrix with %d actions (default: admin-only).', count($seeded)));

		(new InitializeFlowActions($auth, $apps, $this->createMock(LoggerInterface::class)))->run($output);

		$this->assertArrayHasKey('flow.create', $seeded);
	}
}
