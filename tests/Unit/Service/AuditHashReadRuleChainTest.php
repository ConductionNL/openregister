<?php

/**
 * The read-rule digest in the audit hash chain.
 *
 * A chain whose early rows were sealed before the rule columns existed and whose later
 * rows carry a rule digest verifies end to end; changing a sealed digest breaks it at
 * that row. Drives the real verifyChain() over rows served by a mocked connection.
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
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/read-audit-entries-name-the-rule/specs/audit-trail-immutable/spec.md#requirement-the-rule-digest-is-part-of-the-hash-chain
 */

declare(strict_types=1);

namespace Unit\Service;

use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrail\AuditTrailFieldTypes;
use OCA\OpenRegister\Service\AuditHashService;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\Lock\ILockingProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\Service\AuditHashService
 * @covers \OCA\OpenRegister\Db\AuditTrail\AuditTrailSealedForm
 */
class AuditHashReadRuleChainTest extends TestCase {

	private AuditHashService $service;

	private IDBConnection&MockObject $db;

	/** @var array<int, array<string, mixed>> */
	private array $rows = [];

	private int $queries = 0;

	protected function setUp(): void {
		$this->db = $this->createMock(IDBConnection::class);
		$this->service = new AuditHashService(
			$this->db,
			$this->createMock(ILockingProvider::class),
			$this->createMock(LoggerInterface::class),
			$this->createMock(IAppConfig::class)
		);
	}

	/**
	 * Serve the rows as one window, then an empty one.
	 */
	private function serve(): void {
		$this->db->method('getQueryBuilder')->willReturnCallback(
			function (): IQueryBuilder {
				$rows = ($this->queries === 0 ? $this->rows : []);
				$this->queries++;
				$cursor = $this->createMock(IResult::class);
				$cursor->method('fetchAll')->willReturn($rows);
				$expr = $this->createMock(IExpressionBuilder::class);
				$qb = $this->createMock(IQueryBuilder::class);
				foreach (['select', 'from', 'where', 'andWhere', 'orderBy', 'setMaxResults'] as $chain) {
					$qb->method($chain)->willReturnSelf();
				}

				$qb->method('expr')->willReturn($expr);
				$qb->method('createNamedParameter')->willReturn(':p');
				$qb->method('executeQuery')->willReturn($cursor);

				return $qb;
			}
		);
	}

	/**
	 * The entity a row hydrates to, the way the service maps it.
	 *
	 * @param array<string, mixed> $row The row.
	 */
	private function entity(array $row): AuditTrail {
		$mapped = [];
		foreach ($row as $key => $value) {
			$mapped[lcfirst(str_replace('_', '', ucwords($key, '_')))] = $value;
		}

		$entry = new AuditTrail();
		$entry->hydrate($mapped);

		return $entry;
	}

