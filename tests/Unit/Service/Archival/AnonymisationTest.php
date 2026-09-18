<?php

declare(strict_types=1);

/**
 * Anonymising: what a record loses, and what it goes on saying.
 *
 * 🔴 THE FAILURE THESE EXIST FOR IS A REPORT OF SUCCESS OVER A RECORD THAT WAS
 * NOT CHANGED. A profile naming a property the schema spells differently, a
 * treatment the vocabulary does not know, a date that will not parse: each of
 * them would otherwise leave the value exactly where it was while every screen
 * says the record is anonymised. Nobody looks at an anonymised record twice.
 *
 * So the assertions are about the values that are GONE and the values that are
 * still there by name, never about a count or a status.
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

use InvalidArgumentException;
use OCA\OpenRegister\Service\Archival\AnonymisationPlanner;
use OCA\OpenRegister\Service\Archival\AnonymisationProfile;
use OCA\OpenRegister\Service\Archival\AnonymisationService;
use OCA\OpenRegister\Service\Archival\Appraisal;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the anonymisation vocabulary, planner and service.
 */
class AnonymisationTest extends TestCase {

	private AnonymisationService $service;
	private AnonymisationPlanner $planner;

	/**
	 * Wire the two collaborators, both of which are pure.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->service = new AnonymisationService();
		$this->planner = new AnonymisationPlanner();
	}//end setUp()

	/**
	 * A payload with one of each kind of personal value.
	 *
	 * @return array<string, mixed> The payload.
	 */
	private function payload(): array {
		return [
			'naam' => 'Fatima El-Amrani',
			'bsn' => '123456782',
			'geboortedatum' => '1984-03-17',
			'postcode' => '2511 CV',
			'zaaknummer' => 'ZK-2026-0041',
			'uitkomst' => 'toegekend',
		];
	}//end payload()

	/**
	 * The profile the tests below declare.
	 *
	 * @return array<string, mixed> The annotation block.
	 */
	private function annotation(): array {
		return [
			'action' => 'anonymiseren',
			AnonymisationProfile::ANNOTATION_KEY => [
				'naam' => ['treatment' => AnonymisationProfile::REMOVE],
				'bsn' => ['treatment' => AnonymisationProfile::PSEUDONYM],
				'geboortedatum' => ['treatment' => AnonymisationProfile::GENERALISE, 'grain' => AnonymisationProfile::GRAIN_YEAR],
				'postcode' => ['treatment' => AnonymisationProfile::GENERALISE, 'grain' => AnonymisationProfile::GRAIN_POSTCODE_DISTRICT],
				'uitkomst' => ['treatment' => AnonymisationProfile::FIXED, 'value' => 'afgehandeld'],
			],
		];
	}//end annotation()

	/**
	 * The vocabulary carries a fourth word, in every spelling that occurs.
	 *
	 * @return void
	 */
	public function testAnonymiseIsAnAppraisal(): void {
		$this->assertContains(Appraisal::ANONYMISE, Appraisal::ALL);
		$this->assertSame(Appraisal::ANONYMISE, Appraisal::CANONICAL['anonymiseren']);
		$this->assertSame(Appraisal::ANONYMISE, Appraisal::CANONICAL['anonymise']);
		$this->assertSame(Appraisal::ANONYMISE, Appraisal::CANONICAL['anonymize']);
	}//end testAnonymiseIsAnAppraisal()

	/**
	 * Remove takes the property out; nothing is left to read.
	 *
	 * @return void
	 */
	public function testRemoveLeavesNothingToRead(): void {
		$after = $this->service->apply(
			payload: $this->payload(),
			profile: ['naam' => ['treatment' => AnonymisationProfile::REMOVE]],
			salt: 'salt'
		);

		$this->assertArrayNotHasKey('naam', $after);
		$this->assertStringNotContainsString('Fatima', json_encode($after));
	}//end testRemoveLeavesNothingToRead()

