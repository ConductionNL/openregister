<?php

/**
 * PaginatedResultsExtrasTest: the declared extra top-level keys of an integration list answer.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Integration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/integration-registry/spec.md#requirement-an-integration-may-declare-extra-top-level-keys-of-its-list-answer
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Integration;

use OCA\OpenRegister\Service\Integration\PaginatedResults;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * PaginatedResults::extras() passes declared keys through and nothing else.
 */
#[CoversClass(PaginatedResults::class)]
class PaginatedResultsExtrasTest extends TestCase {

	/**
	 * The answer the page-layout leaf gives: an envelope with two explanation keys and one internal key.
	 *
	 * @return array<string, mixed>
	 */
	private static function layoutAnswer(): array {
		return [
			'items' => [['id' => 'layout-1']],
			'total' => 1,
			'nextCursor' => null,
			'appliedLayers' => [['id' => 'base'], ['id' => 'override', 'audience' => 'editors']],
			'withheld' => [['id' => 'stale', 'reason' => 'base moved']],
			'internal' => 'not for consumers',
		];
	}//end layoutAnswer()

	public function testDeclaredKeysPassThroughUnchanged(): void {
		$extras = PaginatedResults::extras(value: self::layoutAnswer(), declared: ['appliedLayers', 'withheld']);

		$this->assertSame(
			[
				'appliedLayers' => [['id' => 'base'], ['id' => 'override', 'audience' => 'editors']],
				'withheld' => [['id' => 'stale', 'reason' => 'base moved']],
			],
			$extras
		);
	}//end testDeclaredKeysPassThroughUnchanged()

	public function testUndeclaredKeysAreDropped(): void {
		$extras = PaginatedResults::extras(value: self::layoutAnswer(), declared: ['withheld']);

		$this->assertSame(['withheld'], array_keys($extras));
		$this->assertArrayNotHasKey('internal', $extras);
		$this->assertArrayNotHasKey('appliedLayers', $extras);
	}//end testUndeclaredKeysAreDropped()

	public function testEnvelopeKeysAreNeverPassedAsExtras(): void {
		$extras = PaginatedResults::extras(
			value: self::layoutAnswer() + ['results' => ['spoofed']],
			declared: ['items', 'results', 'total', 'nextCursor', 'withheld']
		);

		$this->assertSame(['withheld'], array_keys($extras));
	}//end testEnvelopeKeysAreNeverPassedAsExtras()

	public function testADeclaredKeyTheAnswerLacksIsNotAdded(): void {
		$this->assertSame([], PaginatedResults::extras(value: ['items' => [], 'total' => 0], declared: ['withheld']));
	}//end testADeclaredKeyTheAnswerLacksIsNotAdded()

	public function testANullValueOfADeclaredKeyIsKept(): void {
		$this->assertSame(
			['withheld' => null],
			PaginatedResults::extras(value: ['items' => [], 'withheld' => null], declared: ['withheld'])
		);
	}//end testANullValueOfADeclaredKeyIsKept()

	public function testAFlatListOfRowsHasNoExtras(): void {
		$this->assertSame([], PaginatedResults::extras(value: [['id' => 'a'], ['id' => 'b']], declared: ['0', 'id']));
	}//end testAFlatListOfRowsHasNoExtras()

	public function testANonArrayValueHasNoExtras(): void {
		$this->assertSame([], PaginatedResults::extras(value: null, declared: ['withheld']));
		$this->assertSame([], PaginatedResults::extras(value: 'answer', declared: ['withheld']));
	}//end testANonArrayValueHasNoExtras()

	public function testNonStringDeclarationsAreIgnored(): void {
		$this->assertSame(
			['withheld' => []],
			PaginatedResults::extras(value: ['items' => [], 'withheld' => [], 5 => 'five'], declared: [5, null, 'withheld'])
		);
	}//end testNonStringDeclarationsAreIgnored()
}//end class
