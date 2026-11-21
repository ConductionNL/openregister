<?php

/**
 * Unit tests for QualityAudit — its definition shape and when a schedule is due.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/runs-recorded-and-causes-named/specs/data-quality-scoring/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use DateTime;
use OCA\OpenRegister\Db\QualityAudit;
use OCA\OpenRegister\Db\QualityAuditRun;
use OCA\OpenRegister\Service\Quality\QualityAuditEvaluator;
use OCA\OpenRegister\Service\Quality\QualityScorer;
use PHPUnit\Framework\TestCase;

final class QualityAuditTest extends TestCase {

	private function audit(?string $schedule, ?string $lastRunAt): QualityAudit {
		$audit = new QualityAudit();
		$audit->setName('Addresses');
		$audit->setRegisterRef('bag');
		$audit->setSchemaRef('address');
		$audit->setRules([['type' => 'required', 'field' => 'postcode']]);
		$audit->setTolerance(0.95);
		$audit->setSchedule($schedule);
		if ($lastRunAt !== null) {
			$audit->setLastRunAt(new DateTime($lastRunAt));
		}

		return $audit;
	}

	public function testTheDefinitionIsWhatTheEvaluatorAccepts(): void {
		$audit = $this->audit('weekly', null);

		$this->assertSame(
			[
				'name' => 'Addresses',
				'population' => ['register' => 'bag', 'schema' => 'address', 'filters' => []],
				'rules' => [['type' => 'required', 'field' => 'postcode']],
				'tolerance' => 0.95,
				'schedule' => 'weekly',
			],
			$audit->definition()
		);
		$this->assertSame([], (new QualityAuditEvaluator(new QualityScorer()))->definitionFaults($audit->definition()));
	}

	public function testAScheduledAuditThatNeverRanIsDue(): void {
		$this->assertTrue($this->audit('daily', null)->isDue(new DateTime('2026-11-14T03:00:00Z')));
	}

	public function testAnOnDemandAuditIsNeverDue(): void {
		$this->assertFalse($this->audit(null, null)->isDue(new DateTime('2026-11-14T03:00:00Z')));
	}

	public function testEachScheduleIsDueOnceItsPeriodHasPassed(): void {
		$now = new DateTime('2026-11-14T03:00:00Z');

		$this->assertTrue($this->audit('daily', '2026-11-13T03:00:00Z')->isDue($now));
		$this->assertFalse($this->audit('daily', '2026-11-13T03:00:01Z')->isDue($now));
		$this->assertTrue($this->audit('weekly', '2026-11-07T03:00:00Z')->isDue($now));
		$this->assertFalse($this->audit('weekly', '2026-11-08T03:00:00Z')->isDue($now));
		$this->assertTrue($this->audit('monthly', '2026-10-14T03:00:00Z')->isDue($now));
		$this->assertFalse($this->audit('monthly', '2026-10-15T03:00:00Z')->isDue($now));
	}

	public function testIsDueLeavesTheMomentAsItWas(): void {
		$now = new DateTime('2026-11-14T03:00:00Z');
		$this->audit('monthly', '2026-01-01T00:00:00Z')->isDue($now);

		$this->assertSame('2026-11-14T03:00:00+00:00', $now->format(DATE_ATOM));
	}

	public function testARunSerialisesItsVerdictAtTheToleranceItWasJudgedAgainst(): void {
		$run = new QualityAuditRun();
		$run->setUuid('run-1');
		$run->setStartedAt(new DateTime('2026-03-01T09:00:00Z'));
		$run->setStartedBy('schedule');
		$run->setEvaluated(1000);
		$run->setScore(0.91);
		$run->setTolerance(0.9);
		$run->setVerdict('pass');
		$run->setFailed(40);

		$this->assertSame(
			[
				'id' => null,
				'uuid' => 'run-1',
				'auditId' => null,
				'startedAt' => '2026-03-01T09:00:00+00:00',
				'startedBy' => 'schedule',
				'evaluated' => 1000,
				'score' => 0.91,
				'tolerance' => 0.9,
				'verdict' => 'pass',
				'failed' => 40,
			],
			$run->jsonSerialize()
		);
	}
}
