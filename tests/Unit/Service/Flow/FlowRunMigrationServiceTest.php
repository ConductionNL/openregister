<?php

/**
 * Moving a run in flight from one version of its flow to another.
 *
 * 🔴 WHAT THESE GUARD IS A RUN LANDING SOMEWHERE NOBODY CHOSE. A run IS its
 * marking, so the only question a migration has to answer is whether every
 * place holding a token has somewhere to go in the target. Getting that wrong
 * does not throw: the run reads the new version, parks on a place the engine
 * cannot resume from, and sits there with nothing saying why. So the test that
 * matters most is the REFUSAL, and that the refusal names the places.
 *
 * 🔑 THE DRY RUN IS TESTED AGAINST THE STORE, NOT AGAINST ITS OWN RETURN VALUE.
 * A preview that wrote and rolled back would still have taken the log and shown
 * up in an audit trail, so the assertion is that `update()` was never called at
 * all.
 *
 * 🔑 THE JOIN SUFFIX IS ITS OWN TEST. A declared join holds one place per
 * incoming edge, `<nodeId>#<edgeId>`. Dropping the suffix on the way across
 * would collapse a half-arrived join into a single place and fire it early,
 * which is a wrong ANSWER rather than an error, and no other assertion here
 * would notice.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Flow
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow;

use OCA\OpenRegister\Db\FlowRun;
use OCA\OpenRegister\Db\FlowRunMapper;
use OCA\OpenRegister\Db\FlowTimer;
use OCA\OpenRegister\Db\FlowTimerMapper;
use OCA\OpenRegister\Db\FlowVersion;
use OCA\OpenRegister\Service\Flow\FlowRunMigrationService;
use OCA\OpenRegister\Service\Flow\FlowVersionService;
use OCA\OpenRegister\Service\Flow\Timer\FlowTimerService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @covers \OCA\OpenRegister\Service\Flow\FlowRunMigrationService
 *
 * @spec openspec/changes/migrate-run-between-versions/specs/flow-definition-versioning/spec.md
 */
final class FlowRunMigrationServiceTest extends TestCase {

	private const FLOW = 'flow-1111';

	private const RUN = 'run-2222';

	private FlowRunMapper&MockObject $runs;

	private FlowVersionService&MockObject $versions;

	private FlowTimerMapper&MockObject $timers;

	/**
	 * The graph of each version, by version number.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $graphs = [];

	/**
	 * Version 2 has `review`, version 3 renamed it `assess`.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->runs = $this->createMock(FlowRunMapper::class);
		$this->versions = $this->createMock(FlowVersionService::class);
		$this->timers = $this->createMock(FlowTimerMapper::class);
		$this->timers->method('findOpenByRun')->willReturn([]);

		$this->graphs = [
			2 => ['nodes' => [
				['id' => 'intake', 'type' => 'action'],
				['id' => 'review', 'type' => 'user-task'],
				['id' => 'decide', 'type' => 'gateway'],
			]],
			3 => ['nodes' => [
				['id' => 'intake', 'type' => 'action'],
				['id' => 'assess', 'type' => 'user-task'],
				['id' => 'decide', 'type' => 'gateway'],
			]],
			4 => ['nodes' => [
				['id' => 'intake', 'type' => 'action'],
				// `review` became a GATEWAY, which a token sitting in a user
				// task cannot land on.
				['id' => 'review', 'type' => 'gateway'],
			]],
		];

		$this->versions->method('versionOf')->willReturnCallback(
			function (string $flowUuid, int $number): ?FlowVersion {
				if (array_key_exists($number, $this->graphs) === false) {
					return null;
				}

				// A REAL entity, not a mock. `FlowVersion` extends Nextcloud's
				// `Entity`, whose getters are `__call` magic, so PHPUnit
				// refuses to configure `getVersion()`: the method does not
				// physically exist. Building the row is also closer to what the
				// version service hands back.
				$version = new FlowVersion();
				$version->setVersion($number);
				$version->setDefinitionHash('hash-' . $number);

				return $version;
			}
		);
		$this->versions->method('graphOfVersion')->willReturnCallback(
			fn (FlowVersion $version): ?array => ($this->graphs[$version->getVersion()] ?? null)
		);
	}

	/**
	 * The service under test.
	 *
	 * @param FlowTimerService|null $timerService The timer seam.
	 *
	 * @return FlowRunMigrationService The service.
	 */
	private function service(?FlowTimerService $timerService = null): FlowRunMigrationService {
		return new FlowRunMigrationService(
			$this->runs,
			$this->versions,
			$this->timers,
			($timerService ?? $this->createMock(FlowTimerService::class)),
			new NullLogger()
		);
	}

