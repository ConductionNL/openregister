<?php

/**
 * The recorder: what a save writes, and what it refuses to break.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Rules
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

namespace OCA\OpenRegister\Tests\Unit\Service\Rules;

use DateTime;
use OCA\OpenRegister\Db\RuleRun;
use OCA\OpenRegister\Db\RuleRunMapper;
use OCA\OpenRegister\Db\RuleRunSummary;
use OCA\OpenRegister\Db\RuleRunSummaryMapper;
use OCA\OpenRegister\Service\Rules\RuleRunRecorder;
use OCA\OpenRegister\Service\Rules\RuleTrace;
use OCA\OpenRegister\Service\Rules\RuleVocabulary;
use OCP\IAppConfig;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Exercises the detail row, the switch that turns it off and the failure that
 * must never reach the save.
 *
 * @package OCA\OpenRegister\Tests\Unit\Service\Rules
 *
 * @spec openspec/changes/rules-engine-operability/specs/flow-engine/spec.md
 */
class RuleRunRecorderTest extends TestCase {

	/**
	 * Build a recorder over doubles, capturing what it wrote.
	 *
	 * @param string $logEnabled The configured value of the detail-log switch.
	 * @param array<int, RuleRun> $rows Captures the detail rows written.
	 * @param array<int, array<string, mixed>> $summaries Captures the summary calls.
	 * @param bool $summaryThrows Whether the summary store refuses the write.
	 *
	 * @return RuleRunRecorder The recorder.
	 */
	private function recorder(
		string $logEnabled,
		array &$rows,
		array &$summaries,
		bool $summaryThrows = false,
	): RuleRunRecorder {
		$runs = $this->createMock(RuleRunMapper::class);
		$runs->method('insert')->willReturnCallback(
			static function (RuleRun $run) use (&$rows): RuleRun {
				$rows[] = $run;
				return $run;
			}
		);

		$summaryMapper = $this->createMock(RuleRunSummaryMapper::class);
		$summaryMapper->method('record')->willReturnCallback(
			static function (
				string $ruleId,
				string $schemaSlug,
				string $verdict,
				DateTime $at,
				?string $error = null,
			) use (&$summaries, $summaryThrows): RuleRunSummary {
				if ($summaryThrows === true) {
					throw new RuntimeException('the summary table is gone');
				}

				$summaries[] = ['ruleId' => $ruleId, 'verdict' => $verdict, 'error' => $error];
				return new RuleRunSummary();
			}
		);

		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = '') use ($logEnabled): string {
				if ($key === RuleRunRecorder::CONFIG_ENABLED) {
					return $logEnabled;
				}

				return $default;
			}
		);

		return new RuleRunRecorder(
			$runs,
			$summaryMapper,
			$this->createMock(IUserSession::class),
			$config,
			$this->createMock(LoggerInterface::class)
		);

	}//end recorder()

	/**
	 * A refusal writes the verdict, the operand and the value it read.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/rules-engine-operability/specs/flow-engine/spec.md
	 */
	public function testARefusalIsRecordedWithItsDecidingOperand(): void {
		$rows = [];
		$summaries = [];

		$this->recorder(logEnabled: '', rows: $rows, summaries: $summaries)->record(
			ruleId: 'lifecycleCondition:bezwaar:sluiten',
			schemaSlug: 'bezwaar',
			trace: new RuleTrace(
				verdict: RuleVocabulary::VERDICT_NO_MATCH,
				operand: 'bedrag',
				operandValue: '120'
			),
			objectUuid: 'uuid-1',
			registerSlug: 'zaken'
		);

		$this->assertCount(1, $rows);
		$this->assertSame(RuleVocabulary::VERDICT_NO_MATCH, $rows[0]->getVerdict());
		$this->assertSame('bedrag', $rows[0]->getOperand());
		$this->assertSame('120', $rows[0]->getOperandValue());
		$this->assertSame('uuid-1', $rows[0]->getObjectUuid());
		$this->assertSame('zaken', $rows[0]->getRegisterSlug());
		$this->assertCount(1, $summaries);

	}//end testARefusalIsRecordedWithItsDecidingOperand()

	/**
	 * With the detail log off, no row is written and the summary still is: the
	 * inventory keeps working on an instance that does not want the weight.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/rules-engine-operability/specs/flow-engine/spec.md
	 */
	public function testSwitchingTheDetailLogOffKeepsTheSummary(): void {
		$rows = [];
		$summaries = [];

		$this->recorder(logEnabled: 'false', rows: $rows, summaries: $summaries)->record(
			ruleId: 'calculation:bezwaar:uiterlijkeDatum',
			schemaSlug: 'bezwaar',
			trace: RuleTrace::fired()
		);

		$this->assertSame([], $rows);
		$this->assertCount(1, $summaries);

	}//end testSwitchingTheDetailLogOffKeepsTheSummary()

	/**
	 * An error verdict hands its message to the summary, which is what keeps a
	 * last error readable after a thousand good runs.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/rules-engine-operability/specs/flow-engine/spec.md
	 */
	public function testAnErrorCarriesItsMessageIntoTheSummary(): void {
		$rows = [];
		$summaries = [];

		$this->recorder(logEnabled: '', rows: $rows, summaries: $summaries)->record(
			ruleId: 'calculation:bezwaar:uiterlijkeDatum',
			schemaSlug: 'bezwaar',
			trace: RuleTrace::errored(message: 'Unknown property "ontvangstdatum".')
		);

		$this->assertSame('Unknown property "ontvangstdatum".', $summaries[0]['error']);
		$this->assertSame(RuleVocabulary::VERDICT_ERROR, $rows[0]->getVerdict());

	}//end testAnErrorCarriesItsMessageIntoTheSummary()

	/**
	 * A log store that refuses the write costs the explanation and nothing
	 * else. The recorder sits inside the save pipeline, so a throw here would
	 * be a save that failed because its own audit of itself failed.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/rules-engine-operability/specs/flow-engine/spec.md
	 */
	public function testAFailingLogStoreNeverReachesTheSave(): void {
		$rows = [];
		$summaries = [];

		$this->recorder(
			logEnabled: '',
			rows: $rows,
			summaries: $summaries,
			summaryThrows: true
		)->record(
			ruleId: 'calculation:bezwaar:uiterlijkeDatum',
			schemaSlug: 'bezwaar',
			trace: RuleTrace::fired()
		);

		$this->assertSame([], $summaries);
		$this->assertCount(1, $rows);

	}//end testAFailingLogStoreNeverReachesTheSave()

	/**
	 * The retention period falls back to the default for anything that is not
	 * a count of days, rather than to zero, which would prune everything.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/rules-engine-operability/specs/flow-engine/spec.md
	 */
	public function testAnUnreadableRetentionPeriodFallsBackToTheDefault(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = ''): string {
				if ($key === RuleRunRecorder::CONFIG_RETENTION_DAYS) {
					return 'zeven';
				}

				return $default;
			}
		);

		$recorder = new RuleRunRecorder(
			$this->createMock(RuleRunMapper::class),
			$this->createMock(RuleRunSummaryMapper::class),
			$this->createMock(IUserSession::class),
			$config,
			$this->createMock(LoggerInterface::class)
		);

		$this->assertSame(RuleRunRecorder::DEFAULT_RETENTION_DAYS, $recorder->retentionDays());

	}//end testAnUnreadableRetentionPeriodFallsBackToTheDefault()
}//end class
