<?php

/**
 * Unit tests for the v1 → v2 audit chain migration.
 *
 * 🔴 THIS IS THE RISKIEST CODE IN THE CHANGE, because it is the only part that
 * cannot be undone. A re-chain recomputes every hash from current row content,
 * so afterwards an intact chain and a tampered one look identical — and the v1
 * hashes that could have told them apart are gone.
 *
 * Everything below therefore tests the ORDER and the REFUSALS, not the happy
 * path:
 *
 *   - the verdict is recorded BEFORE anything is re-sealed;
 *   - a chain that cannot be verdicted is not re-sealed at all;
 *   - a second run does not re-verify (a v2 chain checked against the v1 form
 *     would report a false break and overwrite the real verdict with it).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/flow-object-attribution/specs/audit-hash-chain/spec.md
 */

namespace Unit\Repair;

use OCA\OpenRegister\Repair\RechainAuditTrailForFlowAttribution;
use OCA\OpenRegister\Service\AuditHashService;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

class RechainAuditTrailForFlowAttributionTest extends TestCase {

	/**
	 * An app-config double backed by a plain array.
	 *
	 * @param boolean $writable Whether writes are kept. False models a config
	 *                          backend that silently drops them.
	 *
	 * @return IAppConfig The config double.
	 */
	private function config(bool $writable = true, array &$store = []): IAppConfig {
		$config = $this->createMock(IAppConfig::class);

		// A regular closure with `use (&$store)`, NOT an arrow function: `fn`
		// captures by VALUE at definition time, so the read-back would see the
		// store as it was before any write. That mistake made this double report
		// a dropped write on every call — which is exactly what the code under
		// test refuses on, so the first run of these tests failed for a reason
		// that had nothing to do with the code.
		$config->method('getValueString')->willReturnCallback(
			function (string $app, string $key, string $default = '') use (&$store): string {
				return ($store[$key] ?? $default);
			}
		);

		$config->method('setValueString')->willReturnCallback(
			static function (string $app, string $key, string $value) use (&$store, $writable): bool {
				if ($writable === true) {
					$store[$key] = $value;
				}

				return true;
			}
		);

		return $config;
	}//end config()

	/**
	 * A database double whose audit-trail query returns no rows.
	 *
	 * An empty table is the right fixture for these tests: the ORDERING and the
	 * REFUSALS are what is under test, and they do not depend on there being
	 * rows. Chain-walking itself is covered by the AuditHashService suites.
	 *
	 * @return IDBConnection The database double.
	 */
	private function emptyDb(): IDBConnection {
		$result = $this->createMock(\OCP\DB\IResult::class);
		$result->method('fetchAll')->willReturn([]);

		$expr = $this->createMock(\OCP\DB\QueryBuilder\IExpressionBuilder::class);

		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('select')->willReturnSelf();
		$qb->method('from')->willReturnSelf();
		$qb->method('where')->willReturnSelf();
		$qb->method('andWhere')->willReturnSelf();
		$qb->method('orderBy')->willReturnSelf();
		$qb->method('setMaxResults')->willReturnSelf();
		$qb->method('expr')->willReturn($expr);
		$qb->method('createNamedParameter')->willReturn(':p');
		$qb->method('executeQuery')->willReturn($result);

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);

