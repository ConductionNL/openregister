<?php

/**
 * A match rule whose field neither side holds carries no weight.
 *
 * A schema declares its rules once for every kind of object it stores. A rule
 * on a field one kind never fills (a permit reference on a Woo request) is
 * neither agreement nor disagreement for that pair, so it must not lower the
 * score of the rules that did compare something.
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
 * @spec openspec/specs/duplicate-detection/spec.md#requirement-a-candidate-can-be-checked-against-the-stored-objects-before-it-is-saved
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

class DuplicateAbsentFieldWeightTest extends TestCase {

	/**
	 * Object read path.
	 *
	 * @var ObjectService&MockObject
	 */
	private $objectService;

	/**
	 * Service under test.
	 *
	 * @var DuplicateDetectionService
	 */
	private DuplicateDetectionService $service;

	/**
	 * Build the service over a schema with a requester, a permit reference
	 * and a title rule, as dossiq's case schema declares them.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->objectService = $this->createMock(originalClassName: ObjectService::class);
		$schemaMapper = $this->createMock(originalClassName: SchemaMapper::class);
		$registerMapper = $this->createMock(originalClassName: RegisterMapper::class);
		$dismissals = $this->createMock(originalClassName: DismissedPairStore::class);
		$dismissals->method('activeFor')->willReturn([]);

		$register = new Register();
		$register->setId(1);
		$register->setSlug('reg');
		$register->setSchemas([1]);

		$schema = new Schema();
		$schema->setId(1);
		$schema->setSlug('case');
		$schema->setConfiguration(
			[
				'x-openregister-dedup' => [
					'matchRules' => [
						['field' => 'requester', 'method' => 'exact', 'weight' => 0.35],
						['field' => 'permitApplicationRef', 'method' => 'exact', 'weight' => 0.35],
						['field' => 'title', 'method' => 'normalized', 'weight' => 0.2],
						['field' => 'title', 'method' => 'levenshtein', 'weight' => 0.1],
					],
					'threshold' => 0.1,
				],
			]
		);

		$registerMapper->method('find')->willReturn($register);
		$schemaMapper->method('findInIds')->willReturn($schema);

		$this->service = new DuplicateDetectionService(
			$this->objectService,
			$schemaMapper,
			$registerMapper,
			new SimilarityCalculator(),
			$dismissals,
			$this->createMock(originalClassName: LoggerInterface::class)
		);
	}//end setUp()

	/**
	 * Score a candidate against one stored object.
	 *
	 * @param array<string, mixed> $stored    The stored payload.
	 * @param array<string, mixed> $candidate The candidate payload.
	 *
	 * @return float The score, 0.0 when it is not returned.
	 */
	private function scoreOf(array $stored, array $candidate): float {
		$object = new ObjectEntity();
		$object->setUuid('stored-1');
		$object->setObject($stored);
		$this->objectService->method('findAll')->willReturn([$object]);

		$matches = $this->service->checkCandidate(1, 1, $candidate);

		return (float)($matches[0]['score'] ?? 0.0);
	}//end scoreOf()

	/**
	 * Same requester and the same title, no permit reference on either side:
	 * every rule that compared something agrees, so the pair scores 1.0.
	 *
	 * @return void
	 */
	public function testAFieldNeitherSideHoldsDoesNotLowerTheScore(): void {
		$this->assertSame(
			expected: 1.0,
			actual: $this->scoreOf(
				stored: ['requester' => 'p-1', 'title' => 'Kosten zwembad Sportpark Zuid'],
				candidate: ['requester' => 'p-1', 'title' => 'Kosten zwembad Sportpark Zuid', 'permitApplicationRef' => '']
			)
		);
	}//end testAFieldNeitherSideHoldsDoesNotLowerTheScore()

	/**
	 * A field only one side holds still counts: one has a permit reference
	 * and the other does not, which is a difference.
	 *
	 * @return void
	 */
	public function testAFieldOneSideHoldsStillCounts(): void {
		$this->assertSame(
			expected: 0.65,
			actual: $this->scoreOf(
				stored: ['requester' => 'p-1', 'title' => 'Kosten zwembad', 'permitApplicationRef' => 'OLO-1'],
				candidate: ['requester' => 'p-1', 'title' => 'Kosten zwembad']
			)
		);
	}//end testAFieldOneSideHoldsStillCounts()

	/**
	 * Same requester, an unrelated title, no permit reference: the requester
	 * weighs against the three rules that compared something.
	 *
	 * @return void
	 */
	public function testTheRequesterAloneWeighsAgainstTheRulesThatCompared(): void {
		$score = $this->scoreOf(
			stored: ['requester' => 'p-1', 'title' => 'Openbare verlichting fietspad Lindelaan'],
			candidate: ['requester' => 'p-1', 'title' => 'Kosten zwembad Sportpark Zuid']
		);

		$this->assertGreaterThan(expected: 0.53, actual: $score);
		$this->assertLessThan(expected: 0.6, actual: $score);
	}//end testTheRequesterAloneWeighsAgainstTheRulesThatCompared()

	/**
	 * Two objects that share no field the rules read are not compared at all.
	 *
	 * @return void
	 */
	public function testNothingToCompareIsNoMatch(): void {
		$this->assertSame(expected: 0.0, actual: $this->scoreOf(stored: ['other' => 'x'], candidate: ['other' => 'x']));
	}//end testNothingToCompareIsNoMatch()
}//end class
