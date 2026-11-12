<?php

/**
 * Every form of `_order` reads as one map of field to direction.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Support;

use OCA\OpenRegister\Support\OrderParameter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Support\OrderParameter
 */
class OrderParameterTest extends TestCase {
	/**
	 * The forms and what they ask for.
	 *
	 * @return array<string, array{0: mixed, 1: array<array-key, string>}>
	 */
	public static function forms(): array {
		return [
			'a map' => [['title' => 'desc', '@self.created' => 'asc'], ['title' => 'DESC', '@self.created' => 'ASC']],
			'a map with an unknown direction' => [['title' => 'up'], ['title' => 'ASC']],
			'a map with a list for a direction' => [['title' => ['desc']], ['title' => 'ASC']],
			'a digit-only field' => [[7 => 'desc'], [7 => 'DESC']],
			'a map as JSON' => ['{"status":"ASC","title":"DESC"}', ['status' => 'ASC', 'title' => 'DESC']],
			'a list of objects as JSON' => ['[{"field":"created","direction":"DESC"}]', ['created' => 'DESC']],
			'a list of objects' => [[['field' => 'a', 'direction' => 'desc'], ['field' => 'b']], ['a' => 'DESC', 'b' => 'ASC']],
			'a list of names' => [['title', 'status:desc'], ['title' => 'ASC', 'status' => 'DESC']],
			'the first of two entries wins' => [['title:desc', 'title'], ['title' => 'DESC']],
			'one name' => ['dueDate', ['dueDate' => 'ASC']],
			'one name with a direction' => ['@self.created:DESC', ['@self.created' => 'DESC']],
			'a colon that is no direction' => ['a:b', ['a:b' => 'ASC']],
			'nothing' => [[], []],
			'an empty string' => ['', []],
			'an empty name' => [[''], []],
			'null' => [null, []],
			'false' => [false, []],
			'a number' => [5, []],
			'JSON that is no list or map' => ['5', []],
			'entries that name nothing' => [[5, null, ['direction' => 'DESC'], ['field' => ''], ['field' => 5]], []],
		];
	}//end forms()

	/**
	 * Each form gives its fields.
	 *
	 * @param mixed                    $order    The value.
	 * @param array<array-key, string> $expected The fields.
	 *
	 * @return void
	 */
	#[DataProvider('forms')]
	public function testEveryFormReadsAsAMap(mixed $order, array $expected): void {
		$this->assertSame($expected, (new OrderParameter(order: $order))->fields());
	}//end testEveryFormReadsAsAMap()
}//end class
