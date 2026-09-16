<?php

/**
 * SharedMasterDataServiceTest — what the share admits, and what it must not.
 *
 * Two failures are worth the whole test file. The first is the one that makes
 * the feature pointless: a consumer that reads nothing, because the resolver
 * answered with the holder's rows only when the consumer already held them.
 * The second is the one that makes it dangerous: a consumer that reads
 * EVERYTHING the holder owns rather than the one register that was declared.
 * Every test below is aimed at one of those two.
 *
 * The third failure is quieter and is tested here too: an organisation that
 * declared nothing must resolve to nothing, so an instance that has never heard
 * of this feature is byte for byte unchanged.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/changes/several-legal-entities-in-one-instance/specs/saas-multi-tenant/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Exception\SharedMasterDataWriteException;
use OCA\OpenRegister\Service\SharedMasterDataService;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\SharedMasterDataService
 * @covers \OCA\OpenRegister\Exception\SharedMasterDataWriteException
 */
class SharedMasterDataServiceTest extends TestCase {

	/**
	 * Organisation A, which holds the code list.
	 */
	private const ORG_A = 'org-a-uuid';

	/**
	 * Organisation B, which consumes it.
	 */
	private const ORG_B = 'org-b-uuid';

	/**
	 * Organisation C, which was never named in any declaration.
	 */
	private const ORG_C = 'org-c-uuid';

	/**
	 * Build the service over a fixed set of rows per table.
	 *
	 * The rows are the ACTUAL database shape — `shared_with` arrives as a JSON
	 * STRING, because that is what a TEXT column returns, and a test that fed
	 * the resolver a ready-made PHP array would pass while the real decoding
	 * was broken.
	 *
	 * @param array<string, array<int, array<string, mixed>>> $rowsByTable Rows keyed by table name.
	 *
	 * @return SharedMasterDataService The service under test.
	 */
	private function service(array $rowsByTable): SharedMasterDataService {
		$db = $this->createMock(IDBConnection::class);

		$db->method('getQueryBuilder')->willReturnCallback(
			function () use ($rowsByTable): IQueryBuilder {
				return $this->queryBuilder(rowsByTable: $rowsByTable);
			}
		);

		return new SharedMasterDataService(db: $db);

	}//end service()

	/**
	 * A query builder that remembers which table it was pointed at and hands back that table's rows.
	 *
	 * @param array<string, array<int, array<string, mixed>>> $rowsByTable Rows keyed by table name.
	 *
	 * @return IQueryBuilder&MockObject The query builder.
	 */
	private function queryBuilder(array $rowsByTable): IQueryBuilder {
		$table = '';

		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('isNotNull')->willReturn('shared_with IS NOT NULL');
		$expr->method('eq')->willReturn('uuid = :uuid');

		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('expr')->willReturn($expr);
		$qb->method('select')->willReturnSelf();
		$qb->method('where')->willReturnSelf();
		$qb->method('setMaxResults')->willReturnSelf();
		$qb->method('createNamedParameter')->willReturn(':p');
		$qb->method('from')->willReturnCallback(
			function (string $from) use (&$table, $qb): IQueryBuilder {
				$table = $from;
				return $qb;
			}
		);

		$qb->method('executeQuery')->willReturnCallback(
			function () use (&$table, $rowsByTable): IResult {
				$rows = ($rowsByTable[$table] ?? []);
				$cursor = 0;

				$result = $this->createMock(IResult::class);
				$result->method('fetch')->willReturnCallback(
					function () use ($rows, &$cursor): array|false {
						if ($cursor >= count($rows)) {
							return false;
						}

						$row = $rows[$cursor];
						$cursor++;
						return $row;
					}
				);
				$result->method('fetchOne')->willReturn(false);

				return $result;
			}
		);

		return $qb;

	}//end queryBuilder()

	/**
	 * Registers: one code list held by A and declared readable by B, one private register held by A.
	 *
	 * @return array<string, array<int, array<string, mixed>>> The rows.
	 */
	private function codeListSharedWithB(): array {
		return [
			SharedMasterDataService::REGISTERS => [
				[
					'id' => 7,
					'title' => 'Code lists',
					'organisation' => self::ORG_A,
					'shared_with' => json_encode([self::ORG_B]),
				],
				[
					'id' => 8,
					'title' => 'Personnel',
					'organisation' => self::ORG_A,
					'shared_with' => null,
				],
			],
			SharedMasterDataService::SCHEMAS => [],
		];
	}//end codeListSharedWithB()

	public function testAConsumerResolvesTheHoldersRegister(): void {
		$ids = $this->service(rowsByTable: $this->codeListSharedWithB())
			->sharedIds(table: SharedMasterDataService::REGISTERS, consumerOrgUuids: [self::ORG_B]);

		$this->assertSame([7], $ids);

	}//end testAConsumerResolvesTheHoldersRegister()

