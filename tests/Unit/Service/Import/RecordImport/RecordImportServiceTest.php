<?php

/**
 * RecordImportService: the file's rows through the streaming save, identity by the nominated property.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Import\RecordImport
 * @author   Conduction <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/openregister
 *
 * @spec openspec/changes/widget-record-import/specs/widget-record-import/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Import\RecordImport;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Import\RecordImport\ColumnMapper;
use OCA\OpenRegister\Service\Import\RecordImport\RecordFileReader;
use OCA\OpenRegister\Service\Import\RecordImport\RecordImportRequest;
use OCA\OpenRegister\Service\Import\RecordImport\RecordImportService;
use OCA\OpenRegister\Service\Object\BatchOperationStatus;
use OCA\OpenRegister\Service\Object\RowFailure;
use OCA\OpenRegister\Service\Object\SaveObject\StreamingSave;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The object service is the persistence boundary: its streaming save is stubbed to
 * consume the rows the way the real one does and record an outcome per row.
 *
 * @covers \OCA\OpenRegister\Service\Import\RecordImport\RecordImportService
 * @covers \OCA\OpenRegister\Service\Import\RecordImport\RecordImportRequest
 * @uses   \OCA\OpenRegister\Service\Import\RecordImport\RecordFileReader
 * @uses   \OCA\OpenRegister\Service\Import\RecordImport\ColumnMapper
 * @uses   \OCA\OpenRegister\Service\Import\RecordImport\ConfirmedMapping
 * @uses   \OCA\OpenRegister\Service\Object\BatchOperationStatus
 * @uses   \OCA\OpenRegister\Service\Object\RowFailure
 * @uses   \OCA\OpenRegister\Service\Object\SaveObject\StreamingSave
 * @uses   \OCA\OpenRegister\Db\Register
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 */
class RecordImportServiceTest extends TestCase {

	/**
	 * The object service.
	 *
	 * @var ObjectService&MockObject
	 */
	private ObjectService&MockObject $objects;

	/**
	 * The rows the save received.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $saved = [];

	/**
	 * The file written by a test.
	 *
	 * @var string
	 */
	private string $path = '';

	protected function setUp(): void {
		$this->objects = $this->createMock(ObjectService::class);
	}

	protected function tearDown(): void {
		if ($this->path !== '') {
			@unlink($this->path);
		}
	}

	/**
	 * Stub the streaming save: a row with `id` updates, one without creates, one with reference "BAD" fails.
	 *
	 * @return void
	 */
	private function streamingSave(): void {
		$this->objects->expects($this->once())->method('saveObjectsStreaming')->willReturnCallback(
			function (StreamingSave $save): BatchOperationStatus {
				$this->assertSame(7, $save->register->getId());
				$this->assertSame(9, $save->schema->getId());
				$status = new BatchOperationStatus();
				$index = -1;
				foreach ($save->rows as $row) {
					$index++;
					$this->saved[] = $row;
					if (($row['reference'] ?? '') === 'BAD') {
						$status->recordFailed(new RowFailure(null, 'reference is not valid', 'Exception', $index));
						continue;
					}

					if (isset($row['id']) === true) {
						$status->recordUpdated($row['id']);
						continue;
					}

					$status->recordCreated('new-' . ($row['reference'] ?? 'unnamed'));
				}

				$status->recordReferenceCacheMiss();
				$status->recordReferenceCacheHit();
				return $status;
			}
		);
	}

	private function request(string $csv, ?string $identifier): RecordImportRequest {
		$this->path = tempnam(sys_get_temp_dir(), 'imp') . '.csv';
		file_put_contents($this->path, $csv);
		$register = new Register();
		$register->setId(7);
		$schema = new Schema();
		$schema->setId(9);
		$decisions = ['ref' => 'reference', 'Plaats' => 'city', 'note' => null];
		$mapping = (new ColumnMapper())->confirm(['ref', 'Plaats', 'note'], ['reference', 'city'], $decisions, $identifier);

		return new RecordImportRequest($this->path, RecordFileReader::CSV, $register, $schema, $mapping);
	}

