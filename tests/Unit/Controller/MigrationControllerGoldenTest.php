<?php

/**
 * MigrationControllerGoldenTest
 *
 * Golden test pinning the storage-migration endpoints: parameter coercion,
 * the exact refusals, which service migrations run for each direction and in
 * what order, and the error bodies.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace Unit\Controller;

use Exception;
use OCA\OpenRegister\Controller\MigrationController;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\MigrationService;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @coversDefaultClass \OCA\OpenRegister\Controller\MigrationController
 */
class MigrationControllerGoldenTest extends TestCase {

	private MigrationService&MockObject $migrationService;

	private IRequest&MockObject $request;

	private MigrationController $controller;

	private Register $register;

	private Schema $schema;

	/**
	 * @var list<array<int, mixed>>
	 */
	private array $calls = [];

	protected function setUp(): void {
		$this->request = $this->createMock(IRequest::class);
		$this->migrationService = $this->createMock(MigrationService::class);
		$this->controller = new MigrationController('openregister', $this->request, $this->migrationService);
		$this->register = $this->createMock(Register::class);
		$this->schema = $this->createMock(Schema::class);
	}

	private function withParams(array $params): void {
		$this->request->method('getParam')->willReturnCallback(
			function (string $name, $default = null) use ($params) {
				$this->calls[] = ['getParam', $name, $default];
				return array_key_exists($name, $params) === true ? $params[$name] : $default;
			}
		);
	}

	private function recordMigrations(): void {
		$this->migrationService->method('resolveRegisterAndSchema')->willReturnCallback(
			function ($registerId, $schemaId): array {
				$this->calls[] = ['resolve', $registerId, $schemaId];
				return ['register' => $this->register, 'schema' => $this->schema];
			}
		);
		$this->migrationService->method('migrateToBlobStorage')->willReturnCallback(
			function (Register $register, Schema $schema, int $batchSize, bool $dryRun): array {
				$this->assertSame($this->register, $register);
				$this->assertSame($this->schema, $schema);
				$this->calls[] = ['toBlob', $batchSize, $dryRun];
				return ['direction' => 'to-blob'];
			}
		);
		$this->migrationService->method('migrateToMagicTable')->willReturnCallback(
			function (Register $register, Schema $schema, int $batchSize, bool $dryRun): array {
				$this->assertSame($this->register, $register);
				$this->assertSame($this->schema, $schema);
				$this->calls[] = ['toMagic', $batchSize, $dryRun];
				return ['direction' => 'to-magic'];
			}
		);
	}

	private function migrationCalls(): array {
		return array_values(array_filter($this->calls, static fn (array $call): bool => $call[0] !== 'getParam'));
	}

	public function testToMagicRunsTheBlobMigrationFirstAndReturnsTheMagicReport(): void {
		$this->withParams(['register' => 'reg', 'schema' => 'sch', 'direction' => 'to-magic', 'batchSize' => '25', 'dryRun' => 'yes']);
		$this->recordMigrations();

		$response = $this->controller->migrate();

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['direction' => 'to-magic'], $response->getData());
		$this->assertSame(
			[['resolve', 'reg', 'sch'], ['toBlob', 25, true], ['toMagic', 25, true]],
			$this->migrationCalls()
		);
	}

	public function testToBlobRunsOnlyTheBlobMigrationWithDefaults(): void {
		$this->withParams(['register' => 7, 'schema' => 'sch', 'direction' => 'to-blob']);
		$this->recordMigrations();

		$response = $this->controller->migrate();

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['direction' => 'to-blob'], $response->getData());
		$this->assertSame([['resolve', 7, 'sch'], ['toBlob', 100, false]], $this->migrationCalls());
	}

	public function testParamsAreReadWithTheirDefaults(): void {
		$this->withParams(['register' => 'reg', 'schema' => 'sch', 'direction' => 'to-blob', 'dryRun' => 'off', 'batchSize' => 'x']);
		$this->recordMigrations();

		$this->controller->migrate();

		$this->assertSame(
			[
				['getParam', 'register', null],
				['getParam', 'schema', null],
				['getParam', 'direction', null],
				['getParam', 'batchSize', 100],
				['getParam', 'dryRun', false],
				['resolve', 'reg', 'sch'],
				['toBlob', 0, false],
			],
			$this->calls
		);
	}

	public function testEmptyRegisterOrSchemaIsA400(): void {
		$this->migrationService->expects($this->never())->method('resolveRegisterAndSchema');
		$this->withParams(['register' => '0', 'schema' => 'sch', 'direction' => 'nope']);

		$response = $this->controller->migrate();

		$this->assertSame(400, $response->getStatus());
		$this->assertSame(['error' => 'register and schema parameters are required'], $response->getData());
	}

	public function testUnknownDirectionIsA400(): void {
		$this->migrationService->expects($this->never())->method('resolveRegisterAndSchema');
		$this->withParams(['register' => 'reg', 'schema' => 'sch']);

		$response = $this->controller->migrate();

		$this->assertSame(400, $response->getStatus());
		$this->assertSame(['error' => 'direction must be "to-magic" or "to-blob"'], $response->getData());
	}

	public function testABlobFailureDuringToMagicIsA500WithItsMessage(): void {
		$this->withParams(['register' => 'reg', 'schema' => 'sch', 'direction' => 'to-magic']);
		$this->migrationService->method('resolveRegisterAndSchema')
			->willReturn(['register' => $this->register, 'schema' => $this->schema]);
		$this->migrationService->method('migrateToBlobStorage')->willThrowException(new Exception('blob failed'));
		$this->migrationService->expects($this->never())->method('migrateToMagicTable');

		$response = $this->controller->migrate();

		$this->assertSame(500, $response->getStatus());
		$this->assertSame(['error' => 'blob failed'], $response->getData());
	}

	public function testStatusReturnsTheStorageStatusOfTheResolvedPair(): void {
		$this->migrationService->method('resolveRegisterAndSchema')
			->with('reg', 'sch')
			->willReturn(['register' => $this->register, 'schema' => $this->schema]);
		$this->migrationService->expects($this->once())
			->method('getStorageStatus')
			->with($this->register, $this->schema)
			->willReturn(['blobCount' => 3, 'magicTableExists' => false]);

		$response = $this->controller->status('reg', 'sch');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['blobCount' => 3, 'magicTableExists' => false], $response->getData());
	}

	public function testStatusFailureIsA500WithItsMessage(): void {
		$this->migrationService->method('resolveRegisterAndSchema')->willThrowException(new Exception('not found'));

		$response = $this->controller->status('reg', 'sch');

		$this->assertSame(500, $response->getStatus());
		$this->assertSame(['error' => 'not found'], $response->getData());
	}
}
