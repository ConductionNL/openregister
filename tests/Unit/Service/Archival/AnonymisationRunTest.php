<?php

declare(strict_types=1);

/**
 * When an anonymisation may run, and what it leaves when it cannot finish.
 *
 * 🔴 EVERY AMBIGUITY HERE IS ANSWERED WITH A STOP. The act is irreversible and
 * reaches several stores, so a best effort is the wrong shape: a run that got
 * halfway and reported success would leave a record that every screen calls
 * anonymised while the search index still answers a query for the name, and
 * nobody looks at an anonymised record twice.
 *
 * The two questions these tests exist to answer out loud:
 *
 *  - what a run does to a record that is ALREADY anonymised: it refuses, names
 *    when and under which profile, and says what a second run would cost;
 *  - whether a half-finished run leaves a record nothing can finish: it does
 *    not, PROVIDED the salt has not rotated. The marker carries the salt
 *    fingerprint, a resume under the same salt re-derives the same plan, and a
 *    resume under a different one refuses rather than leaving one record
 *    carrying two families of pseudonym.
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

use OCA\OpenRegister\Service\Archival\AnonymisationPlan;
use OCA\OpenRegister\Service\Archival\AnonymisationProfile;
use OCA\OpenRegister\Service\Archival\AnonymisationRefusedException;
use OCA\OpenRegister\Service\Archival\AnonymisationRun;
use OCA\OpenRegister\Service\Archival\AnonymisationTarget;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * A target that records what it was asked to do, and can be told to fail.
 */
final class RecordingTarget implements AnonymisationTarget {

	/** @var string[] What happened, in order. */
	public array $calls = [];

	/**
	 * Build the double.
	 *
	 * @param string $name        What this target is called.
	 * @param bool   $failPrepare Whether preparation throws.
	 * @param bool   $failApply   Whether the write throws.
	 */
	public function __construct(
		private readonly string $name,
		private readonly bool $failPrepare = false,
		private readonly bool $failApply = false,
	) {
	}//end __construct()

	/**
	 * What this target is.
	 *
	 * @return string The name.
	 */
	public function name(): string {
		return $this->name;
	}//end name()

	/**
	 * Prepare, or fail.
	 *
	 * @param AnonymisationPlan $plan The plan.
	 *
	 * @return void
	 */
	public function prepare(AnonymisationPlan $plan): void {
		$this->calls[] = 'prepare';
		if ($this->failPrepare === true) {
			throw new RuntimeException('the index is offline');
		}
	}//end prepare()

	/**
	 * Write, or fail.
	 *
	 * @param AnonymisationPlan $plan The plan.
	 *
	 * @return void
	 */
	public function apply(AnonymisationPlan $plan): void {
		$this->calls[] = 'apply';
		if ($this->failApply === true) {
			throw new RuntimeException('the write was rejected');
		}
	}//end apply()
}//end class

/**
 * Tests for AnonymisationRun.
 */
class AnonymisationRunTest extends TestCase {

	private AnonymisationRun $run;

	/**
	 * Wire the run with its real, pure collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->run = new AnonymisationRun();
	}//end setUp()

	/**
	 * A record with a name and a case number.
	 *
	 * @return array<string, mixed> The payload.
	 */
	private function payload(): array {
		return ['naam' => 'Fatima El-Amrani', 'zaaknummer' => 'ZK-2026-0041'];
	}//end payload()

	/**
	 * An annotation removing the name and keeping the case number.
	 *
	 * @return array<string, mixed> The annotation.
	 */
	private function annotation(): array {
		return [
			AnonymisationProfile::ANNOTATION_KEY => [
				'naam' => ['treatment' => AnonymisationProfile::REMOVE],
			],
		];
	}//end annotation()

	/**
	 * A plan over that record.
	 *
	 * @return AnonymisationPlan The plan.
	 */
	private function plan(): AnonymisationPlan {
		return $this->run->plan(
			objectUuid: 'obj-1',
			payload: $this->payload(),
			annotation: $this->annotation(),
			salt: 'instance-salt',
			profileName: 'zaak-statistiek'
		);
	}//end plan()

	/**
	 * 🔴 A LEGAL HOLD STOPS IT, AND THE REFUSAL NAMES THE HOLD. A hold says
	 * somebody may still need the record as it is, and as it is includes the
	 * name.
	 *
	 * @return void
	 */
	public function testARecordUnderALegalHoldIsNotAnonymised(): void {
		$refusal = $this->run->refuse(
			marker: [],
			hasLegalHold: true,
			holdReason: 'bezwaarprocedure 2026-114',
			saltFingerprint: 'abc'
		);

		$this->assertNotNull($refusal);
		$this->assertStringContainsString('bezwaarprocedure 2026-114', $refusal);
	}//end testARecordUnderALegalHoldIsNotAnonymised()

