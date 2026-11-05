<?php

/**
 * Golden test for Organisation::jsonSerialize().
 *
 * Serialises a default organisation, one with every property null and one
 * with every property set, and compares the exact output, key order
 * included. Re-record with UPDATE_GOLDEN=1.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Db
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://www.OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Db;

use DateTime;
use OCA\OpenRegister\Db\Organisation;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Db\Organisation
 */
class OrganisationJsonGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/organisation-json-golden.json';

	public function testJsonSerializeMatchesGolden(): void {
		$actual = [
			'defaults' => (new Organisation())->jsonSerialize(),
			'all null' => $this->filled(sample: false)->jsonSerialize(),
			'all set' => $this->filled(sample: true)->jsonSerialize(),
		];

		if (getenv('UPDATE_GOLDEN') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
			$this->addToAssertionCount(1);
			return;
		}

		$expected = json_decode((string) file_get_contents(self::FIXTURE), true);
		$this->assertSame($expected, json_decode((string) json_encode($actual), true));
		$this->assertSame(array_keys($expected['all set']), array_keys($actual['all set']));
	}//end testJsonSerializeMatchesGolden()

	private function filled(bool $sample): Organisation {
		$organisation = new Organisation();
		foreach ((new \ReflectionClass(Organisation::class))->getProperties() as $property) {
			$type = $property->getType();
			if ($property->isStatic() === true || $type === null || $type->allowsNull() === false) {
				continue;
			}

			$value = null;
			if ($sample === true) {
				$value = match ($type->getName()) {
					'string' => 'v-' . $property->getName(),
					'int' => strlen($property->getName()),
					'bool' => false,
					'array' => array_fill_keys(range(1, (strlen($property->getName()) % 3) + 1), $property->getName()),
					'DateTime' => new DateTime('2026-01-02T03:04:05+00:00'),
					default => null,
				};
			}

			$property->setAccessible(true);
			$property->setValue($organisation, $value);
		}

		return $organisation;
	}//end filled()
}//end class
