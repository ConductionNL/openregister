<?php

/**
 * The audit chain is keyed from its keyed-from row on, and the unkeyed rows before it still verify.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/audit-hash-chain/spec.md
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Repair\KeyAuditHashChain;
use OCA\OpenRegister\Service\AuditChainKey;
use OCA\OpenRegister\Service\AuditHashService;
use OCA\OpenRegister\Service\TenantKeyService;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IFunctionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\QueryBuilder\IQueryFunction;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\Lock\ILockingProvider;
use OCP\Migration\IOutput;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class AuditChainKeyedTest extends TestCase {

	/**
	 * App config values, by key.
	 *
	 * @var array<string, string>
	 */
	private array $config = [];

	/**
	 * The rows the chain walk reads.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $rows = [];

	/**
	 * How many tenant keys were asked for.
	 *
	 * @var int
	 */
	private int $keyReads = 0;

	/**
	 * The database the chain walk reads through.
	 *
	 * @var IDBConnection&MockObject
	 */
	private IDBConnection&MockObject $db;

	/**
	 * App config backed by $config.
	 *
	 * @var IAppConfig&MockObject
	 */
	private IAppConfig&MockObject $appConfig;

	protected function setUp(): void {
		parent::setUp();
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => ($this->config[$key] ?? $default)
		);
		$this->appConfig->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->config[$key] = $value;
				return true;
			}
		);
		$this->db = $this->createMock(IDBConnection::class);
		$this->wireDb();
	}//end setUp()

	private function chainKey(string $key = 'k1'): AuditChainKey {
		$keys = $this->createMock(TenantKeyService::class);
		$keys->method('getCurrentTenantKey')->willReturnCallback(
			function (string $tenantId) use ($key): string {
				$this->assertSame(AuditChainKey::TENANT, $tenantId);
				$this->keyReads++;
				return $key;
			}
		);

		return new AuditChainKey($keys, $this->appConfig);
	}//end chainKey()

	private function service(?AuditChainKey $key): AuditHashService {
		$locks = $this->createMock(ILockingProvider::class);

		return new AuditHashService($this->db, $locks, $this->createMock(LoggerInterface::class), $this->appConfig, $key);
	}//end service()

	private function wireDb(): void {
		$expr = $this->createMock(IExpressionBuilder::class);
		$func = $this->createMock(IFunctionBuilder::class);
		$func->method('max')->willReturn($this->createMock(IQueryFunction::class));

		$this->db->method('getQueryBuilder')->willReturnCallback(
			function () use ($expr, $func): IQueryBuilder {
				$state = ['after' => 0, 'max' => false];
				$qb = $this->createMock(IQueryBuilder::class);
				foreach (['from', 'orderBy', 'andWhere', 'where', 'setMaxResults'] as $fluent) {
					$qb->method($fluent)->willReturnSelf();
				}

				$qb->method('select')->willReturnCallback(
					function (...$columns) use ($qb, &$state): IQueryBuilder {
						$state['max'] = ($columns[0] instanceof IQueryFunction);
						return $qb;
					}
				);
				$qb->method('expr')->willReturn($expr);
				$qb->method('func')->willReturn($func);
				$qb->method('createNamedParameter')->willReturnCallback(
					function ($value) use (&$state): string {
						if (is_int($value) === true) {
							$state['after'] = $value;
						}

						return ':p';
					}
				);
				$qb->method('executeQuery')->willReturnCallback(
					function () use (&$state): IResult {
						$result = $this->createMock(IResult::class);
						$after = $state['after'];
						$result->method('fetchAll')->willReturn(array_values(array_filter($this->rows, static fn (array $r): bool => $r['id'] > $after)));
						$result->method('fetchOne')->willReturn(max([0, ...array_column($this->rows, 'id')]));
						return $result;
					}
				);

				return $qb;
			}
		);
	}//end wireDb()

	private function seal(AuditHashService $service, int $id, string $action): void {
		$row = ['id' => $id, 'uuid' => 'uuid-' . $id, 'action' => $action, 'object_uuid' => 'obj-' . $id, 'hash' => null, 'purged_at' => null];
		$previous = $service->getGenesisHash();
		if ($this->rows !== []) {
			$previous = $this->rows[array_key_last($this->rows)]['hash'];
		}

		$row['hash'] = $service->computeHash(entry: $this->entryOf(row: $row), previousHash: $previous);
		$row['previous_hash'] = $previous;
		$this->rows[] = $row;
	}//end seal()

	private function entryOf(array $row): AuditTrail {
		$entry = new AuditTrail();
		$entry->hydrate(['id' => $row['id'], 'uuid' => $row['uuid'], 'action' => $row['action'], 'objectUuid' => $row['object_uuid']]);

		return $entry;
	}//end entryOf()

	private function unkeyedHash(array $row, string $previous): string {
		return $this->service(null)->computeHash(entry: $this->entryOf(row: $row), previousHash: $previous);
	}//end unkeyedHash()

	public function testTheRepairStepKeysTheChainAfterTheExistingRows(): void {
		$legacy = $this->service(null);
		$this->seal($legacy, 1, 'create');
		$this->seal($legacy, 2, 'update');

		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('info')->with($this->stringContains('rows from 3 on are chained with the key'));
		(new KeyAuditHashChain($this->service($this->chainKey()), $this->appConfig))->run($output);

		$this->assertStringStartsWith('3:', $this->config[AuditChainKey::KEYED_FROM_KEY]);
		$verdict = json_decode($this->config[KeyAuditHashChain::VERDICT_KEY], true);
		$this->assertTrue($verdict['verdict']['valid']);
		$this->assertSame(2, $verdict['verdict']['entriesVerified']);
	}//end testTheRepairStepKeysTheChainAfterTheExistingRows()

	public function testTheRepairStepRunsOnce(): void {
		$this->config[AuditChainKey::KEYED_FROM_KEY] = '1:x';
		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('info')->with($this->stringContains('already keyed from row 1'));

		(new KeyAuditHashChain($this->service($this->chainKey()), $this->appConfig))->run($output);

		$this->assertSame('1:x', $this->config[AuditChainKey::KEYED_FROM_KEY]);
	}//end testTheRepairStepRunsOnce()

	public function testKeyedRowsAreHmacLinksAndTheUnkeyedSegmentIsReported(): void {
		$legacy = $this->service(null);
		$this->seal($legacy, 1, 'create');
		$this->seal($legacy, 2, 'update');
		$service = $this->service($this->chainKey());
		$service->startKeyedChain();
		$this->seal($service, 3, 'delete');
		$this->seal($service, 4, 'create');

		$this->assertNotSame($this->unkeyedHash($this->rows[2], $this->rows[1]['hash']), $this->rows[2]['hash'], 'A keyed link is not the plain SHA-256');
		$this->assertSame($this->rows[1]['hash'], $this->rows[2]['previous_hash'], 'The keyed chain links onto the last unkeyed row');

		$report = $this->service($this->chainKey())->verifyChain();

		$this->assertTrue($report['valid']);
		$this->assertSame(4, $report['entriesVerified']);
		$this->assertSame(2, $report['unkeyedEntries']);
		$this->assertSame(3, $report['keyedFrom']);
		$this->assertTrue($report['keyBoundaryIntact']);
	}//end testKeyedRowsAreHmacLinksAndTheUnkeyedSegmentIsReported()

	public function testAKeyedRowRewrittenWithAPlainHashBreaksTheChain(): void {
		$service = $this->service($this->chainKey());
		$service->startKeyedChain();
		$this->seal($service, 1, 'create');
		$this->seal($service, 2, 'update');

		$this->rows[1]['action'] = 'approve';
		$this->rows[1]['hash'] = $this->unkeyedHash($this->rows[1], $this->rows[0]['hash']);

		$report = $this->service($this->chainKey())->verifyChain();

		$this->assertFalse($report['valid']);
		$this->assertSame(2, $report['brokenAt']);
	}//end testAKeyedRowRewrittenWithAPlainHashBreaksTheChain()

	public function testAMovedBoundaryIsReportedAndKeyedRowsAreNeverCheckedUnkeyed(): void {
		$service = $this->service($this->chainKey());
		$service->startKeyedChain();
		$this->seal($service, 1, 'create');

		$this->config[AuditChainKey::KEYED_FROM_KEY] = '99:' . explode(':', $this->config[AuditChainKey::KEYED_FROM_KEY])[1];

		$report = $this->service($this->chainKey())->verifyChain();

		$this->assertFalse($report['valid']);
		$this->assertFalse($report['keyBoundaryIntact']);
	}//end testAMovedBoundaryIsReportedAndKeyedRowsAreNeverCheckedUnkeyed()

	public function testAChainWithoutAKeyedFromPointStaysUnkeyed(): void {
		$service = $this->service($this->chainKey());
		$this->seal($service, 1, 'create');

		$this->assertSame($this->unkeyedHash($this->rows[0], $service->getGenesisHash()), $this->rows[0]['hash']);
		$this->assertSame(0, $this->keyReads, 'No key is generated before the chain is keyed');
	}//end testAChainWithoutAKeyedFromPointStaysUnkeyed()
}//end class