	/**
	 * Fixed makes two different people indistinguishable.
	 *
	 * @return void
	 */
	public function testFixedWritesOneValueOverEveryone(): void {
		$profile = ['uitkomst' => ['treatment' => AnonymisationProfile::FIXED, 'value' => 'afgehandeld']];

		$one = $this->service->apply(payload: ['uitkomst' => 'toegekend'], profile: $profile, salt: 's');
		$two = $this->service->apply(payload: ['uitkomst' => 'afgewezen'], profile: $profile, salt: 's');

		$this->assertSame('afgehandeld', $one['uitkomst']);
		$this->assertSame($one['uitkomst'], $two['uitkomst']);
	}//end testFixedWritesOneValueOverEveryone()

	/**
	 * 🔴 THE PSEUDONYM JOINS TWO ROWS AND NAMES NOBODY. This is the treatment
	 * that keeps statistics usable: the municipality can still count how many
	 * cases one person had. It is also the one that carries residual risk, so
	 * the test pins both halves — the same input gives the same token, a
	 * different input does not, and neither token contains the value.
	 *
	 * @return void
	 */
	public function testThePseudonymJoinsTwoRowsWithoutNamingAnyone(): void {
		$profile = ['bsn' => ['treatment' => AnonymisationProfile::PSEUDONYM]];

		$first = $this->service->apply(payload: ['bsn' => '123456782'], profile: $profile, salt: 'instance-salt');
		$again = $this->service->apply(payload: ['bsn' => '123456782'], profile: $profile, salt: 'instance-salt');
		$other = $this->service->apply(payload: ['bsn' => '987654321'], profile: $profile, salt: 'instance-salt');

		$this->assertSame($first['bsn'], $again['bsn'], 'the same person must still join across rows');
		$this->assertNotSame($first['bsn'], $other['bsn'], 'two people must not collapse into one');
		$this->assertStringNotContainsString('123456782', (string)$first['bsn']);
	}//end testThePseudonymJoinsTwoRowsWithoutNamingAnyone()

	/**
	 * A different salt gives a different token, so one instance's tokens do not
	 * join against another's.
	 *
	 * @return void
	 */
	public function testTheSaltSeparatesInstances(): void {
		$this->assertNotSame(
			$this->service->pseudonymFor(value: '123456782', salt: 'one'),
			$this->service->pseudonymFor(value: '123456782', salt: 'two')
		);
	}//end testTheSaltSeparatesInstances()

	/**
	 * Generalise coarsens rather than removes, so the value still says
	 * something true about too many people to identify one.
	 *
	 * @return void
	 */
	public function testGeneraliseCoarsensADateAndAPostcode(): void {
		$after = $this->service->apply(payload: $this->payload(), profile: $this->annotation()[AnonymisationProfile::ANNOTATION_KEY], salt: 's');

		$this->assertSame('1984', $after['geboortedatum']);
		$this->assertSame('2511', $after['postcode']);
	}//end testGeneraliseCoarsensADateAndAPostcode()

	/**
	 * 🔴 A VALUE THAT CANNOT BE COARSENED IS NOT LEFT AS IT WAS. Leaving the
	 * exact date in place because it would not parse is the silent pass-through
	 * this change exists to remove: the report would say generalised and the
	 * record would still hold the day somebody was born.
	 *
	 * @return void
	 */
	public function testAValueThatWillNotCoarsenIsNotLeftInPlace(): void {
		$after = $this->service->apply(
			payload: ['geboortedatum' => 'onbekend, zie dossier'],
			profile: ['geboortedatum' => ['treatment' => AnonymisationProfile::GENERALISE, 'grain' => AnonymisationProfile::GRAIN_YEAR]],
			salt: 's'
		);

		$this->assertNull($after['geboortedatum']);
	}//end testAValueThatWillNotCoarsenIsNotLeftInPlace()

	/**
	 * 🔴 THE REPORT NAMES WHAT STAYED. A record with the name removed and the
	 * date of birth, the postcode and the case number intact is not anonymous,
	 * and a report listing only removals invites the reader to assume the rest
	 * was never personal.
	 *
	 * @return void
	 */
	public function testTheReportNamesWhatWasDeliberatelyKept(): void {
		$before = $this->payload();
		$after = $this->service->apply(payload: $before, profile: $this->annotation()[AnonymisationProfile::ANNOTATION_KEY], salt: 's');

		$report = $this->service->report(before: $before, after: $after, profileName: 'zaak-statistiek');

		$this->assertSame(['zaaknummer'], $report['kept']);
		$this->assertSame(AnonymisationService::REMOVED, $report['changed']['naam']);
		$this->assertArrayHasKey('bsn', $report['changed']);
		$this->assertSame('zaak-statistiek', $report['profile']);
	}//end testTheReportNamesWhatWasDeliberatelyKept()

