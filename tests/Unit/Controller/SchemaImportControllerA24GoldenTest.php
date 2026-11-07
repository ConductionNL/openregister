<?php

/**
 * Golden outcomes of the standards-based schema import surface.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\SchemaImportController;
use OCA\OpenRegister\Controller\Support\SchemaImportControllerServices;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Exception\SchemaImportException;
use OCA\OpenRegister\Service\Schema\SchemaChangeSet;
use OCA\OpenRegister\Service\Schema\SchemaVersioningService;
use OCA\OpenRegister\Service\SchemaImport\ImportedSchema;
use OCA\OpenRegister\Service\SchemaImport\ImportOptions;
use OCA\OpenRegister\Service\SchemaImport\SchemaImportService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Pins status + body + side effects of every SchemaImportController action.
 */
class SchemaImportControllerA24GoldenTest extends TestCase {
	private array $params = [];
	private SchemaImportService&MockObject $service;
	private SchemaMapperInterface&MockObject $schemas;
	private RegisterMapperInterface&MockObject $registers;
	private SchemaVersioningService&MockObject $versioning;
	private LoggerInterface&MockObject $logger;
	private SchemaImportController $controller;

	protected function setUp(): void {
		parent::setUp();
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			fn (string $key, mixed $default = null): mixed => (array_key_exists($key, $this->params) ? $this->params[$key] : $default)
		);
		$request->method('getParams')->willReturnCallback(fn (): array => $this->params);
		$this->service = $this->createMock(SchemaImportService::class);
		$this->schemas = $this->createMock(SchemaMapperInterface::class);
		$this->registers = $this->createMock(RegisterMapperInterface::class);
		$this->versioning = $this->createMock(SchemaVersioningService::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->controller = new SchemaImportController(
			'openregister',
			$request,
			new SchemaImportControllerServices(
				importService: $this->service,
				schemaMapper: $this->schemas,
				registerMapper: $this->registers,
				schemaVersioning: $this->versioning
			),
			$this->logger
		);
	}

	private function pair($response): array {
		return [$response->getStatus(), $response->getData()];
	}

	private function imported(): ImportedSchema {
		return new ImportedSchema('Persoon', 'd', ['naam' => []], [], ['dialect' => 'ggm'], ['x']);
	}

	private function persisted(int $id): Schema {
		$schema = new Schema();
		$schema->setId($id);
		return $schema;
	}

	public function testDiscoveryAndSnapshotPassThroughOrMapTheFailure(): void {
		$this->params = ['q' => 'pers'];
		$this->service->method('discover')->with('ggm', 'pers')->willReturnOnConsecutiveCalls(['r'], $this->throwException(new SchemaImportException('unknown', 404)));
		$this->service->method('snapshotInfo')->willReturnOnConsecutiveCalls(['v' => 1], $this->throwException(new SchemaImportException('gone', 410)));

		$this->assertSame([200, ['r']], $this->pair($this->controller->types('ggm')));
		$this->assertSame([404, ['error' => 'unknown']], $this->pair($this->controller->types('ggm')));
		$this->assertSame([200, ['v' => 1]], $this->pair($this->controller->snapshot('ggm')));
		$this->assertSame([410, ['error' => 'gone']], $this->pair($this->controller->snapshot('ggm')));
	}

	public function testImportNeedsAReference(): void {
		$this->params = ['reference' => ''];
		$this->service->expects($this->never())->method('import');

		$this->assertSame(
			[400, ['error' => 'A "reference" (type IRI / bare name / objecttype id) is required.']],
			$this->pair($this->controller->import('ggm'))
		);
	}

	public function testImportMapsServiceAndPersistFailures(): void {
		$this->params = ['reference' => 'Persoon'];
		$this->service->method('import')->willReturnOnConsecutiveCalls(
			$this->throwException(new SchemaImportException('no such type', 422)),
			$this->imported()
		);
		$this->schemas->method('createFromArray')->willThrowException(new RuntimeException('db'));
		$this->logger->expects($this->once())->method('error')
			->with('[SchemaImportController] Failed to persist imported schema', ['error' => 'db']);

		$this->assertSame([422, ['error' => 'no such type']], $this->pair($this->controller->import('ggm')));
		$this->assertSame([500, ['error' => 'Failed to persist the imported schema: db']], $this->pair($this->controller->import('ggm')));
	}

	public function testImportPersistsAndAssociatesWithTheTargetRegister(): void {
		$this->params = ['reference' => 'Persoon', 'targetRegister' => '4'];
		$imported = $this->imported();
		$this->service->method('import')->with('ggm', 'Persoon', $this->isInstanceOf(ImportOptions::class))->willReturn($imported);
		$this->schemas->method('createFromArray')->with($imported->toSchemaArray())->willReturn($this->persisted(8));
		$register = new Register();
		$register->setSchemas([1]);
		$this->registers->method('find')->with(4)->willReturn($register);
		$this->registers->expects($this->once())->method('update')->with($register);

		$response = $this->controller->import('ggm');

		$this->assertSame([201, ['schema' => $this->persisted(8)->jsonSerialize(), 'unknownRequested' => ['x']]], $this->pair($response));
		$this->assertSame([1, 8], $register->getSchemas());
	}

	public function testAnAlreadyAssociatedOrFailingRegisterDoesNotFailTheImport(): void {
		$this->params = ['reference' => 'Persoon', 'targetRegister' => 4];
		$this->service->method('import')->willReturn($this->imported());
		$this->schemas->method('createFromArray')->willReturn($this->persisted(8));
		$register = new Register();
		$register->setSchemas(['8']);
		$this->registers->method('find')->willReturnOnConsecutiveCalls($register, $this->throwException(new RuntimeException('gone')));
		$this->registers->expects($this->never())->method('update');
		$this->logger->expects($this->once())->method('warning')->with(
			'[SchemaImportController] Could not associate imported schema with register',
			['register' => 4, 'schema' => 8, 'error' => 'gone']
		);

		$this->assertSame(201, $this->controller->import('ggm')->getStatus());
		$this->assertSame(201, $this->controller->import('ggm')->getStatus());
	}

