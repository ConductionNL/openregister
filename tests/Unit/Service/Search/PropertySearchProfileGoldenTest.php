<?php

/**
 * Golden test for PropertySearchProfile.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Search
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

namespace OCA\OpenRegister\Tests\Unit\Service\Search;

use OCA\OpenRegister\Search\Service\Search\PropertySearchProfile;
use PHPUnit\Framework\TestCase;

/**
 * Pins every public answer (declaredBy, declaresMatchType, matchTypeFor,
 * autoDetect, inputControlFor, participatesInFreeText) for a matrix of
 * property definitions. Compared with
 * fixtures/property-search-profile-golden.json; set UPDATE_GOLDEN=1 to
 * rewrite it.
 *
 * @covers \OCA\OpenRegister\Search\Service\Search\PropertySearchProfile
 * @uses   \OCA\OpenRegister\Service\Rbac\ExplicitReveal
 */
class PropertySearchProfileGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/property-search-profile-golden.json';

	/**
	 * Every property definition the matrix is built from.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function properties(): array {
		$types = [];
		foreach (['string', 'STRING', 'integer', 'Number', 'boolean', 'array', 'object', 'null', ''] as $type) {
			$types['type ' . $type] = ['type' => $type];
		}

		return $types + [
			'no type' => [],
			'date format' => ['type' => 'string', 'format' => 'date'],
			'date-time upper' => ['type' => 'string', 'format' => 'DATE-TIME'],
			'datetime on integer' => ['type' => 'integer', 'format' => 'datetime'],
			'time format' => ['format' => 'time'],
			'email format' => ['type' => 'string', 'format' => 'email'],
			'enum string' => ['type' => 'string', 'enum' => ['a', 'b']],
			'enum not array' => ['type' => 'string', 'enum' => 'a'],
			'enum on boolean' => ['type' => 'boolean', 'enum' => [true]],
			'enum on array' => ['type' => 'array', 'enum' => ['x']],
			'enum on object' => ['type' => 'object', 'enum' => ['x']],
			'enum null' => ['type' => 'object', 'enum' => null],
			'declared exact' => ['type' => 'string', 'matchType' => 'exact'],
			'declared prefix padded' => ['type' => 'string', 'matchType' => '  Prefix '],
			'declared range on string' => ['type' => 'string', 'matchType' => 'range'],
			'declared range on date' => ['type' => 'string', 'format' => 'date', 'matchType' => 'RANGE'],
			'declared range on enum' => ['type' => 'string', 'enum' => ['a'], 'matchType' => 'range'],
			'declared fuzzy' => ['type' => 'integer', 'matchType' => 'fuzzy'],
			'declared fulltext on boolean' => ['type' => 'boolean', 'matchType' => 'fulltext'],
			'declared unknown' => ['type' => 'integer', 'matchType' => 'soundex'],
			'declared blank' => ['type' => 'string', 'matchType' => '   '],
			'declared not string' => ['type' => 'string', 'matchType' => 5],
			'control select' => ['type' => 'string', 'inputControl' => 'select'],
			'control padded upper' => ['type' => 'integer', 'inputControl' => ' Date-Range '],
			'control unknown' => ['type' => 'integer', 'inputControl' => 'slider'],
			'control not string' => ['type' => 'boolean', 'inputControl' => ['boolean']],
			'control unknown on date' => ['type' => 'string', 'format' => 'date', 'inputControl' => 'calendar'],
			'control unknown on enum' => ['type' => 'string', 'enum' => ['a'], 'inputControl' => 'radio'],
			'encrypted string' => ['type' => 'string', 'x-openregister-encrypted' => true],
			'encrypted truthy string' => ['type' => 'string', 'x-openregister-encrypted' => 'yes'],
			'encrypted with declared exact' => ['type' => 'string', 'matchType' => 'exact', 'x-openregister-encrypted' => true],
		];
	}//end properties()

	public function testAnswersMatchGolden(): void {
		$profile = new PropertySearchProfile();
		$actual  = [];
		foreach (self::properties() as $name => $property) {
			$actual[$name] = [
				'declaredBy' => $profile->declaredBy($property),
				'declaresMatchType' => PropertySearchProfile::declaresMatchType($property),
				'matchTypeFor' => PropertySearchProfile::matchTypeFor($property),
				'autoDetect' => PropertySearchProfile::autoDetect($property),
				'inputControlFor' => PropertySearchProfile::inputControlFor($property),
				'participatesInFreeText' => PropertySearchProfile::participatesInFreeText($property),
			];
		}

		if (getenv('UPDATE_GOLDEN') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
		}

		$expected = json_decode((string) file_get_contents(self::FIXTURE), true);
		$this->assertSame(array_keys($expected), array_keys($actual));
		foreach ($expected as $name => $answers) {
			$this->assertSame($answers, $actual[$name], $name);
		}
	}//end testAnswersMatchGolden()
}//end class
