<?php

/**
 * The hash chain commits to a row's flow attribution, under the v2 seed.
 *
 * Builds chains with the service's own computeHash() and walks them with
 * verifyChain() over a mocked connection that serves the rows, so the
 * assertions are on the verdict verifyChain() reaches.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-object-attribution
 */

declare(strict_types=1);

namespace Unit\Service;

use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Service\AuditHashService;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\Lock\ILockingProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Flow attribution is inside the canonical form, so altering it breaks the chain.
 */
class AuditHashFlowAttributionTest extends TestCase {

	private AuditHashService $service;

	private IDBConnection&MockObject $db;

	/**
	 * The rows served to verifyChain(), in one window.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $rows = [];

	/**
	 * How many windows have been served.
	 */
	private int $queries = 0;

	protected function setUp(): void {
		parent::setUp();

		$this->db = $this->createMock(IDBConnection::class);
		$this->service = new AuditHashService(
			$this->db,
			$this->createMock(ILockingProvider::class),
			$this->createMock(LoggerInterface::class),
			$this->createMock(IAppConfig::class)
		);

		$this->db->method('getQueryBuilder')
			->willReturnCallback(
				function (): IQueryBuilder {
					// One populated window, then an empty one that ends the walk.
					$rows = ($this->queries === 0) ? $this->rows : [];
					$this->queries++;

					$cursor = $this->createMock(IResult::class);
					$cursor->method('fetchAll')->willReturn($rows);

					$expr = $this->createMock(IExpressionBuilder::class);
					$expr->method('gt')->willReturn('gt');
					$expr->method('lte')->willReturn('lte');
					$expr->method('gte')->willReturn('gte');
					$expr->method('isNotNull')->willReturn('isnotnull');

					$qb = $this->createMock(IQueryBuilder::class);
					$qb->method('select')->willReturnSelf();
					$qb->method('from')->willReturnSelf();
					$qb->method('where')->willReturnSelf();
					$qb->method('andWhere')->willReturnSelf();
					$qb->method('orderBy')->willReturnSelf();
					$qb->method('setMaxResults')->willReturnSelf();
					$qb->method('expr')->willReturn($expr);
					$qb->method('createNamedParameter')->willReturn(':p');
					$qb->method('executeQuery')->willReturn($cursor);

					return $qb;
				}
			);
	}

	/**
	 * Hydrate a snake_case row the way the service does.
	 *
	 * @param array<string, mixed> $row The raw row.
	 *
	 * @return AuditTrail
	 */
	private function entityFromRow(array $row): AuditTrail {
		$mapped = [];
		foreach ($row as $key => $value) {
			$mapped[lcfirst(str_replace('_', '', ucwords($key, '_')))] = $value;
		}

		$entry = new AuditTrail();
		$entry->hydrate($mapped);

		return $entry;
	}

	/**
	 * Three rows sealed from the genesis hash: attributed, unattributed, attributed.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function sealedChain(): array {
		$plain = [
			['flow_run' => 'run-1', 'flow_node' => 'enrich', 'flow_step' => 1],
			['flow_run' => null, 'flow_node' => null, 'flow_step' => null],
			['flow_run' => 'run-1', 'flow_node' => 'publish', 'flow_step' => 2],
		];

		$rows = [];
		$previous = $this->service->getGenesisHash();
		foreach ($plain as $index => $attribution) {
			$row = [
				'id' => ($index + 1),
				'uuid' => 'uuid-' . ($index + 1),
				'action' => 'update',
				'hash' => null,
				'previous_hash' => null,
			] + $attribution;

			$hash = $this->service->computeHash($this->entityFromRow($row), $previous);
			$row['hash'] = $hash;
			$row['previous_hash'] = $previous;
			$previous = $hash;
			$rows[] = $row;
		}

		return $rows;
	}

	public function testTheGenesisIsTheV2Seed(): void {
		$this->assertSame(hash('sha256', 'openregister-genesis-v2'), $this->service->getGenesisHash());
	}

	public function testTheCanonicalFormCarriesTheAttributionKeys(): void {
		$entry = $this->entityFromRow(['uuid' => 'u', 'flow_run' => 'run-1', 'flow_node' => 'enrich', 'flow_step' => 4]);

		$canonical = json_decode($this->service->getCanonicalJson($entry), true);

		$this->assertSame('run-1', $canonical['flowRun']);
		$this->assertSame('enrich', $canonical['flowNode']);
		$this->assertSame(4, $canonical['flowStep']);
	}

	public function testAFreshV2ChainVerifiesEndToEnd(): void {
		$this->rows = $this->sealedChain();

		$result = $this->service->verifyChain();

		$this->assertTrue($result['valid']);
		$this->assertNull($result['brokenAt']);
		$this->assertSame(3, $result['entriesVerified']);
		$this->assertSame(hash('sha256', 'openregister-genesis-v2'), $this->rows[0]['previous_hash']);
	}

	/**
	 * Each way a row's attribution can be altered after it was sealed.
	 *
	 * @return array<string, array{int, string, mixed}>
	 */
	public static function tamperings(): array {
		return [
			'run re-pointed' => [0, 'flow_run', 'run-2'],
			'run stripped' => [2, 'flow_run', null],
			'node renamed' => [2, 'flow_node', 'enrich'],
			'step changed' => [0, 'flow_step', 7],
			'attribution added to an unattributed row' => [1, 'flow_run', 'run-1'],
		];
	}

	#[DataProvider('tamperings')]
	public function testAlteringAttributionBreaksTheChainAtThatRow(int $index, string $column, mixed $value): void {
		$this->rows = $this->sealedChain();
		$this->rows[$index][$column] = $value;

		$result = $this->service->verifyChain();

		$this->assertFalse($result['valid']);
		$this->assertSame(($index + 1), $result['brokenAt']);
		$this->assertSame($index, $result['entriesVerified']);
	}
}
