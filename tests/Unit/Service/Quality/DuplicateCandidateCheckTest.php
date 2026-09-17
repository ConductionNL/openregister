<?php

/**
 * Candidate-versus-stored duplicate check.
 *
 * Covers the half `DuplicateDetectionService` did not have: scoring an
 * UNSAVED body against what is stored. The parity test is the important one —
 * a warning shown at intake and a duplicate found by a later sweep have to
 * agree on what a duplicate is, and this asserts they do by scoring the same
 * two payloads through both entry points and comparing the numbers.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Quality
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/dedup-check-before-create/specs/duplicate-detection/spec.md#requirement-a-candidate-can-be-checked-against-the-stored-objects-before-it-is-saved
 */

declare(strict_types=1);

namespace Unit\Service\Quality;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\Quality\DismissedPairStore;
use OCA\OpenRegister\Service\Quality\DuplicateDetectionService;
use OCA\OpenRegister\Service\Quality\SimilarityCalculator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class DuplicateCandidateCheckTest extends TestCase {

	/**
	 * Object read path.
	 *
	 * @var ObjectService&MockObject
	 */
	private $objectService;

	/**
	 * Schema lookup.
	 *
	 * @var SchemaMapper&MockObject
	 */
	private $schemaMapper;

	/**
	 * Register lookup — the boundary the schema resolves inside.
	 *
	 * @var RegisterMapper&MockObject
	 */
	private $registerMapper;

	/**
	 * Service under test.
	 *
	 * @var DuplicateDetectionService
	 */
	/**
	 * Dismissed pairs. Answers "none" throughout this class, so every pair the
	 * scorer finds is offered: the exclusion itself is asserted in
	 * DuplicateDismissalExclusionTest, where both sides can be controlled.
	 *
	 * @var DismissedPairStore&MockObject
	 */
	private $dismissals;

	private DuplicateDetectionService $service;

	/**
	 * Build the service over mocked mappers and a real similarity calculator.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->objectService = $this->createMock(ObjectService::class);
		$this->schemaMapper = $this->createMock(SchemaMapper::class);
		$this->registerMapper = $this->createMock(RegisterMapper::class);
		$this->dismissals = $this->createMock(DismissedPairStore::class);
		$this->dismissals->method('activeFor')->willReturn([]);
		$this->service = new DuplicateDetectionService(
			$this->objectService,
			$this->schemaMapper,
			$this->registerMapper,
			new SimilarityCalculator(),
			$this->dismissals,
			$this->createMock(LoggerInterface::class)
		);
	}//end setUp()

	/**
	 * Stub the register-scoped schema resolution the annotation lookup uses.
	 *
	 * @param array<string, mixed> $configuration The schema configuration to answer with.
	 *
	 * @return void
	 */
	private function stubSchema(array $configuration): void {
		$register = new Register();
		$register->setId(1);
		$register->setSlug('reg');
		$register->setSchemas([1]);

		$schema = new Schema();
		$schema->setId(1);
		$schema->setSlug('party');
		$schema->setConfiguration($configuration);

		$this->registerMapper->method('find')->willReturn($register);
		$this->schemaMapper->method('findInIds')->willReturn($schema);
	}//end stubSchema()

	/**
	 * Build a stored object.
	 *
	 * @param string $uuid Object uuid.
	 * @param array<string, mixed> $payload Object payload.
	 *
	 * @return ObjectEntity
	 */
	private function storedObject(string $uuid, array $payload): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid($uuid);
		$object->setObject($payload);

		return $object;
	}//end storedObject()

	/**
	 * The two match rules used throughout, weighted evenly.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function rules(): array {
		return [
			['field' => 'requester', 'method' => 'exact', 'weight' => 0.5],
			['field' => 'subject', 'method' => 'normalized', 'weight' => 0.5],
		];
	}//end rules()

	/**
	 * An intake form is warned: a candidate matching a stored object on both
	 * declared fields comes back with a strong score and both fields named.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/dedup-check-before-create/specs/duplicate-detection/spec.md#requirement-a-candidate-can-be-checked-against-the-stored-objects-before-it-is-saved
	 */
	public function testCandidateMatchingBothFieldsIsReturnedWithTheFieldsNamed(): void {
		$this->stubSchema(['x-openregister-dedup' => ['matchRules' => $this->rules(), 'threshold' => 0.85]]);
		$this->objectService->method('findAll')->willReturn(
			[
				$this->storedObject('stored-1', ['requester' => 'bsn:123', 'subject' => 'Kapvergunning  Eik']),
				$this->storedObject('stored-2', ['requester' => 'bsn:999', 'subject' => 'Iets anders']),
			]
		);

		$matches = $this->service->checkCandidate(
			1,
			1,
			['requester' => 'bsn:123', 'subject' => 'kapvergunning eik']
		);

		$this->assertCount(1, $matches);
		$this->assertSame('stored-1', $matches[0]['uuid']);
		$this->assertSame(1.0, $matches[0]['score']);
		$this->assertContains('requester', $matches[0]['matchedOn']);
		$this->assertContains('subject', $matches[0]['matchedOn']);
		$this->assertSame(
			['requester', 'subject'],
			array_column($matches[0]['matchedRules'], 'field')
		);
		$this->assertSame('normalized', $matches[0]['matchedRules'][1]['method']);
	}//end testCandidateMatchingBothFieldsIsReturnedWithTheFieldsNamed()

	/**
	 * The check and the sweep score the same two payloads identically.
	 *
	 * This is the parity the design asks for. It is asserted by scoring the
	 * SAME pair of payloads through both entry points — once as two stored
	 * objects, once as a candidate against one stored object — and comparing,
	 * so a change to either scorer that does not change the other fails here.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/dedup-check-before-create/specs/duplicate-detection/spec.md#requirement-a-candidate-can-be-checked-against-the-stored-objects-before-it-is-saved
	 */
	public function testCheckAndSweepAgreeOnTheScore(): void {
		$this->stubSchema(['x-openregister-dedup' => ['matchRules' => $this->rules(), 'threshold' => 0.5]]);

		$left = ['requester' => 'bsn:123', 'subject' => 'Kapvergunning Eikenlaan 4'];
		$right = ['requester' => 'bsn:123', 'subject' => 'Kapvergunning Eikenlaan 14'];

		$this->objectService->method('findAll')->willReturn(
			[
				$this->storedObject('a', $left),
				$this->storedObject('b', $right),
			]
		);

		$pairs = $this->service->findDuplicates(1, 1);
		$matches = $this->service->checkCandidate(1, 1, $left);

		$this->assertCount(1, $pairs);
		// Two stored objects pair with each other; the candidate IS `a`, so it
		// matches itself at 1.0 and `b` at the pair score.
		$scoreAgainstB = null;
		foreach ($matches as $match) {
			if ($match['uuid'] === 'b') {
				$scoreAgainstB = $match['score'];
			}
		}

		$this->assertNotNull($scoreAgainstB, 'the candidate should have been scored against b');
		$this->assertSame($pairs[0]['score'], $scoreAgainstB);
	}//end testCheckAndSweepAgreeOnTheScore()

	/**
	 * A candidate below the threshold is not a match.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/dedup-check-before-create/specs/duplicate-detection/spec.md#requirement-a-candidate-can-be-checked-against-the-stored-objects-before-it-is-saved
	 */
	public function testCandidateBelowTheThresholdIsNotReturned(): void {
		$this->stubSchema(['x-openregister-dedup' => ['matchRules' => $this->rules(), 'threshold' => 0.85]]);
		$this->objectService->method('findAll')->willReturn(
			[$this->storedObject('stored-1', ['requester' => 'bsn:999', 'subject' => 'Iets heel anders'])]
		);

		$this->assertSame(
			[],
			$this->service->checkCandidate(1, 1, ['requester' => 'bsn:123', 'subject' => 'Kapvergunning'])
		);
	}//end testCandidateBelowTheThresholdIsNotReturned()

	/**
	 * Blocking narrows the comparison, and it does so on the NORMALISED token,
	 * not the stored value.
	 *
	 * The stored object in the candidate's bucket spells its blocking value in
	 * different case, with padding the form left in. A check that pushed the
	 * candidate's raw value down as an object filter would miss it; blocking
	 * on the normalised token finds it, and still excludes the object in the
	 * other bucket.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/dedup-check-before-create/specs/duplicate-detection/spec.md#requirement-a-candidate-can-be-checked-against-the-stored-objects-before-it-is-saved
	 */
	public function testBlockingIsEvaluatedOnTheNormalisedToken(): void {
		$this->stubSchema(
			[
				'x-openregister-dedup' => [
					'blockingKeys' => ['postalCode'],
					'matchRules' => $this->rules(),
					'threshold' => 0.85,
				],
			]
		);

		$this->objectService->method('findAll')->willReturn(
			[
				$this->storedObject(
					'same-bucket',
					['postalCode' => '1234 AB', 'requester' => 'bsn:123', 'subject' => 'Kapvergunning']
				),
				$this->storedObject(
					'other-bucket',
					['postalCode' => '9999ZZ', 'requester' => 'bsn:123', 'subject' => 'Kapvergunning']
				),
			]
		);

		$matches = $this->service->checkCandidate(
			1,
			1,
			['postalCode' => '  1234 ab ', 'requester' => 'bsn:123', 'subject' => 'Kapvergunning']
		);

		$this->assertSame(['same-bucket'], array_column($matches, 'uuid'));
	}//end testBlockingIsEvaluatedOnTheNormalisedToken()

	/**
	 * A candidate that leaves a blocking field empty belongs to no bucket, so
	 * it has no matches — and, importantly, the register is never read for it.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/dedup-check-before-create/specs/duplicate-detection/spec.md#requirement-a-candidate-can-be-checked-against-the-stored-objects-before-it-is-saved
	 */
	public function testCandidateWithoutABlockingValueMatchesNothingAndReadsNothing(): void {
		$this->stubSchema(
			[
				'x-openregister-dedup' => [
					'blockingKeys' => ['postalCode'],
					'matchRules' => $this->rules(),
				],
			]
		);

		$this->objectService->expects($this->never())->method('findAll');

		$this->assertSame([], $this->service->checkCandidate(1, 1, ['requester' => 'bsn:123']));
	}//end testCandidateWithoutABlockingValueMatchesNothingAndReadsNothing()

	/**
	 * A schema declaring no usable rules answers nothing rather than guessing.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/dedup-check-before-create/specs/duplicate-detection/spec.md#requirement-a-candidate-can-be-checked-against-the-stored-objects-before-it-is-saved
	 */
	public function testSchemaWithoutRulesReturnsNoMatches(): void {
		$this->stubSchema([]);
		$this->objectService->expects($this->never())->method('findAll');

		$this->assertSame([], $this->service->checkCandidate(1, 1, ['requester' => 'bsn:123']));
	}//end testSchemaWithoutRulesReturnsNoMatches()

	/**
	 * The effective threshold is the schema's, and a caller override wins.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/dedup-check-before-create/specs/duplicate-detection/spec.md#requirement-a-schema-declares-what-a-strong-match-does-at-create
	 */
	public function testEffectiveThresholdPrefersTheCallerThenTheSchema(): void {
		$this->stubSchema(['x-openregister-dedup' => ['matchRules' => $this->rules(), 'threshold' => 0.7]]);

		$this->assertSame(0.7, $this->service->effectiveThreshold(1, 1));
		$this->assertSame(0.95, $this->service->effectiveThreshold(1, 1, 0.95));
	}//end testEffectiveThresholdPrefersTheCallerThenTheSchema()

	/**
	 * The annotation reader hands back the declaration, so the create policy
	 * and the scorer read one source.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/dedup-check-before-create/specs/duplicate-detection/spec.md#requirement-a-schema-declares-what-a-strong-match-does-at-create
	 */
	public function testDedupAnnotationReturnsTheDeclaration(): void {
		$this->stubSchema(
			[
				'x-openregister-dedup' => [
					'matchRules' => $this->rules(),
					'onCreate' => 'block',
					'overrideGroups' => ['case-supervisors'],
				],
			]
		);

		$annotation = $this->service->dedupAnnotation(1, 1);

		$this->assertSame('block', $annotation['onCreate']);
		$this->assertSame(['case-supervisors'], $annotation['overrideGroups']);
	}//end testDedupAnnotationReturnsTheDeclaration()
}//end class
