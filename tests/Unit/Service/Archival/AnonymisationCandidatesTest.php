<?php

declare(strict_types=1);

/**
 * Which records a retention pass hands to the anonymisation sweep.
 *
 * A result type that says `anonymiseren` makes its records anonymisation
 * candidates once their archiefactiedatum passes, never destruction
 * candidates; a destroy nomination never becomes an anonymisation one.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
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

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Archival\AnonymisationBatch;
use OCA\OpenRegister\Service\Archival\AnonymisationCandidates;
use OCA\OpenRegister\Service\Archival\AnonymisationProfile;
use OCA\OpenRegister\Service\Archival\AnonymisationRun;
use OCA\OpenRegister\Service\Archival\AnonymisationSweep;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

// RecordingTarget is declared with the run's tests.
require_once __DIR__ . '/AnonymisationRunTest.php';

/**
 * Tests for AnonymisationCandidates.
 */
#[CoversClass(AnonymisationCandidates::class)]
#[UsesClass(ObjectEntity::class)]
#[UsesClass(Schema::class)]
#[UsesClass(\OCA\OpenRegister\Service\Archival\AnonymisationSweep::class)]
#[UsesClass(\OCA\OpenRegister\Service\Archival\AnonymisationRun::class)]
#[UsesClass(\OCA\OpenRegister\Service\Archival\AnonymisationBatch::class)]
#[UsesClass(\OCA\OpenRegister\Service\Archival\AnonymisationService::class)]
#[UsesClass(\OCA\OpenRegister\Service\Archival\AnonymisationPlanner::class)]
#[UsesClass(\OCA\OpenRegister\Service\Archival\AnonymisationPlan::class)]
#[UsesClass(\OCA\OpenRegister\Service\Archival\AnonymisationClearance::class)]
#[UsesClass(\OCA\OpenRegister\Service\Archival\AnonymisationSubject::class)]
#[UsesClass(\OCA\OpenRegister\Service\Archival\AnonymisedPayload::class)]
#[UsesClass(\OCA\OpenRegister\Service\Archival\SaltedProfile::class)]
#[UsesClass(\OCA\OpenRegister\Service\Archival\PayloadChange::class)]
#[UsesClass(\OCA\OpenRegister\Service\Archival\AnonymisationPass::class)]
#[UsesClass(\OCA\OpenRegister\Service\Archival\TargetWriteRun::class)]
#[UsesClass(\OCA\OpenRegister\Service\Archival\AnonymisationProfile::class)]
class AnonymisationCandidatesTest extends TestCase {

	private const TODAY = '2026-11-14';

	private AnonymisationCandidates $candidates;

	protected function setUp(): void {
		$this->candidates = new AnonymisationCandidates();
	}//end setUp()

	/**
	 * A schema declaring a profile in `x-openregister-archival`, or in `archive`.
	 *
	 * @param string $where `archival`, `archive` or `none`.
	 *
	 * @return Schema
	 */
	private function schema(string $where = 'archival'): Schema {
		$profile = [AnonymisationProfile::ANNOTATION_KEY => ['naam' => ['treatment' => AnonymisationProfile::REMOVE]]];
		$schema  = new Schema();
		$schema->setSlug('zaak');
		$schema->setTitle('Zaak');
		$schema->setConfiguration(
			$where === 'archival' ? ['x-openregister-archival' => ['retention' => ['period' => 'P10Y']] + $profile] : []
		);
		$schema->setArchive($where === 'archive' ? $profile : []);

		return $schema;
	}//end schema()

	/**
	 * A record with this retention block.
	 *
	 * @param array<string, mixed> $retention The retention block.
	 *
	 * @return ObjectEntity
	 */
	private function record(array $retention): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid('obj-1');
		$object->setObject(['naam' => 'Fatima El-Amrani', 'zaaknummer' => 'ZK-1']);
		$object->setRetention($retention);

