<?php

/**
 * The refusal messages of the related-row filter parser, pinned word for word.
 *
 * The parser's other test asserts that malformed blocks throw; this one pins
 * what each refusal says, so a reshaped helper cannot change the wording a
 * caller reads.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Query
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Query;

use InvalidArgumentException;
use OCA\OpenRegister\Service\Query\RelatedRowFilterParser;
use PHPUnit\Framework\TestCase;

/**
 * Each refusal and each accepted spelling, with its exact outcome.
 *
 * @covers \OCA\OpenRegister\Service\Query\RelatedRowFilterParser
 * @uses \OCA\OpenRegister\Service\Query\RelatedRowFilter
 */
class RelatedRowFilterParserMessagesTest extends TestCase {

	/**
	 * Malformed blocks and the message each one is refused with.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: string}>
	 */
	public static function refusals(): array {
		return [
			'blank foreign key' => [
				['_related' => ['pd' => [' ' => ['x' => '1']]]],
				'_related[pd][ ] must carry at least one condition.',
			],
			'empty block under a foreign key' => [
				['_related' => ['pd' => ['case' => []]]],
				'_related[pd][case] must carry at least one condition.',
			],
			'scalar block under a foreign key' => [
				['_related' => ['pd' => ['case' => 'x']]],
				'_related[pd][case] must carry at least one condition.',
			],
			'forward key, empty block' => [
				['_referenced' => ['pd' => ['case' => []]]],
				'_referenced[pd][case] must carry at least one condition.',
			],
			'field with an empty operator map' => [
				['_related' => ['pd' => ['case' => ['x' => []]]]],
				'The condition _related[pd][case][x] carries no value.',
			],
			'unknown operator' => [
				['_related' => ['pd' => ['case' => ['x' => ['nope' => '1']]]]],
				"The condition _related[pd][case][x] uses operator 'nope'. It must be one of: ",
			],
		];
	}//end refusals()

	/**
	 * Every malformed block is refused with its exact message.
	 *
	 * @param array<string, mixed> $query   The request query.
	 * @param string               $message The message, or its start.
	 *
	 * @dataProvider refusals
	 *
	 * @return void
	 */
	public function testRefusalMessage(array $query, string $message): void {
		$key = array_key_first($query);

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage($message);

		(new RelatedRowFilterParser(key: $key))->parse($query);
	}//end testRefusalMessage()

	/**
	 * The three spellings of a condition, and a scalar `in` split on commas.
	 *
	 * @return void
	 */
	public function testAcceptedSpellings(): void {
		$filters = (new RelatedRowFilterParser())->parse(
			[
				'_related' => [
					'pd' => [
						' case ' => [
							'a' => '1',
							'b' => ['x', 'y'],
							'c' => [' gte ' => '5', 'in' => 'p, q'],
						],
					],
				],
			]
		);

		$this->assertCount(1, $filters);
		$this->assertSame('case', $filters[0]->foreignKey);
		$this->assertSame(
			[
				['field' => 'a', 'operator' => 'eq', 'value' => '1'],
				['field' => 'b', 'operator' => 'in', 'value' => ['x', 'y']],
				['field' => 'c', 'operator' => 'gte', 'value' => '5'],
				['field' => 'c', 'operator' => 'in', 'value' => ['p', 'q']],
			],
			$filters[0]->conditions
		);
	}//end testAcceptedSpellings()
}//end class
