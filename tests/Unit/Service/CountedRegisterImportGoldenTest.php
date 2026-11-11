<?php

/**
 * Golden test for CountedRegisterImport::runCall().
 *
 * Pins, on the code as it was before the run() refactor, the full result of
 * an import that created, updated and left registers and schemas unchanged,
 * one that recorded an import job, one that failed, and one that reported
 * success without creating the register; plus the read order around the import.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers take positional args by convention.

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\SchemaCatalog;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Service\CountedRegisterImport;
use OCA\OpenRegister\Service\RegisterImportCall;
use OCA\OpenRegister\Service\RegisterImportDocument;
use OCA\OpenRegister\Service\ShippedBaseline\ShippedAuthorizationDecision;
use PHPUnit\Framework\TestCase;

/**
 * Golden outcomes of CountedRegisterImport::run().
 */
class CountedRegisterImportGoldenTest extends TestCase {

	/**
	 * Calls in order.
	 *
	 * @var array<int, string>
	 */
	private array $calls = [];

	/**
	 * The register rows findAll returns, per call.
	 *
	 * @var array<int, array<int, Register>>
	 */
	private array $registerReads = [];

	/**
	 * The schema rows findMultiple returns, per call.
	 *
	 * @var array<int, array<int, Schema>>
	 */
	private array $schemaReads = [];

	/**
	 * The administrator's decision the import runs under.
	 *
	 * @var ShippedAuthorizationDecision
	 */
	private ShippedAuthorizationDecision $decision;

	private function import(): CountedRegisterImport {
		$this->calls    = [];
		$this->decision = new ShippedAuthorizationDecision();

		$registers = $this->createMock(RegisterMapperInterface::class);
		$registers->method('findAll')->willReturnCallback(
			function (): array {
				$this->calls[] = 'registers';
				return array_shift($this->registerReads) ?? [];
			}
		);

		$schemas = $this->createMock(SchemaMapperInterface::class);
		$schemas->method('findMultiple')->willReturnCallback(
			function (array $ids, bool $_rbac = true, bool $_multitenancy = true): array {
				$this->calls[] = 'schemas:' . json_encode($ids) . ':' . var_export($_rbac, true) . ':' . var_export($_multitenancy, true);
				return array_shift($this->schemaReads) ?? [];
			}
		);

		return new CountedRegisterImport(catalog: new SchemaCatalog(registers: $registers, schemas: $schemas, tables: $this->createMock(MagicMapperInterface::class)), decision: $this->decision);
	}//end import()

	/**
	 * A register row.
	 *
	 * @param string            $slug    The slug.
	 * @param string            $version The version.
	 * @param array<int, mixed> $schemas Schema ids as stored.
	 *
	 * @return Register
	 */
	private function register(string $slug, string $version, array $schemas = []): Register {
		$register = new Register();
		$register->setSlug($slug);
		$register->setVersion($version);
		$register->setTitle($slug);
		$register->setSchemas($schemas);
		return $register;
	}//end register()

	private function schema(int $id, string $version): Schema {
		$schema = new Schema();
		$schema->setId($id);
		$schema->setVersion($version);
		$schema->setTitle('s' . $id);
		return $schema;
	}//end schema()

	public function testCountsWhatTheImportCreatedUpdatedAndLeft(): void {
		$this->registerReads = [
			[$this->register('Zaken', '1.0', [1, '2', null]), $this->register('other', '1.0', [9]), $this->register('kept', '1.0')],
			[
				$this->register('zaken', '1.1', [1, '2', 3]),
				$this->register('kept', '1.0'),
				$this->register('new', '1.0', [3, ['x']]),
				$this->register('other', '2.0', [9]),
			],
		];
		$this->schemaReads   = [
			[$this->schema(1, '1'), $this->schema(2, '1')],
			[$this->schema(1, '1'), $this->schema(2, '2'), $this->schema(3, '1')],
		];

		$import = $this->import();
		$result = $import->runCall(
			document: (new RegisterImportDocument(slug: 'ZAKEN', slugs: ['zaken', 'kept', 'new']))->release(version: '1.1', fragments: 2),
			call: new RegisterImportCall(
				via: 'admin',
				operation: function (): array {
					$this->calls[] = 'import:' . $this->decision->via();
					$this->decision->noteReplaced(slug: 'case', roots: ['read', 'write']);
					return ['success' => true, 'path' => 'base', 'via' => 'occ', 'message' => ''];
				}
			)
		);

		$this->assertSame(
			[
				'registers',
				'schemas:[1,"2"]:false:false',
				'import:admin',
				'registers',
				'schemas:[1,"2",3]:false:false',
			],
			$this->calls
		);
		$this->assertSame(
			[
				'outcome'   => 'imported',
				'reason'    => null,
				'path'      => 'base',
				'via'       => 'occ',
				'version'   => '1.1',
				'fragments' => 2,
				'counts'    => [
					'registers'             => ['created' => 1, 'updated' => 1, 'unchanged' => 1],
					'schemas'               => ['created' => 1, 'updated' => 1, 'unchanged' => 1],
					'authorizationReplaced' => 2,
				],
				'replaced'  => [['schema' => 'case', 'path' => 'read'], ['schema' => 'case', 'path' => 'write']],
			],
			$result
		);
	}//end testCountsWhatTheImportCreatedUpdatedAndLeft()