	/**
	 * A hold with no recorded reason still refuses, and says the reason is
	 * missing rather than printing an empty pair of brackets.
	 *
	 * @return void
	 */
	public function testAHoldWithoutAReasonStillRefuses(): void {
		$refusal = $this->run->refuse(marker: [], hasLegalHold: true, holdReason: '  ', saltFingerprint: 'abc');

		$this->assertStringContainsString('no reason was recorded', (string)$refusal);
	}//end testAHoldWithoutAReasonStillRefuses()

	/**
	 * 🔴 WHAT A RUN DOES TO A RECORD THAT IS ALREADY ANONYMISED: IT REFUSES,
	 * and says when, under which profile, and what running again would cost.
	 *
	 * @return void
	 */
	public function testAnAlreadyAnonymisedRecordIsRefusedWithWhenAndWhy(): void {
		$refusal = $this->run->refuse(
			marker: [
				'state' => AnonymisationRun::COMPLETE,
				'anonymisedAt' => '2026-09-01T10:00:00+00:00',
				'profile' => 'zaak-statistiek',
			],
			hasLegalHold: false,
			holdReason: '',
			saltFingerprint: 'abc'
		);

		$this->assertNotNull($refusal);
		$this->assertStringContainsString('2026-09-01', $refusal);
		$this->assertStringContainsString('zaak-statistiek', $refusal);
		$this->assertStringContainsString('stop joining', $refusal);
	}//end testAnAlreadyAnonymisedRecordIsRefusedWithWhenAndWhy()

	/**
	 * 🔴 A HALF-FINISHED RUN IS FINISHABLE UNDER THE SAME SALT. This is the
	 * answer to "does a half-finished run leave a record nothing can finish":
	 * no, and this is the case that proves it.
	 *
	 * @return void
	 */
	public function testAHalfFinishedRunMayBeResumedUnderTheSameSalt(): void {
		$this->assertNull(
			$this->run->refuse(
				marker: ['state' => AnonymisationRun::IN_PROGRESS, 'saltFingerprint' => 'abc'],
				hasLegalHold: false,
				holdReason: '',
				saltFingerprint: 'abc'
			)
		);
	}//end testAHalfFinishedRunMayBeResumedUnderTheSameSalt()

	/**
	 * 🔴 AND IT IS REFUSED UNDER A DIFFERENT ONE. Finishing with a second salt
	 * leaves one record carrying two families of pseudonym, and nothing on any
	 * screen would say so.
	 *
	 * @return void
	 */
	public function testAHalfFinishedRunIsRefusedUnderARotatedSalt(): void {
		$refusal = $this->run->refuse(
			marker: ['state' => AnonymisationRun::IN_PROGRESS, 'saltFingerprint' => 'abc'],
			hasLegalHold: false,
			holdReason: '',
			saltFingerprint: 'def'
		);

		$this->assertStringContainsString('two families of pseudonym', (string)$refusal);
	}//end testAHalfFinishedRunIsRefusedUnderARotatedSalt()

	/**
	 * A clean record is not refused, so the refusals above are not a blanket.
	 *
	 * @return void
	 */
	public function testACleanRecordIsNotRefused(): void {
		$this->assertNull($this->run->refuse(marker: [], hasLegalHold: false, holdReason: '', saltFingerprint: 'abc'));
	}//end testACleanRecordIsNotRefused()

	/**
	 * 🔴 A RUN THAT WOULD CHANGE NOTHING IS REFUSED. Marking a record
	 * anonymised while it holds every value it held before is the instrument
	 * lying about the thing it measures.
	 *
	 * @return void
	 */
	public function testAPlanThatWouldChangeNothingIsRefused(): void {
		$this->expectException(AnonymisationRefusedException::class);
		$this->expectExceptionMessageMatches('/would change nothing/');

		$this->run->plan(
			objectUuid: 'obj-2',
			payload: ['zaaknummer' => 'ZK-1'],
			annotation: [AnonymisationProfile::ANNOTATION_KEY => []],
			salt: 'instance-salt'
		);
	}//end testAPlanThatWouldChangeNothingIsRefused()

	/**
	 * The plan names what changes and what is deliberately kept, before
	 * anything is irreversible.
	 *
	 * @return void
	 */
	public function testThePlanNamesWhatChangesAndWhatIsKept(): void {
		$plan = $this->plan();

		$this->assertSame(['naam'], $plan->changedProperties);
		$this->assertSame(['zaaknummer'], $plan->keptProperties);
		$this->assertArrayNotHasKey('naam', $plan->after);
	}//end testThePlanNamesWhatChangesAndWhatIsKept()

