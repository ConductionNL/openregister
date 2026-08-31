<?php

/**
 * The priority normalisation table — including the live fleet defect.
 *
 * pipelinq's `task.priority` declares the enum `["low","normal","high"]`
 * with `"default": "normaal"` — a default not in its own enum. A coercing
 * normaliser would have hidden that for as long as it existed; this table
 * pins that `"normaal"` is REFUSED naming itself, alongside the three
 * accepted scales.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Task
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Task;

use OCA\OpenRegister\Exception\TaskValidationException;
use OCA\OpenRegister\Service\Task\TaskPriority;
use PHPUnit\Framework\TestCase;

/**
 * All four fleet scales in, one scale out; off-scale refused.
 *
 * @covers \OCA\OpenRegister\Service\Task\TaskPriority
 */
class TaskPriorityTest extends TestCase {

	/**
	 * Accepted values across the scales.
	 *
	 * @return array<string, array{0: mixed, 1: string}> value, expected.
	 */
	public static function acceptedProvider(): array {
		return [
			// Canonical.
			'low' => ['low', 'low'],
			'normal' => ['normal', 'normal'],
			'high' => ['high', 'high'],
			'urgent' => ['urgent', 'urgent'],
			// Case-insensitive canonical.
			'High capitalised' => ['High', 'high'],
			// Notification scale.
			'medium' => ['medium', 'normal'],
			'critical' => ['critical', 'urgent'],
			// iCal integers: 1 is the wire format's most urgent.
			'ical 0 undefined' => [0, 'normal'],
			'ical 1' => [1, 'urgent'],
			'ical 2' => [2, 'high'],
			'ical 4' => [4, 'high'],
			'ical 5' => [5, 'normal'],
			'ical 6' => [6, 'low'],
			'ical 9' => [9, 'low'],
			// A numeric string reads as its integer.
			'ical numeric string' => ['1', 'urgent'],
		];
	}//end acceptedProvider()

	/**
	 * The scales land where the table says.
	 *
	 * @dataProvider acceptedProvider
	 *
	 * @param mixed $value The incoming value.
	 * @param string $expected The normalised priority.
	 *
	 * @return void
	 */
	public function testAccepted(mixed $value, string $expected): void {
		$this->assertSame($expected, TaskPriority::normalise(value: $value));
	}//end testAccepted()

	/**
	 * Refused values, each named in the message.
	 *
	 * @return array<string, array{0: mixed, 1: string}> value, named fragment.
	 */
	public static function refusedProvider(): array {
		return [
			// THE PIPELINQ DEFECT: a default outside its own enum.
			'normaal' => ['normaal', "'normaal'"],
			'ical out of range' => [10, "'10'"],
			'negative' => [-1, "'-1'"],
			'empty string' => ['', "''"],
			'unknown word' => ['hoog', "'hoog'"],
		];
	}//end refusedProvider()

	/**
	 * Off-scale is refused naming the value, never coerced.
	 *
	 * @dataProvider refusedProvider
	 *
	 * @param mixed $value The refused value.
	 * @param string $named The fragment the message must carry.
	 *
	 * @return void
	 */
	public function testRefusedByName(mixed $value, string $named): void {
		$this->expectException(TaskValidationException::class);
		$this->expectExceptionMessage($named);
		TaskPriority::normalise(value: $value);
	}//end testRefusedByName()
}//end class
