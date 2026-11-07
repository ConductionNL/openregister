<?php

/**
 * Golden test for UniqueHintAnnotationValidator.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Search\Service\Quality
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Search\Service\Quality;

use OCA\OpenRegister\Search\Service\Quality\UniqueHintAnnotationValidator;
use PHPUnit\Framework\TestCase;

/**
 * Pins validate() (codes, properties, messages, order) and nominated() for a
 * set of schema shapes. Compared with fixtures/unique-hint-golden.json; set
 * UPDATE_GOLDEN=1 to rewrite it.
 *
 * @covers \OCA\OpenRegister\Search\Service\Quality\UniqueHintAnnotationValidator
 */
class UniqueHintAnnotationGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/unique-hint-golden.json';

	/**
	 * Case name => schema (also used as the configuration for nominated()).
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function cases(): array {
		$props = ['properties' => ['email' => [], 'kvk' => [], '12' => [], '' => []]];
		return [
			'no annotation' => $props,
			'annotation null' => $props + ['x-openregister-unique-hint' => null],
			'annotation string' => $props + ['x-openregister-unique-hint' => 'email'],
			'annotation false' => $props + ['x-openregister-unique-hint' => false],
			'annotation empty' => $props + ['x-openregister-unique-hint' => []],
			'all declared' => $props + ['x-openregister-unique-hint' => ['email', 'kvk']],
			'mixed entries' => $props + ['x-openregister-unique-hint' => ['email', '', 'phone', 7, null, 'kvk', 'email', 'phone', ['x'], '12', true]],
			'assoc keys' => $props + ['x-openregister-unique-hint' => ['a' => 'email', 'b' => 5, 'c' => 'nope', '3' => '']],
			'properties missing' => ['x-openregister-unique-hint' => ['email', 3]],
			'properties not array' => ['properties' => 'x', 'x-openregister-unique-hint' => ['email']],
			'properties list' => ['properties' => ['email'], 'x-openregister-unique-hint' => ['email', '0']],
		];
	}//end cases()

	public function testValidateAndNominatedMatchGolden(): void {
		$validator = new UniqueHintAnnotationValidator();
		$actual    = [];
		foreach (self::cases() as $name => $schema) {
			$actual[$name] = ['validate' => $validator->validate($schema), 'nominated' => $validator->nominated($schema)];
		}

		if (getenv('UPDATE_GOLDEN') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
		}

		$expected = json_decode((string) file_get_contents(self::FIXTURE), true);
		$this->assertSame(array_keys($expected), array_keys($actual));
		foreach ($expected as $name => $outcome) {
			$this->assertSame($outcome, $actual[$name], $name);
		}
	}//end testValidateAndNominatedMatchGolden()
}//end class