	public function testARecordedImportJobIsReported(): void {
		$this->registerReads = [[], [$this->register('r', '1')]];
		$result = $this->import()->runCall(
			document: (new RegisterImportDocument(slug: 'r', slugs: ['r']))->release(version: '1', fragments: 0),
			call: new RegisterImportCall(
				via: 'ui',
				operation: static fn (): array => [
					'success' => true, 'path' => 'p', 'via' => 'v', 'message' => '', 'importJobId' => 'job-1', 'importAppId' => null,
				]
			)
		);

		$this->assertSame(['registers', 'registers'], $this->calls);
		$this->assertSame('imported', $result['outcome']);
		$this->assertSame(['created' => 1, 'updated' => 0, 'unchanged' => 0], $result['counts']['registers']);
		$this->assertSame(['created' => 0, 'updated' => 0, 'unchanged' => 0], $result['counts']['schemas']);
		$this->assertSame(
			['outcome', 'reason', 'path', 'via', 'version', 'fragments', 'counts', 'replaced', 'importJobId', 'importAppId'],
			array_keys($result)
		);
		$this->assertSame('job-1', $result['importJobId']);
		$this->assertSame('', $result['importAppId']);
	}//end testARecordedImportJobIsReported()

	public function testANonStringJobIdIsNotReported(): void {
		$this->registerReads = [[], [$this->register('r', '1')]];
		$result = $this->import()->runCall(
			document: (new RegisterImportDocument(slug: 'r', slugs: ['r']))->release(version: '1', fragments: 0),
			call: new RegisterImportCall(
				via: 'ui',
				operation: static fn (): array => ['success' => true, 'path' => 'p', 'via' => 'v', 'message' => '', 'importJobId' => 5, 'importAppId' => 'a']
			)
		);

		$this->assertArrayNotHasKey('importJobId', $result);
		$this->assertArrayNotHasKey('importAppId', $result);
	}//end testANonStringJobIdIsNotReported()

	public function testAFailedImportIsReportedWithItsMessageEvenWhenTheRegisterIsMissing(): void {
		$this->registerReads = [[], []];
		$result = $this->import()->runCall(
			document: (new RegisterImportDocument(slug: 'r', slugs: ['r']))->release(version: '2', fragments: 1),
			call: new RegisterImportCall(
				via: 'ui',
				operation: static fn (): array => [
					'success' => false, 'path' => 'file', 'via' => 'descriptor', 'message' => 'bad json', 'importJobId' => 'j', 'importAppId' => 'app',
				]
			)
		);

		$this->assertSame(
			[
				'outcome'     => 'failed',
				'reason'      => 'The import through descriptor failed: bad json',
				'path'        => 'file',
				'via'         => 'descriptor',
				'version'     => '2',
				'fragments'   => 1,
				'counts'      => [
					'registers'             => ['created' => 0, 'updated' => 0, 'unchanged' => 0],
					'schemas'               => ['created' => 0, 'updated' => 0, 'unchanged' => 0],
					'authorizationReplaced' => 0,
				],
				'replaced'    => [],
				'importJobId' => 'j',
				'importAppId' => 'app',
			],
			$result
		);
	}//end testAFailedImportIsReportedWithItsMessageEvenWhenTheRegisterIsMissing()

	public function testASuccessThatDidNotCreateTheRegisterIsAFailure(): void {
		$this->registerReads = [[], [$this->register('other', '1')]];
		$result = $this->import()->runCall(
			document: (new RegisterImportDocument(slug: 'Wanted', slugs: ['wanted', 'other']))->release(version: '1', fragments: 0),
			call: new RegisterImportCall(
				via: 'ui',
				operation: static fn (): array => ['success' => true, 'path' => 'p', 'via' => 'v', 'message' => 'ignored']
			)
		);

		$this->assertSame('failed', $result['outcome']);
		$this->assertSame('The import reported no error but register "Wanted" still does not exist.', $result['reason']);
		$this->assertSame(['created' => 1, 'updated' => 0, 'unchanged' => 0], $result['counts']['registers']);
	}//end testASuccessThatDidNotCreateTheRegisterIsAFailure()

	public function testALaterRegistersSchemaIdReplacesAnEarlierOneInPlace(): void {
		$this->registerReads = [[$this->register('a', '1', [5, 6]), $this->register('b', '1', ['5', 7])], []];
		$this->import()->runCall(
			document: (new RegisterImportDocument(slug: 'a', slugs: ['a', 'b']))->release(version: '1', fragments: 0),
			call: new RegisterImportCall(
				via: 'ui',
				operation: static fn (): array => ['success' => true, 'path' => 'p', 'via' => 'v', 'message' => '']
			)
		);

		$this->assertSame(['registers', 'schemas:["5",6,7]:false:false', 'registers'], $this->calls);
	}//end testALaterRegistersSchemaIdReplacesAnEarlierOneInPlace()
}//end class