	public function testTheShareAdmitsTheDeclaredRegisterAndNothingElseTheHolderOwns(): void {
		$ids = $this->service(rowsByTable: $this->codeListSharedWithB())
			->sharedIds(table: SharedMasterDataService::REGISTERS, consumerOrgUuids: [self::ORG_B]);

		// Register 8 is held by the SAME organisation and is not declared. If a
		// widening ever keys on the holder rather than on the declared row, this
		// is the assertion that catches it: B would gain everything A owns.
		$this->assertNotContains(
			8,
			$ids,
			'A consumer of one register must not gain another register the same holder owns.'
		);

	}//end testTheShareAdmitsTheDeclaredRegisterAndNothingElseTheHolderOwns()

	public function testAnOrganisationNamedInNoDeclarationResolvesToNothing(): void {
		$ids = $this->service(rowsByTable: $this->codeListSharedWithB())
			->sharedIds(table: SharedMasterDataService::REGISTERS, consumerOrgUuids: [self::ORG_C]);

		$this->assertSame([], $ids);

	}//end testAnOrganisationNamedInNoDeclarationResolvesToNothing()

	public function testTheHolderResolvesToNothingBecauseItReadsItsOwnRowsAlready(): void {
		$ids = $this->service(rowsByTable: $this->codeListSharedWithB())
			->sharedIds(table: SharedMasterDataService::REGISTERS, consumerOrgUuids: [self::ORG_A]);

		$this->assertSame([], $ids, 'The holder reads its own rows through the ordinary organisation filter.');

	}//end testTheHolderResolvesToNothingBecauseItReadsItsOwnRowsAlready()

	public function testAnInstanceThatDeclaresNothingResolvesToNothing(): void {
		$service = $this->service(
			rowsByTable: [
				SharedMasterDataService::REGISTERS => [],
				SharedMasterDataService::SCHEMAS => [],
			]
		);

		$this->assertSame([], $service->sharedIds(table: SharedMasterDataService::REGISTERS, consumerOrgUuids: [self::ORG_B]));
		$this->assertSame([], $service->holdersForResource(registerId: 7, schemaId: 3, consumerOrgUuids: [self::ORG_B]));

	}//end testAnInstanceThatDeclaresNothingResolvesToNothing()

	public function testTheObjectPathWidensByTheHolderForTheDeclaredPairOnly(): void {
		$service = $this->service(rowsByTable: $this->codeListSharedWithB());

		$this->assertSame(
			[self::ORG_A],
			$service->holdersForResource(registerId: 7, schemaId: null, consumerOrgUuids: [self::ORG_B])
		);

		$this->assertSame(
			[],
			$service->holdersForResource(registerId: 8, schemaId: null, consumerOrgUuids: [self::ORG_B]),
			'The undeclared register held by the same organisation must widen by nothing.'
		);

	}//end testTheObjectPathWidensByTheHolderForTheDeclaredPairOnly()

	public function testAPairNobodyNamedWidensByNothing(): void {
		$service = $this->service(rowsByTable: $this->codeListSharedWithB());

		$this->assertSame(
			[],
			$service->holdersForResource(registerId: null, schemaId: null, consumerOrgUuids: [self::ORG_B])
		);

	}//end testAPairNobodyNamedWidensByNothing()

	public function testAConsumersWriteIsRefusedAndTheMessageNamesTheHolder(): void {
		$service = $this->service(rowsByTable: $this->codeListSharedWithB());

		try {
			$service->assertWritable(
				table: SharedMasterDataService::REGISTERS,
				id: 7,
				activeOrgUuids: [self::ORG_B]
			);
			$this->fail('A consumer write to shared master data must be refused.');
		} catch (SharedMasterDataWriteException $refusal) {
			$this->assertSame(self::ORG_A, $refusal->getHolderUuid());
			$this->assertSame('register', $refusal->getResourceType());
			$this->assertStringContainsString(
				self::ORG_A,
				$refusal->getMessage(),
				'The refusal must name the holder, so the caller knows who to ask.'
			);
			$this->assertStringContainsString('Code lists', $refusal->getMessage());
		}//end try

	}//end testAConsumersWriteIsRefusedAndTheMessageNamesTheHolder()

	public function testTheHoldersOwnWriteIsNotRefused(): void {
		$service = $this->service(rowsByTable: $this->codeListSharedWithB());

		$service->assertWritable(
			table: SharedMasterDataService::REGISTERS,
			id: 7,
			activeOrgUuids: [self::ORG_A]
		);

		$this->addToAssertionCount(1);

	}//end testTheHoldersOwnWriteIsNotRefused()

	public function testAnUnrelatedOrganisationsWriteIsNotRefusedByThisGuard(): void {
		// C consumes nothing here, so this guard has no opinion: the ordinary
		// cross-tenant check is what refuses C, and it does so with its own
		// message. A guard that threw for everybody would be indistinguishable
		// from the check it is meant to narrow.
		$service = $this->service(rowsByTable: $this->codeListSharedWithB());

		$service->assertWritable(
			table: SharedMasterDataService::REGISTERS,
			id: 7,
			activeOrgUuids: [self::ORG_C]
		);

		$this->addToAssertionCount(1);

	}//end testAnUnrelatedOrganisationsWriteIsNotRefusedByThisGuard()