		return $object;
	}//end record()

	/**
	 * Every spelling a result type may use for the action makes a candidate.
	 *
	 * @return array<string, array{string}>
	 */
	public static function anonymiseSpellings(): array {
		return ['anonymiseren' => ['anonymiseren'], 'anonymise' => ['anonymise'], 'anonymize' => ['anonymize']];
	}//end anonymiseSpellings()

	#[DataProvider('anonymiseSpellings')]
	public function testADueAnonymiseNominationIsACandidate(string $spelling): void {
		$candidate = $this->candidates->candidateOf(
			object: $this->record(['archiefnominatie' => $spelling, 'archiefactiedatum' => '2026-11-01']),
			schema: $this->schema(),
			today: self::TODAY
		);

		$this->assertSame(
			[
				'uuid'         => 'obj-1',
				'payload'      => ['id' => 'obj-1', 'naam' => 'Fatima El-Amrani', 'zaaknummer' => 'ZK-1'],
				'annotation'   => [
					'retention' => ['period' => 'P10Y'],
					AnonymisationProfile::ANNOTATION_KEY => ['naam' => ['treatment' => AnonymisationProfile::REMOVE]],
				],
				'profileName'  => 'zaak',
				'marker'       => [],
				'hasLegalHold' => false,
				'holdReason'   => '',
			],
			$candidate
		);
	}//end testADueAnonymiseNominationIsACandidate()

	/**
	 * Destroy, retain and undecided nominations are not anonymised.
	 *
	 * @return array<string, array{string}>
	 */
	public static function otherNominations(): array {
		return ['vernietigen' => ['vernietigen'], 'destroy' => ['destroy'], 'bewaren' => ['bewaren'], 'nog_niet_bepaald' => ['nog_niet_bepaald']];
	}//end otherNominations()

	#[DataProvider('otherNominations')]
	public function testAnotherNominationIsNotACandidate(string $nomination): void {
		$record = $this->record(['archiefnominatie' => $nomination, 'archiefactiedatum' => '2026-11-01']);

		$this->assertSame(AnonymisationCandidates::REFUSAL_NOT_NOMINATED, $this->candidates->refusalOf(object: $record, schema: $this->schema(), today: self::TODAY));
		$this->assertNull($this->candidates->candidateOf(object: $record, schema: $this->schema(), today: self::TODAY));
	}//end testAnotherNominationIsNotACandidate()

	public function testANominationNotYetDueWaits(): void {
		$future = $this->record(['archiefnominatie' => 'anonymiseren', 'archiefactiedatum' => '2026-11-15']);
		$undated = $this->record(['archiefnominatie' => 'anonymiseren']);

		$this->assertSame(AnonymisationCandidates::REFUSAL_NOT_DUE, $this->candidates->refusalOf(object: $future, schema: $this->schema(), today: self::TODAY));
		$this->assertSame(AnonymisationCandidates::REFUSAL_NOT_DUE, $this->candidates->refusalOf(object: $undated, schema: $this->schema(), today: self::TODAY));
	}//end testANominationNotYetDueWaits()

	public function testAnActionDateOfTodayIsDue(): void {
		$record = $this->record(['archiefnominatie' => 'anonymiseren', 'archiefactiedatum' => '2026-11-14T00:00:00+00:00']);

		$this->assertNull($this->candidates->refusalOf(object: $record, schema: $this->schema(), today: self::TODAY));
	}//end testAnActionDateOfTodayIsDue()

	public function testARecordNoLongerLiveIsNotACandidate(): void {
		$record = $this->record(['archiefnominatie' => 'anonymiseren', 'archiefactiedatum' => '2026-11-01', 'archiefstatus' => 'destroyed']);

		$this->assertSame(AnonymisationCandidates::REFUSAL_NOT_LIVE, $this->candidates->refusalOf(object: $record, schema: $this->schema(), today: self::TODAY));
	}//end testARecordNoLongerLiveIsNotACandidate()

	public function testASchemaWithoutAProfileIsNotGuessedAt(): void {
		$record = $this->record(['archiefnominatie' => 'anonymiseren', 'archiefactiedatum' => '2026-11-01']);

		$this->assertSame(AnonymisationCandidates::REFUSAL_NO_PROFILE, $this->candidates->refusalOf(object: $record, schema: $this->schema('none'), today: self::TODAY));
	}//end testASchemaWithoutAProfileIsNotGuessedAt()

	public function testAProfileInTheArchiveBlockIsRead(): void {
		$candidate = $this->candidates->candidateOf(
			object: $this->record(['archiefnominatie' => 'anonymiseren', 'archiefactiedatum' => '2026-11-01']),
			schema: $this->schema('archive'),
			today: self::TODAY
		);

		$this->assertSame(
			[AnonymisationProfile::ANNOTATION_KEY => ['naam' => ['treatment' => AnonymisationProfile::REMOVE]]],
			$candidate['annotation']
		);
	}//end testAProfileInTheArchiveBlockIsRead()

	public function testAHoldAndAnEarlierMarkerAreCarriedForTheRunToRefuse(): void {
		$marker    = ['state' => AnonymisationRun::COMPLETE, 'profile' => 'zaak', 'anonymisedAt' => '2026-10-01'];
		$candidate = $this->candidates->candidateOf(
			object: $this->record(
				[
					'archiefnominatie' => 'anonymiseren', 'archiefactiedatum' => '2026-11-01',
					'legalHold' => ['active' => true, 'reason' => 'WOO-verzoek 2026-14'],
					AnonymisationCandidates::MARKER_KEY => $marker,
				]
			),
			schema: $this->schema(),
			today: self::TODAY
		);

		$this->assertTrue($candidate['hasLegalHold']);
		$this->assertSame('WOO-verzoek 2026-14', $candidate['holdReason']);
		$this->assertSame($marker, $candidate['marker']);
	}//end testAHoldAndAnEarlierMarkerAreCarriedForTheRunToRefuse()

	public function testTheSweepAnonymisesTheCandidateAndRefusesTheHeldOne(): void {
		$due  = $this->candidates->candidateOf(
			object: $this->record(['archiefnominatie' => 'anonymiseren', 'archiefactiedatum' => '2026-11-01']),
			schema: $this->schema(),
			today: self::TODAY
		);
		$held = $this->candidates->candidateOf(
			object: $this->record(['archiefnominatie' => 'anonymiseren', 'archiefactiedatum' => '2026-11-01', 'legalHold' => ['active' => true, 'reason' => 'WOO-verzoek']]),
			schema: $this->schema(),
			today: self::TODAY
		);
		$held['uuid'] = 'obj-2';

		$sweep  = new AnonymisationSweep(run: new AnonymisationRun(), logger: new NullLogger());
		$report = $sweep->sweep(
			batch: new AnonymisationBatch(
				candidates: [$due, $held],
				targetsFor: static fn (): array => [new RecordingTarget(name: 'payload')],
				salt: 'instance-salt'
			)
		);

		$this->assertSame(1, $report['anonymisedCount']);
		$this->assertSame('obj-1', $report['anonymised'][0]['uuid']);
		$this->assertSame(['naam'], $report['anonymised'][0]['marker']['changed']);
		$this->assertSame(1, $report['refusedCount']);
		$this->assertStringContainsString('WOO-verzoek', $report['refused'][0]['reason']);
	}//end testTheSweepAnonymisesTheCandidateAndRefusesTheHeldOne()
}//end class
