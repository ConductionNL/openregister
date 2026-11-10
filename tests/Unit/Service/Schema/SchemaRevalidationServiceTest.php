<?php

/**
 * Unit tests for SchemaRevalidationService.
 *
 * Covers the run lifecycle with mocked mappers and object operations: the
 * concurrent-run refusal, the run record start() creates, the population
 * count and its failure fallback, the batch read, valid/invalid counting with
 * one entry per invalid object, cursor and processed bookkeeping, a dry run
 * against a proposed definition, and completion when no objects remain.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Schema
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/schema-migration/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Schema;

use OCA\OpenRegister\Service\Schema\RevalidationRequest;
use OCA\OpenRegister\Service\Schema\SchemaPopulationRuns;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Db\SchemaRun;
use OCA\OpenRegister\Db\SchemaRunEntry;
use OCA\OpenRegister\Db\SchemaRunEntryMapper;
use OCA\OpenRegister\Db\SchemaRunMapper;
use OCA\OpenRegister\Exception\SchemaRunConcurrencyException;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\Object\ValidateObjectInterface;
use OCA\OpenRegister\Service\Schema\SchemaRevalidationService;
use Opis\JsonSchema\Errors\ValidationError;
use Opis\JsonSchema\ValidationResult;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Schema\SchemaRevalidationService
 * @uses   \OCA\OpenRegister\Db\SchemaRun
 * @uses   \OCA\OpenRegister\Db\SchemaRunEntry
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 * @uses   \OCA\OpenRegister\Db\Schema
 */
class SchemaRevalidationServiceTest extends TestCase {

	private SchemaRevalidationService $service;

	private SchemaRunMapper&MockObject $runMapper;

	private SchemaRunEntryMapper&MockObject $runEntryMapper;

	private SchemaMapperInterface&MockObject $schemaMapper;

	private ObjectOperationsInterface&MockObject $objectService;

	private ValidateObjectInterface&MockObject $validator;

	/**
	 * Build the service over fresh mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->runMapper      = $this->createMock(SchemaRunMapper::class);
		$this->runEntryMapper = $this->createMock(SchemaRunEntryMapper::class);
		$this->schemaMapper   = $this->createMock(SchemaMapperInterface::class);
		$this->objectService  = $this->createMock(ObjectOperationsInterface::class);
		$this->validator      = $this->createMock(ValidateObjectInterface::class);

		$this->service = new SchemaRevalidationService(
			new SchemaPopulationRuns($this->runMapper, $this->runEntryMapper, $this->schemaMapper, $this->objectService),
			$this->validator
		);
	}//end setUp()

	/**
	 * An object with an id, uuid and data.
	 *
	 * @param int    $id   The id.
	 * @param string $uuid The uuid.
	 *
	 * @return ObjectEntity The object.
	 */
	private function obj(int $id, string $uuid): ObjectEntity {
		$o = new ObjectEntity();
		$o->setId($id);
		$o->setUuid($uuid);
		$o->setObject(['n' => $id]);
		return $o;
	}//end obj()

	/**
	 * A running revalidation run with a cursor and processed count.
	 *
	 * @param array|null $proposed The proposed definition.
	 *
	 * @return SchemaRun The run.
	 */
	private function makeRun(?array $proposed=null): SchemaRun {
		$run = new SchemaRun();
		$run->setId(7);
		$run->setSchemaId(1);
		$run->setRegisterId(2);
		$run->setType(SchemaRun::TYPE_REVALIDATION);
		$run->setState(SchemaRun::STATE_RUNNING);
		$run->setProposedDefinition($proposed);
		$run->setProcessed(3);
		$run->setCursor(5);
		$run->setReport(['valid' => 1, 'invalid' => 2]);
		return $run;
	}//end makeRun()

	/**
	 * A second run on a schema with an active run is refused.
	 *
	 * @return void
	 */
	public function testStartRefusesConcurrentRun(): void {
		$active = new SchemaRun();
		$active->setId(99);
		$this->runMapper->method('findActiveForSchema')->with(1)->willReturn($active);
		$this->runMapper->expects($this->never())->method('createFromArray');

		$this->expectException(SchemaRunConcurrencyException::class);
		$this->expectExceptionMessage('An active run (#99) already exists for schema 1.');
		$this->service->startRevalidation(new RevalidationRequest(1, 2));
	}//end testStartRefusesConcurrentRun()

	/**
	 * start() records the run with the counted population.
	 *
	 * @return void
	 */
	public function testStartCreatesTheRunWithThePopulationCount(): void {
		$this->runMapper->method('findActiveForSchema')->willReturn(null);
		$this->objectService->expects($this->once())->method('count')
			->with(['filters' => ['register' => 2, 'schema' => 1]])
			->willReturn(42);
		$created = new SchemaRun();
		$this->runMapper->expects($this->once())->method('createFromArray')
			->with(
				[
					'schemaId' => 1,
					'registerId' => 2,
					'type' => SchemaRun::TYPE_REVALIDATION,
					'state' => SchemaRun::STATE_RUNNING,
					'proposedDefinition' => ['properties' => []],
					'total' => 42,
					'startedBy' => 'alice',
					'report' => ['valid' => 0, 'invalid' => 0],
				]
			)
			->willReturn($created);

		$this->assertSame($created, $this->service->startRevalidation(new RevalidationRequest(1, 2, ['properties' => []]), 'alice'));
	}//end testStartCreatesTheRunWithThePopulationCount()

