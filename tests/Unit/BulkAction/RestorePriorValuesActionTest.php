<?php

/**
 * Unit tests for RestorePriorValuesAction — the inverse of a reversible job.
 *
 * Covers the four outcomes the design turns on. A member whose recorded
 * values still match what the original job wrote is restored (D-1). A member
 * somebody edited afterwards is reported by name and left alone, because
 * silently overwriting a later edit is the failure this change exists to
 * prevent, pointed the other way (D-3). A member the original job never
 * wrote has nothing to go back to. And the rehearsal reaches every one of
 * those answers without writing.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\BulkAction
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/undo-a-bulk-action/specs/bulk-action-jobs/spec.md
 */

declare(strict_types=1);

namespace Unit\BulkAction;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use InvalidArgumentException;
use OCA\OpenRegister\BulkAction\RestorePriorValuesAction;
use OCA\OpenRegister\BulkAction\ReversibleBulkActionInterface;
use OCA\OpenRegister\Db\BulkJobMember;
use OCA\OpenRegister\Db\BulkJobMemberMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class RestorePriorValuesActionTest extends TestCase {

	/**
	 * The original job's outcomes.
	 *
	 * @var BulkJobMemberMapper
	 */
	private BulkJobMemberMapper $memberMapper;

	/**
	 * The object write path.
	 *
	 * @var ObjectService
	 */
	private ObjectService $objectService;

	/**
	 * Every patch the action wrote, as [uuid, data].
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $writes = [];

	protected function setUp(): void {
		parent::setUp();

		$this->writes = [];
		$this->memberMapper = $this->createMock(BulkJobMemberMapper::class);
		$this->objectService = $this->createMock(ObjectService::class);

		$this->objectService->method('patchObject')->willReturnCallback(
			function (string $objectId, array $data) {
				$this->writes[] = ['uuid' => $objectId, 'data' => $data];

				return new ObjectEntity();
			}
		);
	}

	private function action(): RestorePriorValuesAction {
		return new RestorePriorValuesAction($this->memberMapper, $this->objectService, new NullLogger());
	}

	private function object(array $data = ['status' => 'afgehandeld']): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid('case-1');
		$object->setRegister('1');
		$object->setSchema('2');
		$object->setObject($data);

		return $object;
	}

	private function record(?array $prior, ?array $applied): BulkJobMember {
		$member = new BulkJobMember();
		$member->setJobId(7);
		$member->setObjectUuid('case-1');
		$member->setPriorValues($prior);
		$member->setAppliedValues($applied);

		return $member;
	}

	private function parameters(): array {
		return [RestorePriorValuesAction::PARAM_JOB => 7];
	}

	public function testTheRecordedPriorValueIsWrittenBack(): void {
		$this->memberMapper->method('findByJobAndObject')->willReturn(
			$this->record(['status' => 'in behandeling'], ['status' => 'afgehandeld'])
		);

		$result = $this->action()->apply($this->object(), $this->parameters(), true);

		$this->assertTrue($result->isApplied());
		$this->assertSame([['uuid' => 'case-1', 'data' => ['status' => 'in behandeling']]], $this->writes);
	}

	public function testALaterEditIsReportedByNameAndNeverOverwritten(): void {
		// The colleague moved the case on to 'heropend' after the job closed it.
		$this->memberMapper->method('findByJobAndObject')->willReturn(
			$this->record(['status' => 'in behandeling'], ['status' => 'afgehandeld'])
		);

		$result = $this->action()->apply($this->object(['status' => 'heropend']), $this->parameters(), true);

		$this->assertSame(BulkJobMember::OUTCOME_SKIPPED, $result->getOutcome());
		$this->assertStringContainsString('not reversible', (string)$result->getReason());
		$this->assertStringContainsString('status', (string)$result->getReason());
		$this->assertStringContainsString('changed after the original job', (string)$result->getReason());
		$this->assertSame([], $this->writes, 'a later change was overwritten');
	}

	public function testAMemberTheOriginalJobNeverWroteIsSkipped(): void {
		$this->memberMapper->method('findByJobAndObject')->willReturn(null);

		$result = $this->action()->apply($this->object(), $this->parameters(), true);

		$this->assertSame(BulkJobMember::OUTCOME_SKIPPED, $result->getOutcome());
		$this->assertStringContainsString('never wrote this object', (string)$result->getReason());
		$this->assertSame([], $this->writes);
	}

	public function testAMemberWithARowButNoRecordedPriorValueIsSkipped(): void {
		// A row written by a job whose action was not reversible: the outcome
		// is there, the prior value is not, and restoring nothing would look
		// exactly like restoring something.
		$this->memberMapper->method('findByJobAndObject')->willReturn($this->record(null, null));

		$result = $this->action()->apply($this->object(), $this->parameters(), true);

		$this->assertSame(BulkJobMember::OUTCOME_SKIPPED, $result->getOutcome());
		$this->assertSame([], $this->writes);
	}

	public function testAnObjectAlreadyBackWhereItStartedIsSkipped(): void {
		$this->memberMapper->method('findByJobAndObject')->willReturn(
			$this->record(['status' => 'in behandeling'], ['status' => 'in behandeling'])
		);

		$result = $this->action()->apply($this->object(['status' => 'in behandeling']), $this->parameters(), true);

		$this->assertSame(BulkJobMember::OUTCOME_SKIPPED, $result->getOutcome());
		$this->assertStringContainsString('already carries the values it had before', (string)$result->getReason());
		$this->assertSame([], $this->writes);
	}

	public function testTheRehearsalReachesTheSameAnswerWithoutWriting(): void {
		$this->memberMapper->method('findByJobAndObject')->willReturn(
			$this->record(['status' => 'in behandeling'], ['status' => 'afgehandeld'])
		);

		$result = $this->action()->apply($this->object(), $this->parameters(), false);

		$this->assertTrue($result->isApplied());
		$this->assertSame([], $this->writes, 'the rehearsal wrote');
	}

	public function testTheReversalIsItselfReversible(): void {
		$this->memberMapper->method('findByJobAndObject')->willReturn(
			$this->record(['status' => 'in behandeling'], ['status' => 'afgehandeld'])
		);

		$action = $this->action();
		$plan = $action->reversalPlanFor($this->object(), $this->parameters());

		$this->assertInstanceOf(ReversibleBulkActionInterface::class, $action);
		$this->assertGreaterThan(0, $action->getReversalWindow());
		$this->assertSame(['status' => 'afgehandeld'], $plan[ReversibleBulkActionInterface::PLAN_PRIOR]);
		$this->assertSame(['status' => 'in behandeling'], $plan[ReversibleBulkActionInterface::PLAN_APPLIED]);
	}

	public function testAReversalThatNamesNoJobIsRefusedBeforeItRuns(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage(RestorePriorValuesAction::PARAM_JOB);

		$this->action()->validateParameters([]);
	}
}//end class
