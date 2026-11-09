<?php

/**
 * Behaviour tests for ExampleSetCastShape: how a set's cast is keyed and what its
 * requirements may say.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Service\ExampleSet
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\ExampleSet;


use OCA\OpenRegister\Service\ExampleSet\ExampleSetCastShape;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetException;
use PHPUnit\Framework\TestCase;

/**
 * Behaviour of the cast and requirements shape of a set's OpenRegister file.
 */
class ExampleSetCastShapeBehaviourTest extends TestCase {

	private static function shape(): ExampleSetCastShape {
		return new ExampleSetCastShape(file: 'openregister/demo.json', set: 'demo');
	}//end shape()

	public function testCastEntriesAreKeyedAndGetAContactsUidExceptGroups(): void {
		$keyed = self::shape()->cast(
			[
				'people' => [['key' => 'sanne'], ['key' => 'esra', 'contactsUid' => 'own-uid']],
				'organisations' => [['key' => 7]],
				'groups' => [['key' => 'staff']],
			]
		);

		$this->assertSame('example-set-demo-sanne', $keyed['people']['sanne']['contactsUid']);
		$this->assertSame('own-uid', $keyed['people']['esra']['contactsUid']);
		$this->assertSame('example-set-demo-7', $keyed['organisations'][7]['contactsUid']);
		$this->assertSame(['key' => 'staff'], $keyed['groups']['staff']);
		$this->assertSame(['sanne', 'esra', 7, 'staff'], array_keys($keyed['entries']));
	}

	public function testAnEmptyCastIsEmpty(): void {
		$this->assertSame(['people' => [], 'organisations' => [], 'groups' => [], 'entries' => []], self::shape()->cast([]));
	}

	/**
	 * @return array<string, array{array<string, mixed>, string}>
	 */
	public static function badCasts(): array {
		return [
			'scalar entry' => [['people' => ['sanne']], '""'],
			'no key' => [['people' => [['uid' => 'x']]], '""'],
			'array key' => [['groups' => [['key' => ['a']]]], '""'],
			'key used twice across parts' => [['people' => [['key' => 'x']], 'groups' => [['key' => 'x']]], '"x" in groups'],
		];
	}//end badCasts()

	/**
	 * @dataProvider badCasts
	 */
	public function testEveryCastEntryNeedsAKeyOfItsOwn(array $cast, string $fragment): void {
		$this->expectException(ExampleSetException::class);
		$this->expectExceptionMessageMatches('/^openregister\/demo\.json: every cast entry needs a key of its own; '.preg_quote($fragment, '/').'/');

		self::shape()->cast($cast);
	}

	public function testRequiresAcceptsSlugsAndObjects(): void {
		$this->assertSame(
			['zaken' => ['mock' => false], 'brp' => ['mock' => true], 'kvk' => ['mock' => false]],
			self::shape()->requires(['zaken', ['register' => 'brp', 'mock' => true], ['register' => 'kvk']])
		);
	}

	/**
	 * @return array<string, array{mixed}>
	 */
	public static function badRequirements(): array {
		return [
			'empty slug' => [''],
			'number slug' => [5],
			'missing register' => [['mock' => true]],
			'mock not boolean' => [['register' => 'brp', 'mock' => 'yes']],
		];
	}//end badRequirements()

	/**
	 * @dataProvider badRequirements
	 */
	public function testARequirementNeedsASlugAndABooleanMock(mixed $entry): void {
		$this->expectException(ExampleSetException::class);
		$this->expectExceptionMessage('openregister/demo.json: requires 0 needs a register slug, or {"register": "<slug>", "mock": true}.');

		self::shape()->requires([$entry]);
	}
}//end class