	/**
	 * 🔴 A PROFILE NAMING A PROPERTY THE SCHEMA DOES NOT DECLARE IS REFUSED.
	 * Skipping it silently is the failure that matters: the profile says the
	 * name is removed, the schema spells it differently, and the run reports
	 * success over a record that still holds the name.
	 *
	 * @return void
	 */
	public function testAProfileNamingAnUndeclaredPropertyIsRefused(): void {
		$refusals = $this->planner->refusals(
			annotation: [AnonymisationProfile::ANNOTATION_KEY => ['naamVanBetrokkene' => ['treatment' => AnonymisationProfile::REMOVE]]],
			declaredProperties: ['naam', 'bsn']
		);

		$this->assertCount(1, $refusals);
		$this->assertStringContainsString('naamVanBetrokkene', $refusals[0]);
	}//end testAProfileNamingAnUndeclaredPropertyIsRefused()

	/**
	 * A treatment nobody recognises is refused rather than skipped.
	 *
	 * @return void
	 */
	public function testAnUnknownTreatmentIsRefused(): void {
		$refusals = $this->planner->refusals(
			annotation: [AnonymisationProfile::ANNOTATION_KEY => ['naam' => ['treatment' => 'obfuscate']]],
			declaredProperties: ['naam']
		);

		$this->assertCount(1, $refusals);
		$this->assertStringContainsString('obfuscate', $refusals[0]);
	}//end testAnUnknownTreatmentIsRefused()

	/**
	 * Fixed with nothing to write, and generalise with no grain, are refused.
	 *
	 * @return void
	 */
	public function testARuleThatCannotBeCarriedOutIsRefused(): void {
		$refusals = $this->planner->refusals(
			annotation: [
				AnonymisationProfile::ANNOTATION_KEY => [
					'uitkomst' => ['treatment' => AnonymisationProfile::FIXED],
					'geboortedatum' => ['treatment' => AnonymisationProfile::GENERALISE, 'grain' => 'decade'],
				],
			],
			declaredProperties: ['uitkomst', 'geboortedatum']
		);

		$this->assertCount(2, $refusals);
	}//end testARuleThatCannotBeCarriedOutIsRefused()

	/**
	 * A sound profile refuses nothing, so the refusals above are not a blanket.
	 *
	 * @return void
	 */
	public function testASoundProfileIsAccepted(): void {
		$this->assertSame(
			[],
			$this->planner->refusals(
				annotation: $this->annotation(),
				declaredProperties: array_keys($this->payload())
			)
		);
	}//end testASoundProfileIsAccepted()

	/**
	 * The loud form throws, for a caller that wants a save to fail.
	 *
	 * @return void
	 */
	public function testAssertSoundThrowsOnAnUnsoundProfile(): void {
		$this->expectException(InvalidArgumentException::class);

		$this->planner->assertSound(
			annotation: [AnonymisationProfile::ANNOTATION_KEY => ['weg' => ['treatment' => AnonymisationProfile::REMOVE]]],
			declaredProperties: ['naam']
		);
	}//end testAssertSoundThrowsOnAnUnsoundProfile()

	/**
	 * The plan answers before anything is irreversible, with the same two lists
	 * the report prints afterwards.
	 *
	 * @return void
	 */
	public function testThePlanSaysWhatWouldBeKeptBeforeAnythingHappens(): void {
		$plan = $this->planner->plan(annotation: $this->annotation(), payload: $this->payload());

		$this->assertSame(['zaaknummer'], $plan['kept']);
		$this->assertContains('bsn', $plan['changed']);
	}//end testThePlanSaysWhatWouldBeKeptBeforeAnythingHappens()
}//end class