	/**
	 * A run on version 2, parked wherever the caller says.
	 *
	 * @param array<string, int> $marking The marking.
	 * @param string             $status  The run status.
	 *
	 * @return FlowRun The run.
	 */
	private function aRun(array $marking = ['review' => 1], string $status = 'suspended'): FlowRun {
		$run = new FlowRun();
		$run->setUuid(self::RUN);
		$run->setFlowId(self::FLOW);
		$run->setFlowVersion(2);
		$run->setStatus($status);
		$run->setMarking($marking);
		$run->setLog([['type' => 'started']]);

		return $run;
	}

	/**
	 * Make the mapper answer this run.
	 *
	 * @param FlowRun $run The run.
	 *
	 * @return void
	 */
	private function resolves(FlowRun $run): void {
		$this->runs->method('findByUuid')->willReturn($run);
	}

	/**
	 * 🔴 A renamed node is mapped and the run continues on the target.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/migrate-run-between-versions/specs/flow-definition-versioning/spec.md#requirement-a-run-can-be-migrated-to-another-version-explicitly-and-validated
	 */
	public function testARenamedNodeIsMappedAndTheRunContinues(): void {
		$run = $this->aRun();
		$this->resolves($run);
		$this->runs->expects(self::once())->method('update');

		$outcome = $this->service()->migrate(
			runUuid: self::RUN,
			targetVersion: 3,
			reason: 'Version 3 renamed the review step',
			actor: 'anna',
			mapping: ['review' => 'assess'],
		);

		self::assertTrue($outcome['migrated']);
		self::assertSame(['assess' => 1], $outcome['marking']);
		self::assertSame(3, $run->getFlowVersion());
		self::assertSame(['assess' => 1], $run->getMarking());
	}

	/**
	 * 🔴 The log carries both versions, the mapping, the reason and the actor.
	 *
	 * "The version changed" with nothing beside it sends the next person
	 * digging through the version table to work out what it used to walk.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/migrate-run-between-versions/specs/flow-definition-versioning/spec.md#requirement-a-run-can-be-migrated-to-another-version-explicitly-and-validated
	 */
	public function testTheLogHoldsAMigratedEntryWithBothVersions(): void {
		$run = $this->aRun();
		$this->resolves($run);

		$this->service()->migrate(
			runUuid: self::RUN,
			targetVersion: 3,
			reason: 'Version 3 renamed the review step',
			actor: 'anna',
			mapping: ['review' => 'assess'],
		);

		$log = $run->getLog();
		$entry = end($log);

		self::assertSame(FlowRunMigrationService::LOG_ENTRY, $entry['type']);
		self::assertSame(2, $entry['fromVersion']);
		self::assertSame(3, $entry['toVersion']);
		self::assertSame(['review' => 'assess'], $entry['mapping']);
		self::assertSame('Version 3 renamed the review step', $entry['reason']);
		self::assertSame('anna', $entry['actor']);
		// The run's own history is kept, not replaced.
		self::assertSame('started', $log[0]['type']);
	}

	/**
	 * 🔴 A removed node with no mapping refuses, and NAMES the place.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/migrate-run-between-versions/specs/flow-definition-versioning/spec.md#requirement-a-run-can-be-migrated-to-another-version-explicitly-and-validated
	 */
	public function testARemovedNodeWithoutAMappingRefusesAndNamesIt(): void {
		$run = $this->aRun();
		$this->resolves($run);
		$this->runs->expects(self::never())->method('update');

		$outcome = $this->service()->migrate(
			runUuid: self::RUN,
			targetVersion: 3,
			reason: 'Trying it without a mapping',
			actor: 'anna',
		);

		self::assertFalse($outcome['migrated']);
		self::assertSame(['review'], $outcome['unmapped']);
		self::assertStringContainsString('review', $outcome['reason']);
		// And the run is untouched, which is the half an exception-only
		// assertion would miss.
		self::assertSame(2, $run->getFlowVersion());
		self::assertSame(['review' => 1], $run->getMarking());
	}

	/**
	 * 🔴 A mapping onto a node of another kind is refused.
	 *
	 * A token from a user task landing on a gateway is somewhere the engine
	 * cannot resume from, and the run would park forever with nothing saying
	 * why.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/migrate-run-between-versions/specs/flow-definition-versioning/spec.md#requirement-a-run-can-be-migrated-to-another-version-explicitly-and-validated
	 */
	public function testAMappingOntoAnotherKindIsRefused(): void {
		$run = $this->aRun();
		$this->resolves($run);

		$outcome = $this->service()->migrate(
			runUuid: self::RUN,
			targetVersion: 4,
			reason: 'The id is the same, the kind is not',
			actor: 'anna',
		);

		self::assertFalse($outcome['migrated'], 'same id, different kind, still refused');
		self::assertSame(['review'], $outcome['unmapped']);
	}

