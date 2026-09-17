<?php

/**
 * A dismissed pair stops being offered, and comes back when the data moves.
 *
 * The control matters more than the assertion here: the same two objects are
 * scored with no dismissal first, so "the pair is absent" is a consequence of
 * the dismissal and not of a fixture that never paired in the first place.
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
 * @spec openspec/changes/duplicate-merge-and-dismissed-pairs/specs/duplicate-detection/spec.md#requirement-a-reviewed-pair-is-recorded-as-not-a-duplicate-and-stops-being-offered-req-dmd-003
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

class DuplicateDismissalExclusionTest extends TestCase {

	/**
	 * Object read path.
	 *
	 * @var ObjectService&MockObject
	 */
	private $objectService;

	/**
	 * Dismissed pairs.
	 *
	 * @var DismissedPairStore&MockObject
	 */
	private $dismissals;

	/**
	 * Service under test.
	 *
	 * @var DuplicateDetectionService
	 */
	private DuplicateDetectionService $service;

	/**
	 * The match rules both sides of every assertion share.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $rules = [
		// Weighted so the e-mail carries the match on its own. That is what
		// lets a fixture change the NAME, which moves the fingerprint, while
		// the pair still scores above the threshold: without it, "the pair is
		// gone" after an edit would be ambiguous between "the dismissal held"
		// and "the two simply stopped looking alike", and the expiry could not
		// be tested at all.
		['field' => 'email', 'method' => 'exact', 'weight' => 0.9],
		['field' => 'name', 'method' => 'normalized', 'weight' => 0.1],
	];

	/**
	 * Wire the service over mocked collaborators and a real calculator.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->objectService = $this->createMock(ObjectService::class);
		$schemaMapper = $this->createMock(SchemaMapper::class);
		$registerMapper = $this->createMock(RegisterMapper::class);
		$this->dismissals = $this->createMock(DismissedPairStore::class);

		$register = new Register();
		$register->setId(1);
		$register->setSlug('reg');
		$register->setSchemas([1]);

		$schema = new Schema();
		$schema->setId(1);
		$schema->setSlug('party');
		$schema->setConfiguration(
			['x-openregister-dedup' => ['matchRules' => $this->rules, 'threshold' => 0.85]]
		);

		$registerMapper->method('find')->willReturn($register);
		$schemaMapper->method('findInIds')->willReturn($schema);

		$this->service = new DuplicateDetectionService(
			$this->objectService,
			$schemaMapper,
			$registerMapper,
			new SimilarityCalculator(),
			$this->dismissals,
			$this->createMock(LoggerInterface::class)
		);
	}//end setUp()

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
	 * The two objects the scorer pairs.
	 *
	 * @param string $nameB The second object's name, so it can be moved.
	 *
	 * @return array<int, ObjectEntity>
	 */
	private function pair(string $nameB = 'ACME  bv'): array {
		return [
			$this->storedObject('aaa', ['name' => 'Acme BV', 'email' => 'info@acme.test']),
			$this->storedObject('bbb', ['name' => $nameB, 'email' => 'info@acme.test']),
		];
	}//end pair()

	/**
	 * CONTROL: with no dismissal the pair is offered. Every assertion below
	 * means nothing without this one.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/duplicate-merge-and-dismissed-pairs/specs/duplicate-detection/spec.md#requirement-a-reviewed-pair-is-recorded-as-not-a-duplicate-and-stops-being-offered-req-dmd-003
	 */
	public function testWithoutADismissalThePairIsOffered(): void {
		$this->objectService->method('findAll')->willReturn($this->pair());
		$this->dismissals->method('activeFor')->willReturn([]);

		$pairs = $this->service->findDuplicates(1, 1);

		$this->assertCount(1, $pairs);
	}//end testWithoutADismissalThePairIsOffered()

	/**
	 * The same false pair is not offered twice.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/duplicate-merge-and-dismissed-pairs/specs/duplicate-detection/spec.md#requirement-a-reviewed-pair-is-recorded-as-not-a-duplicate-and-stops-being-offered-req-dmd-003
	 */
	public function testADismissedPairIsNotOffered(): void {
		$objects = $this->pair();
		$this->objectService->method('findAll')->willReturn($objects);

		$fingerprint = $this->service->pairFingerprint(
			dataA: $objects[0]->getObject(),
			dataB: $objects[1]->getObject(),
			rules: $this->rules
		);

		$this->dismissals->method('activeFor')->willReturn(
			[DismissedPairStore::key('aaa', 'bbb') => ['fingerprint' => $fingerprint]]
		);

		$this->assertSame([], $this->service->findDuplicates(1, 1));
	}//end testADismissedPairIsNotOffered()

	/**
	 * A changed record is a new question, so the pair is offered again.
	 *
	 * The dismissal row is byte-identical to the one above; only a compared
	 * value moved. That is what separates "this dismissal expired" from "the
	 * exclusion never worked".
	 *
	 * @return void
	 *
	 * @spec openspec/changes/duplicate-merge-and-dismissed-pairs/specs/duplicate-detection/spec.md#requirement-a-reviewed-pair-is-recorded-as-not-a-duplicate-and-stops-being-offered-req-dmd-003
	 */
	public function testAChangedRecordIsOfferedAgain(): void {
		$original = $this->pair();
		$staleFingerprint = $this->service->pairFingerprint(
			dataA: $original[0]->getObject(),
			dataB: $original[1]->getObject(),
			rules: $this->rules
		);

		// The second record is renamed. The pair still scores 0.9 on the
		// e-mail alone, so it is still a candidate; what changed is the
		// fingerprint the dismissal was made against.
		$moved = $this->pair(nameB: 'Globex Holding');
		$this->objectService->method('findAll')->willReturn($moved);
		$this->dismissals->method('activeFor')->willReturn(
			[DismissedPairStore::key('aaa', 'bbb') => ['fingerprint' => $staleFingerprint]]
		);

		$this->assertCount(1, $this->service->findDuplicates(1, 1));
	}//end testAChangedRecordIsOfferedAgain()

	/**
	 * A dismissal of some other pair does not hide this one.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/duplicate-merge-and-dismissed-pairs/specs/duplicate-detection/spec.md#requirement-a-reviewed-pair-is-recorded-as-not-a-duplicate-and-stops-being-offered-req-dmd-003
	 */
	public function testADismissalOfAnotherPairDoesNotHideThisOne(): void {
		$this->objectService->method('findAll')->willReturn($this->pair());
		$this->dismissals->method('activeFor')->willReturn(
			[DismissedPairStore::key('ccc', 'ddd') => ['fingerprint' => 'whatever']]
		);

		$this->assertCount(1, $this->service->findDuplicates(1, 1));
	}//end testADismissalOfAnotherPairDoesNotHideThisOne()

	/**
	 * The fingerprint does not depend on which way round the pair is read.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/duplicate-merge-and-dismissed-pairs/specs/duplicate-detection/spec.md#requirement-a-reviewed-pair-is-recorded-as-not-a-duplicate-and-stops-being-offered-req-dmd-003
	 */
	public function testTheFingerprintIsOrderIndependent(): void {
		$objects = $this->pair();
		$a = $objects[0]->getObject();
		$b = $objects[1]->getObject();

		$this->assertSame(
			$this->service->pairFingerprint(dataA: $a, dataB: $b, rules: $this->rules),
			$this->service->pairFingerprint(dataA: $b, dataB: $a, rules: $this->rules)
		);
	}//end testTheFingerprintIsOrderIndependent()

	/**
	 * The fingerprint is built from the NORMALISED values, so re-saving a
	 * record with different spacing does not resurrect a dismissal.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/duplicate-merge-and-dismissed-pairs/specs/duplicate-detection/spec.md#requirement-a-reviewed-pair-is-recorded-as-not-a-duplicate-and-stops-being-offered-req-dmd-003
	 */
	public function testTheFingerprintIgnoresCasingAndSpacing(): void {
		$rules = $this->rules;

		$this->assertSame(
			$this->service->pairFingerprint(
				dataA: ['name' => 'Acme BV', 'email' => 'info@acme.test'],
				dataB: ['name' => 'Globex', 'email' => 'hi@globex.test'],
				rules: $rules
			),
			$this->service->pairFingerprint(
				dataA: ['name' => '  acme   bv ', 'email' => 'info@acme.test'],
				dataB: ['name' => 'globex', 'email' => 'hi@globex.test'],
				rules: $rules
			)
		);
	}//end testTheFingerprintIgnoresCasingAndSpacing()

	/**
	 * The effective rules are the schema's, so a caller dismissing a pair
	 * fingerprints exactly what the scorer compared.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/duplicate-merge-and-dismissed-pairs/specs/duplicate-detection/spec.md#requirement-a-reviewed-pair-is-recorded-as-not-a-duplicate-and-stops-being-offered-req-dmd-003
	 */
	public function testEffectiveRulesAreTheSchemasRules(): void {
		$this->assertSame(
			['email', 'name'],
			array_column($this->service->effectiveRules(1, 1), 'field')
		);
	}//end testEffectiveRulesAreTheSchemasRules()
}//end class