	private function service(): RecordImportService {
		return new RecordImportService($this->objects, new RecordFileReader());
	}

	public function testWithoutAnIdentifierEveryRowIsCreatedAndAFailureNamesItsLine(): void {
		$this->streamingSave();
		$this->objects->expects($this->never())->method('searchObjects');

		$result = $this->service()->import($this->request("ref,Plaats,note\nA-1,Utrecht,x\nBAD,Zeist,y\nA-3,,z\n", null));

		$this->assertSame(
			[['reference' => 'A-1', 'city' => 'Utrecht'], ['reference' => 'BAD', 'city' => 'Zeist'], ['reference' => 'A-3']],
			$this->saved
		);
		$this->assertSame(2, $result['createdCount']);
		$this->assertSame(0, $result['updatedCount']);
		$this->assertSame(1, $result['failedCount']);
		$this->assertSame([['line' => 3, 'reason' => 'reference is not valid']], $result['failures']);
		$this->assertSame(['new-A-1', 'new-A-3'], $result['created']);
		$this->assertSame(1, $result['referenceCacheMisses']);
		$this->assertSame(1, $result['referenceCacheHits']);
		$this->assertSame('No identifying column is nominated: every row will be created as a new object.', $result['statement']);
	}

	public function testARowMatchingOneObjectByTheIdentifierUpdatesIt(): void {
		$this->streamingSave();
		$existing = new ObjectEntity();
		$existing->setUuid('uuid-a1');
		$queries = [];
		$this->objects->method('searchObjects')->willReturnCallback(
			function (array $query) use (&$queries, $existing): array {
				$queries[] = $query;
				if ($query['reference'] === 'A-1') {
					return [$existing];
				}

				return [];
			}
		);

		$result = $this->service()->import($this->request("ref,Plaats,note\nA-1,Utrecht,x\nA-2,Zeist,y\n", 'reference'));

		$this->assertSame(
			[['reference' => 'A-1', 'city' => 'Utrecht', 'id' => 'uuid-a1'], ['reference' => 'A-2', 'city' => 'Zeist']],
			$this->saved
		);
		$this->assertSame(['@self' => ['register' => 7, 'schema' => 9], 'reference' => 'A-1', '_limit' => 2], $queries[0]);
		$this->assertSame(1, $result['updatedCount']);
		$this->assertSame(1, $result['createdCount']);
		$this->assertSame(['uuid-a1'], $result['updated']);
	}

	public function testAnIdentifierMatchingTwoObjectsIsRefusedNotGuessed(): void {
		$this->streamingSave();
		$one = ['@self' => ['id' => 'u1']];
		$two = ['@self' => ['id' => 'u2']];
		$this->objects->method('searchObjects')->willReturnCallback(
			static function (array $query) use ($one, $two): array {
				if ($query['reference'] === 'DUP') {
					return [$one, $two];
				}

				return [];
			}
		);

		$result = $this->service()->import($this->request("ref,Plaats,note\nDUP,Utrecht,x\nBAD,Zeist,y\n", 'reference'));

		$this->assertSame([['reference' => 'BAD', 'city' => 'Zeist']], $this->saved);
		$this->assertSame(
			[
				['line' => 2, 'reason' => 'More than one object has reference "DUP"; this row is not imported.'],
				['line' => 3, 'reason' => 'reference is not valid'],
			],
			$result['failures']
		);
		$this->assertSame(2, $result['failedCount']);
		$this->assertSame(0, $result['createdCount']);
	}

	public function testARowWithoutAnIdentifierValueIsCreatedWithoutALookup(): void {
		$this->streamingSave();
		$this->objects->expects($this->never())->method('searchObjects');

		$result = $this->service()->import($this->request("ref,Plaats,note\n,Utrecht,x\n", 'reference'));

		$this->assertSame([['city' => 'Utrecht']], $this->saved);
		$this->assertSame(1, $result['createdCount']);
	}
}