	/**
	 * A failing count starts the run with a total of zero.
	 *
	 * @return void
	 */
	public function testStartCountsZeroWhenCountingFails(): void {
		$this->runMapper->method('findActiveForSchema')->willReturn(null);
		$this->objectService->method('count')->willThrowException(new \RuntimeException('down'));
		$this->runMapper->expects($this->once())->method('createFromArray')
			->with($this->callback(static fn (array $a): bool => $a['total'] === 0))
			->willReturn(new SchemaRun());

		$this->service->startRevalidation(new RevalidationRequest(1, 2));
	}//end testStartCountsZeroWhenCountingFails()

	/**
	 * A batch reads after the processed offset, counts valid and invalid, and
	 * records one entry per invalid object.
	 *
	 * @return void
	 */
	public function testBatchCountsAndRecordsInvalidObjects(): void {
		$this->schemaMapper->method('find')->with(1)->willReturn(new Schema());
		$this->objectService->expects($this->once())->method('findAll')
			->with(
				[
					'limit' => 50,
					'offset' => 3,
					'sort' => ['id' => 'ASC'],
					'filters' => ['register' => 2, 'schema' => 1],
				],
				false,
				false
			)
			->willReturn([$this->obj(9, 'u-9'), 'not an entity', $this->obj(6, 'u-6')]);

		$error = $this->createMock(ValidationError::class);
		$error->method('message')->willReturn('bad n');
		$this->validator->method('validateObject')->willReturnCallback(
			static fn (array $data): ValidationResult => ($data['n'] === 9 ? new ValidationResult(null) : new ValidationResult($error))
		);
		$this->runEntryMapper->expects($this->once())->method('createFromArray')
			->with(['runId' => 7, 'objectUuid' => 'u-6', 'outcome' => SchemaRunEntry::OUTCOME_INVALID, 'message' => 'bad n'])
			->willReturn(new SchemaRunEntry());
		$this->runMapper->expects($this->once())->method('save');

		$run = $this->makeRun();
		$this->assertTrue($this->service->processBatch($run, 50));
		$this->assertSame(9, $run->getCursor());
		$this->assertSame(5, $run->getProcessed());
		$this->assertSame(['valid' => 2, 'invalid' => 3], $run->getReport());
		$this->assertSame(SchemaRun::STATE_RUNNING, $run->getState());
	}//end testBatchCountsAndRecordsInvalidObjects()

	/**
	 * A dry run validates against a transient schema built from the proposal.
	 *
	 * @return void
	 */
	public function testDryRunValidatesAgainstTheProposedDefinition(): void {
		$stored = new Schema();
		$stored->setTitle('T');
		$stored->setSlug('t');
		$this->schemaMapper->method('find')->willReturn($stored);
		$this->objectService->method('findAll')->willReturn([$this->obj(8, 'u-8')]);
		$this->validator->expects($this->once())->method('validateObject')
			->with(
				$this->callback(static fn (array $d): bool => $d['n'] === 8),
				$this->callback(
					static fn (Schema $s): bool => $s !== $stored && $s->getSlug() === 't'
						&& $s->getProperties() === ['p' => ['type' => 'string']] && $s->getRequired() === ['p']
				)
			)
			->willThrowException(new \RuntimeException('boom'));
		$this->runEntryMapper->expects($this->once())->method('createFromArray')
			->with($this->callback(static fn (array $a): bool => $a['message'] === 'boom'))
			->willReturn(new SchemaRunEntry());

		$run = $this->makeRun(['properties' => ['p' => ['type' => 'string']], 'required' => ['p']]);
		$this->assertTrue($this->service->processBatch($run));
		$this->assertSame(['valid' => 1, 'invalid' => 3], $run->getReport());
	}//end testDryRunValidatesAgainstTheProposedDefinition()

	/**
	 * An empty batch completes the run.
	 *
	 * @return void
	 */
	public function testEmptyBatchCompletesTheRun(): void {
		$this->schemaMapper->method('find')->willReturn(new Schema());
		$this->objectService->method('findAll')->willReturn([]);
		$this->runMapper->expects($this->once())->method('save');

		$run = $this->makeRun();
		$this->assertFalse($this->service->processBatch($run));
		$this->assertSame(SchemaRun::STATE_COMPLETED, $run->getState());
		$this->assertSame(5, $run->getCursor());
	}//end testEmptyBatchCompletesTheRun()
}//end class
