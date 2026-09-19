<?php

declare(strict_types=1);

/**
 * The sweep: the only caller that acts with nobody watching.
 *
 * 🔴 EVERY REFUSAL IN AnonymisationRun EXISTS FOR THIS CLASS. A person running
 * one record can read an error and decide; a sweep cannot, so a sweep that
 * resolves an ambiguity by guessing resolves it the same wrong way across every
 * record on the instance before anybody notices.
 *
 * Three shapes were available and two of them are worse:
 *
 *  - stop the batch on the first refusal, and one held record blocks a retention
 *    obligation for everything else;
 *  - skip silently, and the reasons never reach anybody while the sweep reports
 *    a clean run over records it did not touch;
 *  - refuse per record, count the refusals apart, and keep every reason. That
 *    is what these tests pin.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Archival
 *
 * @author   Conduction Development Team <dev@conduction.nl>
 * @license  EUPL-1.2
 *
 * @spec openspec/changes/anonymising-as-an-archival-outcome/specs/retention-management/spec.md
 */

namespace Unit\Service\Archival;

use OCA\OpenRegister\Service\Archival\AnonymisationProfile;
use OCA\OpenRegister\Service\Archival\AnonymisationRun;
use OCA\OpenRegister\Service\Archival\AnonymisationSweep;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests for AnonymisationSweep.
 */
class AnonymisationSweepTest extends TestCase {

	private AnonymisationSweep $sweep;

	/**
	 * Wire the sweep over the real run.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->sweep = new AnonymisationSweep(run: new AnonymisationRun(), logger: new NullLogger());
	}//end setUp()

	/**
	 * One candidate, overridable.
	 *
	 * @param string               $uuid    Its uuid.
	 * @param array<string, mixed> $extra   Fields to override.
	 *
	 * @return array<string, mixed> The candidate.
	 */
	private function candidate(string $uuid, array $extra = []): array {
		return array_merge(
			[
				'uuid' => $uuid,
				'payload' => ['naam' => 'Fatima El-Amrani', 'zaaknummer' => 'ZK-1'],
				'annotation' => [
					AnonymisationProfile::ANNOTATION_KEY => ['naam' => ['treatment' => AnonymisationProfile::REMOVE]],
				],
				'marker' => [],
				'hasLegalHold' => false,
				'holdReason' => '',
				'profileName' => 'zaak-statistiek',
			],
			$extra
		);
	}//end candidate()

	/**
	 * Targets that always succeed.
	 *
	 * @return callable The factory.
	 */
	private function workingTargets(): callable {
		return static function (): array {
			return [new RecordingTarget(name: 'payload')];
		};
	}//end workingTargets()

	/**
	 * A batch of sound candidates is anonymised.
	 *
	 * @return void
	 */
	public function testASoundBatchIsAnonymised(): void {
		$report = $this->sweep->run(
			candidates: [$this->candidate('obj-1'), $this->candidate('obj-2')],
			targetsFor: $this->workingTargets(),
			salt: 'instance-salt'
		);

		$this->assertSame(2, $report['anonymisedCount']);
		$this->assertSame(0, $report['refusedCount']);
	}//end testASoundBatchIsAnonymised()

	/**
	 * 🔴 ONE HELD RECORD DOES NOT BLOCK THE REST, AND DOES NOT VANISH EITHER.
	 * Stopping the batch would let a single hold block a retention obligation
	 * for everything else; skipping it silently would report a clean run.
	 *
	 * @return void
	 */
	public function testAHeldRecordIsRefusedAndTheRestOfTheBatchRuns(): void {
		$report = $this->sweep->run(
			candidates: [
				$this->candidate('obj-1', ['hasLegalHold' => true, 'holdReason' => 'bezwaar 2026-114']),
				$this->candidate('obj-2'),
			],
			targetsFor: $this->workingTargets(),
			salt: 'instance-salt'
		);

		$this->assertSame(1, $report['anonymisedCount']);
		$this->assertSame(1, $report['refusedCount']);
		$this->assertSame('obj-1', $report['refused'][0]['uuid']);
		$this->assertStringContainsString('bezwaar 2026-114', $report['refused'][0]['reason']);
	}//end testAHeldRecordIsRefusedAndTheRestOfTheBatchRuns()