	private function importedSchema(): Schema {
		$schema = $this->persisted(5);
		$schema->setProperties(['a' => []]);
		$schema->setRequired(['a']);
		$schema->setVersion('1.0.0');
		$schema->setConfiguration(['importSource' => ['dialect' => 'ggm']]);
		return $schema;
	}

	private function diff(bool $applied): array {
		return [
			'added' => ['b'], 'removed' => [], 'changed' => [], 'keptLocal' => [], 'conflicts' => ['c'],
			'applied' => $applied, 'merged' => ['a' => [], 'b' => []],
			'incomingSource' => ['dialect' => 'ggm', 'rev' => 2], 'jsonld' => ['@type' => 'P'],
		];
	}

	private function preview(bool $applied): array {
		return ['added' => ['b'], 'removed' => [], 'changed' => [], 'keptLocal' => [], 'conflicts' => ['c'], 'applied' => $applied];
	}

	public function testReimportRefusals(): void {
		$plain = $this->persisted(5);
		$this->schemas->method('find')->willReturnOnConsecutiveCalls(
			$this->throwException(new DoesNotExistException('x')),
			$plain,
			$this->importedSchema()
		);
		$this->service->method('previewUpdateFromSource')->willThrowException(new SchemaImportException('source gone', 502));

		$this->assertSame([404, ['error' => 'Schema not found']], $this->pair($this->controller->reimport(5)));
		$this->assertSame(
			[422, ['error' => 'This schema was not imported from a standard; nothing to update from.']],
			$this->pair($this->controller->reimport(5))
		);
		$this->assertSame([502, ['error' => 'source gone']], $this->pair($this->controller->reimport(5)));
	}

	public function testReimportPreviewsByDefaultAndRefusesUnresolvedConflicts(): void {
		$this->schemas->method('find')->willReturn($this->importedSchema());
		$this->params = ['resolveConflicts' => ['c', 3]];
		$seen = [];
		$this->service->method('previewUpdateFromSource')->willReturnCallback(
			function (array $source, array $current, array $resolved) use (&$seen): array {
				$seen[] = [$source, $current, $resolved];
				return $this->diff(applied: false);
			}
		);
		$this->schemas->expects($this->never())->method('update');

		$this->assertSame([200, $this->preview(applied: false)], $this->pair($this->controller->reimport(5)));

		$this->params = ['resolveConflicts' => 'c', 'apply' => 'true'];
		$this->assertSame(
			[409, ['error' => 'Unresolved conflicts. Confirm each conflicting property via "resolveConflicts" before applying.', 'conflicts' => ['c']]],
			$this->pair($this->controller->reimport(5))
		);
		$this->assertSame([[['dialect' => 'ggm'], ['a' => []], ['c']], [['dialect' => 'ggm'], ['a' => []], []]], $seen);
	}

	public function testAnAppliedReimportIsVersionedAndLogged(): void {
		$schema = $this->importedSchema();
		$this->schemas->method('find')->willReturn($schema);
		$this->params = ['apply' => true];
		$this->service->method('previewUpdateFromSource')->willReturn($this->diff(applied: true));
		$changes = new SchemaChangeSet([['kind' => 'added']], 'additive', 'minor');
		$this->versioning->expects($this->once())->method('classify')
			->with($schema, ['properties' => ['a' => [], 'b' => []], 'required' => ['a']])
			->willReturn($changes);
		$this->versioning->method('nextVersion')->willReturn('1.1.0');
		$this->schemas->expects($this->once())->method('update')->with($schema)->willReturnArgument(0);
		$this->versioning->expects($this->once())->method('recordChangelog')->with(5, '1.1.0', $changes, false, 'source merge');

		$response = $this->controller->reimport(5);

		$this->assertSame('1.1.0', $schema->getVersion());
		$this->assertSame(
			['importSource' => ['dialect' => 'ggm', 'rev' => 2, 'baseline' => ['a' => [], 'b' => []]], 'jsonld' => ['@type' => 'P']],
			$schema->getConfiguration()
		);
		$this->assertSame([200, ['schema' => $schema->jsonSerialize(), 'applied' => $this->preview(applied: true)]], $this->pair($response));
	}

	public function testAnAppliedReimportWithoutChangesKeepsTheVersionAndMapsPersistFailure(): void {
		$schema = $this->importedSchema();
		$this->schemas->method('find')->willReturn($schema);
		$this->params = ['apply' => '1'];
		$diff = $this->diff(applied: true);
		unset($diff['incomingSource']);
		$diff['jsonld'] = [];
		$this->service->method('previewUpdateFromSource')->willReturn($diff);
		$this->versioning->method('classify')->willReturn(new SchemaChangeSet([], 'none', 'none'));
		$this->versioning->expects($this->never())->method('nextVersion');
		$this->schemas->method('update')->willThrowException(new RuntimeException('locked'));
		$this->versioning->expects($this->never())->method('recordChangelog');

		$response = $this->controller->reimport(5);

		$this->assertSame('1.0.0', $schema->getVersion());
		$this->assertSame(['importSource' => ['dialect' => 'ggm']], $schema->getConfiguration());
		$this->assertSame([500, ['error' => 'Failed to persist the imported schema: locked']], $this->pair($response));
	}
}