	public function testASelfShareIsNotADeclaration(): void {
		// A row that lists its own holder as a consumer declares nothing. Left
		// unfiltered it would make the holder read as a consumer of its own row and
		// refuse the holder's own write — the feature locking out the only
		// organisation entitled to change the data.
		$service = $this->service(
			rowsByTable: [
				SharedMasterDataService::REGISTERS => [
					[
						'id' => 7,
						'title' => 'Code lists',
						'organisation' => self::ORG_A,
						'shared_with' => json_encode([self::ORG_A]),
					],
				],
				SharedMasterDataService::SCHEMAS => [],
			]
		);

		$service->assertWritable(
			table: SharedMasterDataService::REGISTERS,
			id: 7,
			activeOrgUuids: [self::ORG_A]
		);

		$this->assertSame(
			[],
			$service->sharedIds(table: SharedMasterDataService::REGISTERS, consumerOrgUuids: [self::ORG_A])
		);

	}//end testASelfShareIsNotADeclaration()

	public function testARowWithNoHolderIsNeverShared(): void {
		// An organisation-less legacy row with a `shared_with` value would,
		// taken literally, be shared by nobody with somebody. Reading it as a
		// share would hand that row across every tenant at once.
		$service = $this->service(
			rowsByTable: [
				SharedMasterDataService::REGISTERS => [
					[
						'id' => 9,
						'title' => 'Legacy',
						'organisation' => null,
						'shared_with' => json_encode([self::ORG_B]),
					],
				],
				SharedMasterDataService::SCHEMAS => [],
			]
		);

		$this->assertSame(
			[],
			$service->sharedIds(table: SharedMasterDataService::REGISTERS, consumerOrgUuids: [self::ORG_B])
		);

	}//end testARowWithNoHolderIsNeverShared()

	public function testAMalformedDeclarationDeclaresNothingRatherThanFailing(): void {
		$service = $this->service(
			rowsByTable: [
				SharedMasterDataService::REGISTERS => [
					[
						'id' => 10,
						'title' => 'Broken',
						'organisation' => self::ORG_A,
						'shared_with' => 'not json at all',
					],
					[
						'id' => 11,
						'title' => 'Empty',
						'organisation' => self::ORG_A,
						'shared_with' => '[]',
					],
				],
				SharedMasterDataService::SCHEMAS => [],
			]
		);

		$this->assertSame(
			[],
			$service->sharedIds(table: SharedMasterDataService::REGISTERS, consumerOrgUuids: [self::ORG_B])
		);

	}//end testAMalformedDeclarationDeclaresNothingRatherThanFailing()

	public function testASchemaLevelShareIsResolvedOnItsOwn(): void {
		$service = $this->service(
			rowsByTable: [
				SharedMasterDataService::REGISTERS => [],
				SharedMasterDataService::SCHEMAS => [
					[
						'id' => 3,
						'title' => 'Vergunningaanvraag',
						'organisation' => self::ORG_A,
						'shared_with' => json_encode([self::ORG_B, self::ORG_C]),
					],
				],
			]
		);

		$this->assertSame(
			[3],
			$service->sharedIds(table: SharedMasterDataService::SCHEMAS, consumerOrgUuids: [self::ORG_C])
		);
		$this->assertSame(
			[self::ORG_A],
			$service->holdersForResource(registerId: null, schemaId: 3, consumerOrgUuids: [self::ORG_C])
		);

	}//end testASchemaLevelShareIsResolvedOnItsOwn()

	public function testAnOrganisationWithNoActiveContextResolvesToNothing(): void {
		$service = $this->service(rowsByTable: $this->codeListSharedWithB());

		$this->assertSame([], $service->sharedIds(table: SharedMasterDataService::REGISTERS, consumerOrgUuids: []));
		$this->assertSame([], $service->holdersForResource(registerId: 7, schemaId: null, consumerOrgUuids: []));

	}//end testAnOrganisationWithNoActiveContextResolvesToNothing()

	public function testClearCacheMakesTheNextReadSeeARevokedShare(): void {
		// The declarations are read once per request. A holder that revokes a
		// share and re-reads in the same request must not still see it, which is
		// why the write path drops the cache.
		$rows = $this->codeListSharedWithB();
		$service = $this->service(rowsByTable: $rows);

		$this->assertSame(
			[7],
			$service->sharedIds(table: SharedMasterDataService::REGISTERS, consumerOrgUuids: [self::ORG_B])
		);

		// Revoke it behind the service, the way a write to the row would.
		$rows[SharedMasterDataService::REGISTERS][0]['shared_with'] = null;
		$service = $this->service(rowsByTable: $rows);
		$service->clearCache();

		$this->assertSame(
			[],
			$service->sharedIds(table: SharedMasterDataService::REGISTERS, consumerOrgUuids: [self::ORG_B])
		);

	}//end testClearCacheMakesTheNextReadSeeARevokedShare()
}//end class
