<?php

/**
 * Unit tests for {@see \OCA\OpenRegister\Search\Service\Quality\PayloadComparator}.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Search\Service\Quality
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Search\Service\Quality;

use OCA\OpenRegister\Search\Service\Quality\PayloadComparator;
use OCA\OpenRegister\Search\Service\Quality\SimilarityCalculator;
use PHPUnit\Framework\TestCase;

/**
 * Reading dotted paths out of two payloads, building blocking tokens and
 * fingerprints from them, and the weighted score of a pair.
 *
 * @covers \OCA\OpenRegister\Search\Service\Quality\PayloadComparator
 * @uses   \OCA\OpenRegister\Search\Service\Quality\SimilarityCalculator
 * @uses   \OCA\OpenRegister\Search\Service\Quality\ComparedValues
 */
class PayloadComparatorTest extends TestCase {

	private PayloadComparator $comparator;

	protected function setUp(): void {
		$this->comparator = new PayloadComparator(similarity: new SimilarityCalculator());
	}//end setUp()

	/**
	 * A dotted path walks nested arrays; anything it cannot walk is null.
	 *
	 * @return void
	 */
	public function testValueAtWalksDottedPaths(): void {
		$data = ['name' => 'Acme', 'address' => ['city' => 'Utrecht', 'zip' => null], 'tags' => 'x'];

		$this->assertSame('Utrecht', $this->comparator->valueAt(data: $data, path: 'address.city'));
		$this->assertSame(['city' => 'Utrecht', 'zip' => null], $this->comparator->valueAt(data: $data, path: 'address'));
		$this->assertNull($this->comparator->valueAt(data: $data, path: 'address.zip'));
		$this->assertNull($this->comparator->valueAt(data: $data, path: 'address.street'));
		$this->assertNull($this->comparator->valueAt(data: $data, path: 'tags.first'));
		$this->assertNull($this->comparator->valueAt(data: $data, path: ''));
	}//end testValueAtWalksDottedPaths()

	/**
	 * The blocking token joins each key's normalised value, and is empty when any key has none.
	 *
	 * @return void
	 */
	public function testABlockingTokenNeedsEveryKey(): void {
		$data = ['name' => '  ACME   Corp ', 'address' => ['city' => 'Utrecht'], 'kvk' => 12345678];

		$this->assertSame('acme corp|utrecht|12345678', $this->comparator->blockingToken(data: $data, keys: ['name', 'address.city', 'kvk']));
		$this->assertSame('', $this->comparator->blockingToken(data: $data, keys: ['name', 'address.street']));
		$this->assertSame('', $this->comparator->blockingToken(data: $data, keys: ['address']));
		$this->assertSame('', $this->comparator->blockingToken(data: $data, keys: []));
	}//end testABlockingTokenNeedsEveryKey()

	/**
	 * A fingerprint lists field=token for every rule with a field, sorted, whatever the rule order.
	 *
	 * @return void
	 */
	public function testAFingerprintIsSortedAndSkipsRulesWithoutAField(): void {
		$data  = ['name' => 'Acme Corp', 'email' => 'INFO@acme.nl'];
		$rules = [['field' => 'name'], ['method' => 'exact'], ['field' => 'email'], ['field' => 'phone']];

		$this->assertSame('email=info@acme.nl|name=acme corp|phone=', $this->comparator->fingerprintSide(data: $data, rules: $rules));
		$this->assertSame(
			$this->comparator->fingerprintSide(data: $data, rules: $rules),
			$this->comparator->fingerprintSide(data: $data, rules: array_reverse($rules))
		);
	}//end testAFingerprintIsSortedAndSkipsRulesWithoutAField()

	/**
	 * The score weighs each scorable rule; a rule matches at 0.9 similarity or more.
	 *
	 * @return void
	 */
	public function testTheScoreIsTheWeightedMeanOfTheScorableRules(): void {
		$payloads = [
			['name' => 'Acme', 'city' => 'Utrecht', 'code' => 'abcdefghij', 'note' => '', 'kvk' => '1', 'email' => 'a@x.nl'],
			['name' => 'Acme', 'city' => 'Zwolle', 'code' => 'abcdefghik', 'note' => '  ', 'kvk' => '2', 'email' => 'a@x.nl'],
		];
		$rules    = [
			['field' => 'name', 'method' => 'exact', 'weight' => 2],
			['field' => 'city', 'method' => 'exact', 'weight' => '1'],
			['field' => 'code', 'method' => 'levenshtein', 'weight' => 'heavy'],
			['field' => 'note', 'method' => 'exact', 'weight' => 5],
			['field' => 'kvk', 'method' => 'exact', 'weight' => 0],
			['field' => 'email', 'method' => 'normalized', 'weight' => -1],
		];

		$this->assertSame(
			[
				'score' => 0.725,
				'matchedOn' => ['name', 'code'],
				'matchedRules' => [
					['field' => 'name', 'method' => 'exact', 'similarity' => 1.0],
					['field' => 'code', 'method' => 'levenshtein', 'similarity' => 0.9],
				],
			],
			$this->comparator->score(payloads: $payloads, rules: $rules)
		);
	}//end testTheScoreIsTheWeightedMeanOfTheScorableRules()

	/**
	 * One blank side still scores; a field matched by two rules is named once.
	 *
	 * @return void
	 */
	public function testOneBlankSideScoresAndAFieldIsNamedOnce(): void {
		$payloads = [['name' => 'Acme', 'city' => null], ['name' => 'acme', 'city' => 'Utrecht']];
		$rules    = [
			['field' => 'name', 'method' => 'normalized'],
			['field' => 'name', 'method' => 'levenshtein'],
			['field' => 'city', 'method' => 'exact'],
		];

		$result = $this->comparator->score(payloads: $payloads, rules: $rules);

		$this->assertSame(0.6667, $result['score']);
		$this->assertSame(['name'], $result['matchedOn']);
		$this->assertCount(2, $result['matchedRules']);
	}//end testOneBlankSideScoresAndAFieldIsNamedOnce()

	/**
	 * Without a scorable rule the score is zero and nothing matched.
	 *
	 * @return void
	 */
	public function testNothingScorableScoresZero(): void {
		$this->assertSame(
			['score' => 0.0, 'matchedOn' => [], 'matchedRules' => []],
			$this->comparator->score(payloads: [['tags' => []], ['tags' => []]], rules: [['field' => 'tags', 'method' => 'exact'], ['field' => 'name', 'method' => 'exact']])
		);
	}//end testNothingScorableScoresZero()
}//end class