	/**
	 * An already-anonymised record is refused by the sweep too, so a nightly
	 * run does not keep rewriting the same records.
	 *
	 * @return void
	 */
	public function testAnAlreadyAnonymisedRecordIsRefusedByTheSweep(): void {
		$report = $this->sweep->run(
			candidates: [
				$this->candidate('obj-1', [
					'marker' => ['state' => AnonymisationRun::COMPLETE, 'anonymisedAt' => '2026-09-01', 'profile' => 'p'],
				]),
			],
			targetsFor: $this->workingTargets(),
			salt: 'instance-salt'
		);

		$this->assertSame(0, $report['anonymisedCount']);
		$this->assertStringContainsString('already anonymised', $report['refused'][0]['reason']);
	}//end testAnAlreadyAnonymisedRecordIsRefusedByTheSweep()

	/**
	 * 🔴 A RECORD WHOSE TARGET CANNOT BE REACHED IS COUNTED AS REFUSED, NOT AS
	 * DONE. A sweep that reports only its successes is the instrument reporting
	 * green over the work it did not do.
	 *
	 * @return void
	 */
	public function testAnUnreachableTargetIsCountedAsRefusedNotAsDone(): void {
		$report = $this->sweep->run(
			candidates: [$this->candidate('obj-1'), $this->candidate('obj-2')],
			targetsFor: static function (array $candidate): array {
				if (($candidate['uuid'] ?? '') === 'obj-1') {
					return [new RecordingTarget(name: 'search index', failPrepare: true)];
				}

				return [new RecordingTarget(name: 'payload')];
			},
			salt: 'instance-salt'
		);

		$this->assertSame(1, $report['anonymisedCount']);
		$this->assertSame(1, $report['refusedCount']);
		$this->assertStringContainsString('search index', $report['refused'][0]['reason']);
	}//end testAnUnreachableTargetIsCountedAsRefusedNotAsDone()

	/**
	 * A record whose profile would change nothing is refused rather than
	 * marked anonymised while holding every value it held before.
	 *
	 * @return void
	 */
	public function testARecordTheProfileWouldNotTouchIsRefused(): void {
		$report = $this->sweep->run(
			candidates: [$this->candidate('obj-1', ['payload' => ['zaaknummer' => 'ZK-1']])],
			targetsFor: $this->workingTargets(),
			salt: 'instance-salt'
		);

		$this->assertSame(0, $report['anonymisedCount']);
		$this->assertSame(1, $report['refusedCount']);
	}//end testARecordTheProfileWouldNotTouchIsRefused()

	/**
	 * A candidate with no uuid is refused: an anonymisation nobody can point at
	 * afterwards is not auditable.
	 *
	 * @return void
	 */
	public function testACandidateWithoutAUuidIsRefused(): void {
		$report = $this->sweep->run(
			candidates: [$this->candidate('')],
			targetsFor: $this->workingTargets(),
			salt: 'instance-salt'
		);

		$this->assertSame(1, $report['refusedCount']);
		$this->assertStringContainsString('no uuid', $report['refused'][0]['reason']);
	}//end testACandidateWithoutAUuidIsRefused()

	/**
	 * 🔴 THE TWO COUNTS ARE SEPARATE. "412 anonymised" and "412 anonymised, 9
	 * refused" are different sentences, and a sweep that adds them says neither.
	 *
	 * @return void
	 */
	public function testTheRefusalsAreCountedApartFromTheSuccesses(): void {
		$report = $this->sweep->run(
			candidates: [
				$this->candidate('obj-1', ['hasLegalHold' => true, 'holdReason' => 'bezwaar']),
				$this->candidate('obj-2'),
				$this->candidate('obj-3', ['hasLegalHold' => true, 'holdReason' => 'bezwaar']),
			],
			targetsFor: $this->workingTargets(),
			salt: 'instance-salt'
		);

		$this->assertSame(1, $report['anonymisedCount']);
		$this->assertSame(2, $report['refusedCount']);
		$this->assertCount(2, $report['refused']);
		foreach ($report['refused'] as $refusal) {
			$this->assertNotSame('', $refusal['reason'], 'every refusal must carry a reason somebody can act on');
		}
	}//end testTheRefusalsAreCountedApartFromTheSuccesses()
}//end class
