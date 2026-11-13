<?php

/**
 * Unit tests for the readiness probe.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/production-observability/specs/production-observability/spec.md#requirement-readiness-endpoint
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\AppHost\Controller;

use OCA\OpenRegister\AppHost\Controller\GenericReadinessController;
use OCA\OpenRegister\AppHost\Observability\StorageHealthChecks;
use OCP\App\IAppManager;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Ready once migrations ran and the database answers; 503 with one reason otherwise; a check that cannot tell is not ready.
 *
 * @covers \OCA\OpenRegister\AppHost\Controller\GenericReadinessController
 */
final class GenericReadinessControllerTest extends TestCase {

	private IAppManager&MockObject $apps;

	private StorageHealthChecks&MockObject $storage;

	protected function setUp(): void {
		$this->apps = $this->createMock(IAppManager::class);
		$this->storage = $this->createMock(StorageHealthChecks::class);
	}

	private function probe(): GenericReadinessController {
		return new GenericReadinessController('openregister', $this->createMock(IRequest::class), $this->apps, $this->storage);
	}

	public function testAMigratedAppWithADatabaseIsReady(): void {
		$this->apps->expects($this->once())->method('isUpgradeRequired')->with('openregister')->willReturn(false);
		$this->storage->expects($this->once())->method('database')->willReturn([true, '']);

		$response = $this->probe()->index();

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['ready' => true], $response->getData());
	}

	public function testPendingMigrationsKeepItOutOfRotation(): void {
		$this->apps->method('isUpgradeRequired')->willReturn(true);
		$this->storage->expects($this->never())->method('database');

		$response = $this->probe()->index();

		$this->assertSame(503, $response->getStatus());
		$this->assertSame(['ready' => false, 'reason' => 'migrations_pending'], $response->getData());
	}

	public function testAnUpgradeCheckThatThrowsIsNotReady(): void {
		$this->apps->method('isUpgradeRequired')->willThrowException(new RuntimeException('app path not found'));

		$response = $this->probe()->index();

		$this->assertSame(503, $response->getStatus());
		$this->assertSame('migrations_pending', $response->getData()['reason']);
	}

	public function testADatabaseThatDoesNotAnswerIsNotReadyAndLeaksNothing(): void {
		$this->apps->method('isUpgradeRequired')->willReturn(false);
		$this->storage->method('database')->willThrowException(new RuntimeException('SQLSTATE[HY000] Connection refused to db.internal:5432'));

		$response = $this->probe()->index();

		$this->assertSame(503, $response->getStatus());
		$this->assertSame(['ready' => false, 'reason' => 'database_unavailable'], $response->getData());
	}
}
