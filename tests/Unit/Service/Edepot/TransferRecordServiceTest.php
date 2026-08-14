<?php

/**
 * Unit tests for {@see \OCA\OpenRegister\Service\Edepot\TransferRecordService}.
 *
 * Durable persistence (archival-transfer-hardening, OR-AD-3): transfer-list
 * round-trip through ObjectService, and write-once proof-of-transfer creation
 * (a second create for the same (transfer, object) pair returns the existing
 * proof instead of duplicating).
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Edepot
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/archival-transfer-hardening/specs/edepot-proof-of-transfer/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\Edepot;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Edepot\TransferRecordService;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * TransferRecordServiceTest.
 */
class TransferRecordServiceTest extends TestCase {

	private ObjectService&MockObject $objectService;

	private TransferRecordService $service;

	protected function setUp(): void {
		$this->objectService = $this->createMock(ObjectService::class);
		$this->service = new TransferRecordService(
			objectService: $this->objectService,
			logger: $this->createMock(LoggerInterface::class),
		);

	}//end setUp()

	/**
	 * A transfer list round-trips: saved to the register schema and re-read.
	 *
	 * @return void
	 */
	public function testSaveAndLoadTransferList(): void {
		$entity = new ObjectEntity();
		$entity->setUuid('t1');
		$entity->setObject(['status' => 'approved', 'objectReferences' => []]);

		$savedArgs = null;
		$this->objectService->method('saveObject')->willReturnCallback(
			function ($object, $extend = [], $register = null, $schema = null, $uuid = null) use ($entity, &$savedArgs) {
				$savedArgs = ['register' => $register, 'schema' => $schema, 'uuid' => $uuid];
				return $entity;
			}
		);

		$saved = $this->service->saveTransferList(['uuid' => 't1', 'status' => 'approved', 'objectReferences' => []]);

		$this->assertSame(TransferRecordService::REGISTER_SLUG, $savedArgs['register']);
		$this->assertSame(TransferRecordService::TRANSFER_SCHEMA_SLUG, $savedArgs['schema']);
		$this->assertSame('t1', $savedArgs['uuid']);
		$this->assertSame('t1', $saved['uuid']);

		// Load reads it back through the same register/schema.
		$this->objectService->method('find')->willReturn($entity);
		$loaded = $this->service->loadTransferList('t1');
		$this->assertSame('t1', $loaded['uuid']);
		$this->assertSame('approved', $loaded['status']);

	}//end testSaveAndLoadTransferList()

	/**
	 * A proof is created once; a second create for the same pair returns the
	 * existing proof (write-once).
	 *
	 * @return void
	 */
	public function testProofIsWriteOnce(): void {
		// First call: no existing proof, so createObject runs.
		$created = new ObjectEntity();
		$created->setUuid('proof-1');
		$created->setObject(['objectUuid' => 'o1', 'transferUuid' => 't1', 'eDepotReference' => 'ARCH-9']);

		$findAllCalls = 0;
		$this->objectService->method('findAll')->willReturnCallback(
			function () use (&$findAllCalls) {
				$findAllCalls++;
				if ($findAllCalls === 1) {
					// No existing proof on first create.
					return [];
				}

				// Existing proof on the second create.
				return [['uuid' => 'proof-1', 'objectUuid' => 'o1', 'transferUuid' => 't1']];
			}
		);

		$this->objectService->expects($this->once())->method('createObject')->willReturn($created);

		$first = $this->service->createProof(
			proof: ['objectUuid' => 'o1', 'transferUuid' => 't1', 'eDepotReference' => 'ARCH-9']
		);
		$this->assertSame('proof-1', $first['uuid']);

		// Second create must NOT call createObject again (expects once above).
		$second = $this->service->createProof(
			proof: ['objectUuid' => 'o1', 'transferUuid' => 't1', 'eDepotReference' => 'ARCH-9']
		);
		$this->assertSame('proof-1', $second['uuid']);

	}//end testProofIsWriteOnce()

	/**
	 * Enumeration failure degrades to an empty list, never raises.
	 *
	 * @return void
	 */
	public function testListDegradesOnFailure(): void {
		$this->objectService->method('findAll')->willThrowException(new \RuntimeException('db down'));

		$this->assertSame([], $this->service->listTransferLists());

	}//end testListDegradesOnFailure()
}//end class