	/**
	 * The canonical JSON a row had before the rule columns existed: the v2 sealed fields only.
	 *
	 * @param array<string, mixed> $row The row.
	 */
	private function preChangeCanonical(array $row): string {
		$entity = $this->entity($row);
		$data = ['id' => $entity->getId()];
		foreach (AuditTrailFieldTypes::SEALED_FIELDS as $field) {
			$data[$field] = $entity->{'get' . ucfirst($field)}();
		}

		unset($data['hash'], $data['previousHash']);
		$data['changed'] = ($data['changed'] === [] ? null : $data['changed']);
		ksort($data);

		return (string) json_encode($data, (JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
	}

	/**
	 * Append a sealed row.
	 *
	 * @param int                  $id      The row id.
	 * @param array<string, mixed> $columns Extra columns.
	 * @param bool                 $legacy  Seal with the pre-change canonical form.
	 */
	private function seal(int $id, array $columns = [], bool $legacy = false): void {
		$previous = ($this->rows === [] ? $this->service->getGenesisHash() : $this->rows[count($this->rows) - 1]['hash']);
		$row = ['id' => $id, 'uuid' => 'uuid-' . $id, 'action' => 'read', 'object_uuid' => 'm-123', 'user' => 'alice'] + $columns;
		$hash = $this->service->computeHash($this->entity($row), $previous);
		if ($legacy === true) {
			$hash = hash('sha256', $previous . $this->preChangeCanonical($row));
		}

		$row['hash'] = $hash;
		$row['previous_hash'] = $previous;
		$this->rows[] = $row;
	}

	public function testAnEntryWithoutARuleCanonicalisesExactlyAsBeforeTheColumnsExisted(): void {
		$row = ['id' => 1, 'uuid' => 'u-1', 'action' => 'read', 'rule_digest' => null, 'rule_version' => null];

		$json = $this->service->getCanonicalJson($this->entity($row));

		$this->assertSame($this->preChangeCanonical($row), $json);
		$this->assertStringNotContainsString('ruleDigest', $json);
		$this->assertStringNotContainsString('ruleVersion', $json);
	}

	public function testAnEntryWithARuleSealsItsDigestAndVersion(): void {
		$json = $this->service->getCanonicalJson(
			$this->entity(['id' => 2, 'uuid' => 'u-2', 'action' => 'read', 'rule_digest' => str_repeat('d', 64), 'rule_version' => '1.4.0'])
		);
		$data = json_decode($json, true);

		$this->assertSame(str_repeat('d', 64), $data['ruleDigest']);
		$this->assertSame('1.4.0', $data['ruleVersion']);
		$keys = array_keys($data);
		$sorted = $keys;
		sort($sorted);
		$this->assertSame($sorted, $keys, 'The digest takes its place in the chain\'s key order');
	}

	public function testAChainAcrossOldAndNewEntriesVerifies(): void {
		// Sealed before the migration: the columns did not exist.
		$this->seal(1, [], legacy: true);
		$this->seal(2, [], legacy: true);
		// After the migration the columns read null on those rows and on unmediated reads.
		$this->rows[0] += ['rule_digest' => null, 'rule_version' => null];
		$this->rows[1] += ['rule_digest' => null, 'rule_version' => null];
		$this->seal(3, ['rule_digest' => null, 'rule_version' => null]);
		$this->seal(4, ['rule_digest' => str_repeat('a', 64), 'rule_version' => '1.4.0']);
		$this->seal(5, ['rule_digest' => str_repeat('b', 64), 'rule_version' => '1.5.0']);
		$this->serve();

		$result = $this->service->verifyChain();

		$this->assertTrue($result['valid']);
		$this->assertNull($result['brokenAt']);
		$this->assertSame(5, $result['entriesVerified']);
	}

	/**
	 * @dataProvider tampering
	 *
	 * @param string      $column The column changed in the database.
	 * @param string|null $value  The value written over it.
	 */
	public function testTamperingWithASealedRuleBreaksTheChainAtThatEntry(string $column, ?string $value): void {
		$this->seal(1, ['rule_digest' => null, 'rule_version' => null]);
		$this->seal(2, ['rule_digest' => str_repeat('a', 64), 'rule_version' => '1.4.0']);
		$this->seal(3, ['rule_digest' => str_repeat('b', 64), 'rule_version' => '1.4.0']);
		$this->rows[1][$column] = $value;
		$this->serve();

		$result = $this->service->verifyChain();

		$this->assertFalse($result['valid']);
		$this->assertSame(2, $result['brokenAt']);
	}

	/**
	 * @return array<string, array{0: string, 1: string|null}>
	 */
	public static function tampering(): array {
		return [
			'digest replaced' => ['rule_digest', str_repeat('c', 64)],
			'digest erased' => ['rule_digest', null],
			'version changed' => ['rule_version', '1.3.0'],
		];
	}

	public function testAddingADigestToAnEntrySealedWithoutOneBreaksTheChain(): void {
		$this->seal(1, ['rule_digest' => null, 'rule_version' => null]);
		$this->seal(2, ['rule_digest' => null, 'rule_version' => null]);
		$this->rows[1]['rule_digest'] = str_repeat('e', 64);
		$this->serve();

		$this->assertSame(2, $this->service->verifyChain()['brokenAt']);
	}
}