	/**
	 * 🔴 A dry run changes nothing at all.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/migrate-run-between-versions/specs/flow-definition-versioning/spec.md#requirement-a-run-can-be-migrated-to-another-version-explicitly-and-validated
	 */
	public function testADryRunChangesNothing(): void {
		$run = $this->aRun();
		$this->resolves($run);
		// The assertion that matters: not that it returned, that it never wrote.
		$this->runs->expects(self::never())->method('update');

		$outcome = $this->service()->migrate(
			runUuid: self::RUN,
			targetVersion: 3,
			reason: '',
			actor: 'anna',
			mapping: ['review' => 'assess'],
			dryRun: true,
		);

		self::assertTrue($outcome['dryRun']);
		self::assertFalse($outcome['migrated']);
		self::assertSame(['assess' => 1], $outcome['marking'], 'it still says where the run would land');
		self::assertSame(2, $run->getFlowVersion());
		self::assertCount(1, $run->getLog(), 'and nothing was appended to the log');
	}

	/**
	 * 🔴 A join's per-edge place keeps its suffix across the move.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/migrate-run-between-versions/specs/flow-definition-versioning/spec.md#requirement-a-run-can-be-migrated-to-another-version-explicitly-and-validated
	 */
	public function testAJoinPlaceKeepsItsEdgeSuffix(): void {
		$run = $this->aRun(marking: ['review#edge-a' => 1]);
		$this->resolves($run);

		$outcome = $this->service()->migrate(
			runUuid: self::RUN,
			targetVersion: 3,
			reason: 'A half-arrived join moves too',
			actor: 'anna',
			mapping: ['review' => 'assess'],
		);

		self::assertTrue($outcome['migrated']);
		self::assertSame(
			['assess#edge-a' => 1],
			$outcome['marking'],
			'dropping the suffix would collapse a half-arrived join and fire it early'
		);
	}

	/**
	 * A finished run is not migrated: that would rewrite what already happened.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/migrate-run-between-versions/specs/flow-definition-versioning/spec.md#requirement-a-run-can-be-migrated-to-another-version-explicitly-and-validated
	 */
	public function testAFinishedRunIsNotMigrated(): void {
		$run = $this->aRun(status: 'completed');
		$this->resolves($run);
		$this->runs->expects(self::never())->method('update');

		$outcome = $this->service()->migrate(
			runUuid: self::RUN,
			targetVersion: 3,
			reason: 'Trying to move a finished run',
			actor: 'anna',
			mapping: ['review' => 'assess'],
		);

		self::assertFalse($outcome['migrated']);
		self::assertStringContainsString('completed', $outcome['reason']);
	}

	/**
	 * A migration with no reason is refused; a dry run needs none.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/migrate-run-between-versions/specs/flow-definition-versioning/spec.md#requirement-a-run-can-be-migrated-to-another-version-explicitly-and-validated
	 */
	public function testAMigrationWithNoReasonIsRefused(): void {
		$run = $this->aRun();
		$this->resolves($run);

		$outcome = $this->service()->migrate(
			runUuid: self::RUN,
			targetVersion: 3,
			reason: '   ',
			actor: 'anna',
			mapping: ['review' => 'assess'],
		);

		self::assertFalse($outcome['migrated']);
		self::assertStringContainsString('why', $outcome['reason']);
	}

	/**
	 * A target version that does not exist refuses rather than throwing.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/migrate-run-between-versions/specs/flow-definition-versioning/spec.md#requirement-a-run-can-be-migrated-to-another-version-explicitly-and-validated
	 */
	public function testAnUnknownTargetVersionIsRefused(): void {
		$run = $this->aRun();
		$this->resolves($run);

		$outcome = $this->service()->migrate(
			runUuid: self::RUN,
			targetVersion: 99,
			reason: 'There is no version 99',
			actor: 'anna',
		);

		self::assertFalse($outcome['migrated']);
		self::assertStringContainsString('99', $outcome['reason']);
	}