	/**
	 * 🔴 EVERY TARGET IS PREPARED BEFORE ANY IS APPLIED. A store that cannot be
	 * reached stops the act while the record is still whole.
	 *
	 * @return void
	 */
	public function testEveryTargetIsPreparedBeforeAnyIsApplied(): void {
		$payload = new RecordingTarget(name: 'payload');
		$index = new RecordingTarget(name: 'search index');

		$this->run->apply(plan: $this->plan(), targets: [$payload, $index]);

		$this->assertSame(['prepare', 'apply'], $payload->calls);
		$this->assertSame(['prepare', 'apply'], $index->calls);
	}//end testEveryTargetIsPreparedBeforeAnyIsApplied()

	/**
	 * 🔴 A TARGET THAT CANNOT BE REACHED STOPS THE ACT AND NOTHING IS WRITTEN.
	 * The first target must not have applied, or the record is half done.
	 *
	 * @return void
	 */
	public function testAnUnreachableTargetStopsTheActBeforeAnythingIsWritten(): void {
		$payload = new RecordingTarget(name: 'payload');
		$index = new RecordingTarget(name: 'search index', failPrepare: true);

		try {
			$this->run->apply(plan: $this->plan(), targets: [$payload, $index]);
			$this->fail('the run should have refused');
		} catch (AnonymisationRefusedException $refusal) {
			$this->assertStringContainsString('search index', $refusal->getMessage());
			$this->assertStringContainsString('The record is unchanged', $refusal->getMessage());
		}

		$this->assertNotContains('apply', $payload->calls, 'nothing may be written once a target has refused');
	}//end testAnUnreachableTargetStopsTheActBeforeAnythingIsWritten()

	/**
	 * 🔴 A FAILURE BETWEEN WRITES SAYS SO PLAINLY, AND DOES NOT IMPLY A
	 * ROLLBACK THAT DID NOT HAPPEN. The stores are separate and no transaction
	 * spans them; what the run promises instead is that the record can be
	 * finished by running it again.
	 *
	 * @return void
	 */
	public function testAFailureBetweenWritesSaysWhatWasWrittenAndThatItCanBeFinished(): void {
		$payload = new RecordingTarget(name: 'payload');
		$index = new RecordingTarget(name: 'search index', failApply: true);

		try {
			$this->run->apply(plan: $this->plan(), targets: [$payload, $index]);
			$this->fail('the run should have refused');
		} catch (AnonymisationRefusedException $refusal) {
			$this->assertStringContainsString('writing payload', $refusal->getMessage());
			$this->assertStringContainsString('can be finished by running it again', $refusal->getMessage());
			$this->assertStringContainsString('must not be treated as anonymised', $refusal->getMessage());
		}
	}//end testAFailureBetweenWritesSaysWhatWasWrittenAndThatItCanBeFinished()

	/**
	 * A run with no stores to reach is a misconfiguration, not a success.
	 *
	 * @return void
	 */
	public function testARunWithNoTargetsIsRefused(): void {
		$this->expectException(AnonymisationRefusedException::class);
		$this->expectExceptionMessageMatches('/no stores to reach/');

		$this->run->apply(plan: $this->plan(), targets: []);
	}//end testARunWithNoTargetsIsRefused()

	/**
	 * A completed run marks the record, naming the profile, the salt it used
	 * and both property lists.
	 *
	 * @return void
	 */
	public function testACompletedRunMarksTheRecord(): void {
		$marker = $this->run->apply(plan: $this->plan(), targets: [new RecordingTarget(name: 'payload')]);

		$this->assertSame(AnonymisationRun::COMPLETE, $marker['state']);
		$this->assertSame('zaak-statistiek', $marker['profile']);
		$this->assertSame(['naam'], $marker['changed']);
		$this->assertSame(['zaaknummer'], $marker['kept']);
	}//end testACompletedRunMarksTheRecord()

	/**
	 * 🔴 THE MARKER CARRIES A FINGERPRINT, NOT THE SALT. The salt is what makes
	 * the pseudonyms unguessable, and the record is the one place it must not
	 * be written.
	 *
	 * @return void
	 */
	public function testTheMarkerCarriesAFingerprintAndNotTheSalt(): void {
		$marker = $this->run->apply(plan: $this->plan(), targets: [new RecordingTarget(name: 'payload')]);

		$this->assertNotSame('instance-salt', $marker['saltFingerprint']);
		$this->assertStringNotContainsString('instance-salt', json_encode($marker));
		$this->assertNotSame(
			$this->run->fingerprint(salt: 'instance-salt'),
			$this->run->fingerprint(salt: 'another-salt')
		);
	}//end testTheMarkerCarriesAFingerprintAndNotTheSalt()
}//end class