		return $db;
	}//end emptyDb()

	public function testItRecordsAVerdictAndThenReSeals(): void {
		$store = [];
		$hashes = $this->createMock(AuditHashService::class);
		$hashes->expects($this->once())->method('rechainAll')
			->willReturn(['rechained' => 3, 'tombstonesPreserved' => 1]);

		$step = new RechainAuditTrailForFlowAttribution(
			$this->emptyDb(),
			$this->config(store: $store),
			$hashes,
			new NullLogger()
		);

		$step->run($this->createMock(IOutput::class));

		$this->assertArrayHasKey(
			RechainAuditTrailForFlowAttribution::VERDICT_KEY,
			$store,
			'The state of the chain before the re-seal is the one fact the re-seal destroys.'
		);
		$this->assertArrayHasKey(RechainAuditTrailForFlowAttribution::DONE_KEY, $store);

		$verdict = json_decode($store[RechainAuditTrailForFlowAttribution::VERDICT_KEY], true);
		$this->assertSame('openregister-genesis-v1', $verdict['seedFrom']);
		$this->assertSame('openregister-genesis-v2', $verdict['seedTo']);
	}//end testItRecordsAVerdictAndThenReSeals()

	/**
	 * 🔴 NO VERDICT, NO RE-CHAIN.
	 *
	 * A re-chain that leaves no account of the chain it replaced has no remedy,
	 * so this is the one refusal that must hold even at the cost of blocking an
	 * upgrade.
	 */
	public function testItRefusesToReSealWhenTheVerdictCannotBeStored(): void {
		$store = [];
		$hashes = $this->createMock(AuditHashService::class);
		$hashes->expects($this->never())->method('rechainAll');

		$step = new RechainAuditTrailForFlowAttribution(
			$this->emptyDb(),
			$this->config(writable: false, store: $store),
			$hashes,
			new NullLogger()
		);

		$output = $this->createMock(IOutput::class);
		$output->expects($this->atLeastOnce())->method('warning');

		$step->run($output);

		$this->assertArrayNotHasKey(RechainAuditTrailForFlowAttribution::DONE_KEY, $store);
	}//end testItRefusesToReSealWhenTheVerdictCannotBeStored()

	/**
	 * 🔴 It does not run twice.
	 *
	 * After the first pass every row is sealed under v2, so a second pre-verify
	 * would compare v2 rows against the v1 form, report a false break, and
	 * overwrite the real verdict with a meaningless one.
	 */
	public function testASecondRunIsANoOp(): void {
		$store = [RechainAuditTrailForFlowAttribution::DONE_KEY => '2026-08-28T00:00:00+00:00'];

		$hashes = $this->createMock(AuditHashService::class);
		$hashes->expects($this->never())->method('rechainAll');

		$step = new RechainAuditTrailForFlowAttribution(
			$this->emptyDb(),
			$this->config(store: $store),
			$hashes,
			new NullLogger()
		);

		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('info');

		$step->run($output);
	}//end testASecondRunIsANoOp()

	/**
	 * A failure inside the re-seal must not leave the step marked done.
	 *
	 * Otherwise the next `occ maintenance:repair` skips it, and the table is
	 * left half-sealed with nothing left to finish it.
	 */
	public function testAFailedReSealDoesNotMarkTheStepDone(): void {
		$store = [];
		$hashes = $this->createMock(AuditHashService::class);
		$hashes->method('rechainAll')->willThrowException(new RuntimeException('lock unavailable'));

		$step = new RechainAuditTrailForFlowAttribution(
			$this->emptyDb(),
			$this->config(store: $store),
			$hashes,
			new NullLogger()
		);

		try {
			$step->run($this->createMock(IOutput::class));
		} catch (RuntimeException $e) {
			// The step does not swallow it; maintenance:repair reports it.
		}

		$this->assertArrayNotHasKey(
			RechainAuditTrailForFlowAttribution::DONE_KEY,
			$store,
			'A half-finished re-seal must not look complete.'
		);
		$this->assertArrayHasKey(
			RechainAuditTrailForFlowAttribution::VERDICT_KEY,
			$store,
			'The verdict is written first and survives the failure.'
		);
	}//end testAFailedReSealDoesNotMarkTheStepDone()

	public function testItNamesItselfForTheRepairRunner(): void {
		$step = new RechainAuditTrailForFlowAttribution(
			$this->emptyDb(),
			$this->config(),
			$this->createMock(AuditHashService::class),
			new NullLogger()
		);

		$this->assertStringContainsString('audit chain', strtolower($step->getName()));
	}//end testItNamesItselfForTheRepairRunner()
}//end class