	/**
	 * 🔴 Only a timer whose node MOVED is superseded.
	 *
	 * A timer on a node the target kept under the same id is measuring the same
	 * wait against the same deadline, and re-arming it would restart a clock
	 * the applicant is already counting.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/migrate-run-between-versions/specs/flow-definition-versioning/spec.md#requirement-a-run-can-be-migrated-to-another-version-explicitly-and-validated
	 */
	public function testOnlyTheTimerWhoseNodeMovedIsSuperseded(): void {
		$moved = $this->timer(uuid: 'timer-moved', nodeId: 'review');
		$stayed = $this->timer(uuid: 'timer-stayed', nodeId: 'intake');

		$timers = $this->createMock(FlowTimerMapper::class);
		$timers->method('findOpenByRun')->willReturn([$moved, $stayed]);
		$this->timers = $timers;

		$timerService = $this->createMock(FlowTimerService::class);
		$superseded = [];
		$timerService->method('supersede')->willReturnCallback(
			function (string $uuid, $anchorEventAt, string $reason, ?string $actor) use (&$superseded) {
				$superseded[] = [$uuid, $reason];
				return new FlowTimer();
			}
		);

		$run = $this->aRun();
		$this->resolves($run);

		$this->service(timerService: $timerService)->migrate(
			runUuid: self::RUN,
			targetVersion: 3,
			reason: 'Version 3 renamed the review step',
			actor: 'anna',
			mapping: ['review' => 'assess'],
		);

		self::assertSame(
			[['timer-moved', FlowRunMigrationService::LOG_ENTRY]],
			$superseded,
			'the timer on the node that did not move must be left alone'
		);
	}

	/**
	 * 🔴 A subject with no run in flight answers `migrated: true`.
	 *
	 * dossiq's `case-type-rebind` stops the whole rebind when the engine
	 * refuses, and a case with no live run has nothing that could disagree with
	 * the rebind. Answering false here would block a correction on a case where
	 * there was never a problem, which is worse than the gap it replaces.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/migrate-run-between-versions/specs/flow-definition-versioning/spec.md#requirement-a-run-can-be-migrated-to-another-version-explicitly-and-validated
	 */
	public function testASubjectWithNoRunInFlightIsNotARefusal(): void {
		$this->runs->method('findActive')->willReturn([]);

		$outcome = $this->service()->migrateRunForSubject(
			subjectUuid: 'case-1',
			targetDefinitionRef: '3',
			actorUid: 'anna',
		);

		self::assertTrue($outcome['migrated'], 'nothing to migrate is not a refusal');
		self::assertSame([], $outcome['runs']);
		self::assertStringContainsString('nothing to migrate', $outcome['reason']);
	}

	/**
	 * 🔴 A live run and a target that names another FLOW is refused, and says why.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/migrate-run-between-versions/specs/flow-definition-versioning/spec.md#requirement-a-run-can-be-migrated-to-another-version-explicitly-and-validated
	 */
	public function testARunCannotBeMovedToAnotherFlow(): void {
		$this->runs->method('findActive')->willReturn([$this->aRun()]);

		$outcome = $this->service()->migrateRunForSubject(
			subjectUuid: 'case-1',
			targetDefinitionRef: 'some-other-flow-uuid',
			actorUid: 'anna',
		);

		self::assertFalse($outcome['migrated']);
		self::assertStringContainsString('never between flows', $outcome['reason']);
	}

	/**
	 * A live run and a version number moves, and reports per run.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/migrate-run-between-versions/specs/flow-definition-versioning/spec.md#requirement-a-run-can-be-migrated-to-another-version-explicitly-and-validated
	 */
	public function testALiveRunMovesToTheNamedVersion(): void {
		$run = $this->aRun(marking: ['intake' => 1]);
		$this->runs->method('findActive')->willReturn([$run]);
		$this->resolves($run);

		$outcome = $this->service()->migrateRunForSubject(
			subjectUuid: 'case-1',
			targetDefinitionRef: '3',
			actorUid: 'anna',
		);

		self::assertTrue($outcome['migrated']);
		self::assertCount(1, $outcome['runs']);
		self::assertSame(3, $run->getFlowVersion());
	}

	/**
	 * An unreadable run store is not "no runs".
	 *
	 * Saying so lets the caller stop rather than proceed on an answer nobody
	 * checked, which is the whole difference between a gap and a wrong answer.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/migrate-run-between-versions/specs/flow-definition-versioning/spec.md#requirement-a-run-can-be-migrated-to-another-version-explicitly-and-validated
	 */
	public function testAnUnreadableRunStoreIsNotAnEmptyList(): void {
		$this->runs->method('findActive')->willThrowException(new \RuntimeException('db down'));

		$outcome = $this->service()->migrateRunForSubject(
			subjectUuid: 'case-1',
			targetDefinitionRef: '3',
			actorUid: 'anna',
		);

		self::assertFalse($outcome['migrated']);
		self::assertStringContainsString('could not be read', $outcome['reason']);
	}

	/**
	 * An open timer on a node.
	 *
	 * @param string $uuid   The timer uuid.
	 * @param string $nodeId The node it is bound to.
	 *
	 * @return FlowTimer The timer.
	 */
	private function timer(string $uuid, string $nodeId): object {
		// Real, for the reason the version above is: an `Entity`'s getters are
		// magic and cannot be stubbed.
		$timer = new FlowTimer();
		$timer->setUuid($uuid);
		$timer->setNodeId($nodeId);

		return $timer;
	}
}//end class
