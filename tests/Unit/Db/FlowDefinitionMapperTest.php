<?php

/**
 * FlowDefinitionMapperTest: content-addressed flow definitions, stored once per hash.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit mock helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Db;

use DateTime;
use OCA\OpenRegister\Db\FlowDefinition;
use OCA\OpenRegister\Db\FlowDefinitionMapper;
use OCA\OpenRegister\Tests\Unit\Db\Support\RecordsQueries;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Db\FlowDefinitionMapper
 * @uses   \OCA\OpenRegister\Db\FlowDefinition
 */
class FlowDefinitionMapperTest extends TestCase {
	use RecordsQueries;

	/**
	 * The mapper on a recording connection.
	 *
	 * @return FlowDefinitionMapper
	 */
	private function mapper(): FlowDefinitionMapper {
		return new FlowDefinitionMapper($this->recordingConnection());
	}//end mapper()

	/**
	 * A mapper whose builder number $failAt throws on execution.
	 *
	 * @param int $failAt The 1-based builder that fails.
	 *
	 * @return FlowDefinitionMapper
	 */
	private function mapperFailingAt(int $failAt): FlowDefinitionMapper {
		$failing = $this->createMock(IQueryBuilder::class);
		foreach (['insert', 'setValue', 'select', 'from', 'where', 'setMaxResults'] as $method) {
			$failing->method($method)->willReturnSelf();
		}

		$failing->method('createNamedParameter')->willReturn(':p');
		$failing->method('expr')->willReturnCallback(fn () => $this->recordingExpressions());
		$failing->method('executeStatement')->willThrowException(new RuntimeException('write failed'));
		$failing->method('executeQuery')->willThrowException(new RuntimeException('read failed'));
		$calls = 0;
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(
			function () use (&$calls, $failing, $failAt): IQueryBuilder {
				$calls++;
				if ($calls === $failAt) {
					$this->queries[] = ['failed'];
					return $failing;
				}

				return $this->recordingBuilder();
			}
		);

		return new FlowDefinitionMapper($db);
	}//end mapperFailingAt()

	public function testABlankHashFindsNothingWithoutAQuery(): void {
		$this->assertNull($this->mapper()->findByHash(' '));
		$this->assertSame([], $this->queries);
	}//end testABlankHashFindsNothingWithoutAQuery()

	public function testFindByHashReadsTheDefinition(): void {
		$this->rowSets = [[['id' => 3, 'hash' => 'h1', 'definition' => '{"nodes":[]}']]];

		$definition = $this->mapper()->findByHash('h1');

		$this->assertSame('{"nodes":[]}', $definition?->getDefinition());
		$this->assertSame(['select(*)', 'from(openregister_flow_defs)', "where(eq(hash, 'h1'))", 'setMaxResults(1)', 'executeQuery'], $this->queries[0]);
	}//end testFindByHashReadsTheDefinition()

	public function testAnUnknownHashIsNull(): void {
		$this->rowSets = [[]];

		$this->assertNull($this->mapper()->findByHash('h1'));
	}//end testAnUnknownHashIsNull()

	public function testAFailedReadIsNullRatherThanAnError(): void {
		$this->assertNull($this->mapperFailingAt(1)->findByHash('h1'));
	}//end testAFailedReadIsNullRatherThanAnError()

	public function testStoreReturnsTheExistingRowWithoutWriting(): void {
		$this->rowSets = [[['id' => 3, 'hash' => 'h1', 'definition' => 'old']]];

		$definition = $this->mapper()->store(self::definition('h1', 'new', 'f1'));

		$this->assertSame(3, $definition?->getId());
		$this->assertSame('old', $definition?->getDefinition());
		$this->assertCount(1, $this->queries);
	}//end testStoreReturnsTheExistingRowWithoutWriting()

	public function testStoreInsertsANewDefinition(): void {
		$this->rowSets = [[]];
		$this->lastInsertId = 8;

		$definition = $this->mapper()->store(self::definition('h2', '{"nodes":[1]}', 'f1'));

		$this->assertSame(8, $definition?->getId());
		$this->assertInstanceOf(DateTime::class, $definition?->getCreated());
		$this->assertContains("setValue(hash, 'h2')", $this->queries[1]);
		$this->assertContains("setValue(flow_uuid, 'f1')", $this->queries[1]);
		$this->assertContains("setValue(definition, '{\"nodes\":[1]}')", $this->queries[1]);
	}//end testStoreInsertsANewDefinition()

	public function testAFailedInsertRereadsAndReturnsTheRaceWinner(): void {
		$this->rowSets = [[], [['id' => 11, 'hash' => 'h2', 'definition' => 'winner']]];

		$definition = $this->mapperFailingAt(2)->store(self::definition('h2', 'mine'));

		$this->assertSame(11, $definition?->getId());
		$this->assertSame('winner', $definition?->getDefinition());
		$this->assertCount(3, $this->queries);
	}//end testAFailedInsertRereadsAndReturnsTheRaceWinner()

	public function testAFailedInsertWithNoWinnerLeavesTheRunUnpinned(): void {
		$this->rowSets = [[], []];

		$this->assertNull($this->mapperFailingAt(2)->store(self::definition('h2', 'mine')));
	}//end testAFailedInsertWithNoWinnerLeavesTheRunUnpinned()

	/**
	 * A definition to store.
	 *
	 * @param string      $hash     The canonical hash.
	 * @param string      $json     The definition JSON.
	 * @param string|null $flowUuid The originating flow, or null to leave it unset.
	 *
	 * @return FlowDefinition The definition.
	 */
	private static function definition(string $hash, string $json, ?string $flowUuid=null): FlowDefinition {
		$definition = new FlowDefinition();
		$definition->setHash($hash);
		$definition->setDefinition($json);
		if ($flowUuid !== null) {
			$definition->setFlowUuid($flowUuid);
		}

		return $definition;
	}//end definition()
}//end class
