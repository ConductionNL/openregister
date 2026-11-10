<?php

/**
 * SchemaService property discovery golden test
 *
 * Pins what the property discovery helpers return for a fixed set of inputs:
 * the detected string format and string patterns per string, the analysis of
 * single values of every type, numeric range merging, and the full
 * analyzeObjectProperties() result over a set of object rows. The expected
 * output is the var_export of the result on the code before the refactor
 * (fixtures/schema-discovery/golden.txt); PHP_INT_MAX is written as a marker
 * so the golden does not depend on the platform's integer size.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\SchemaService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Golden test for the SchemaService property discovery.
 */
class SchemaServiceDiscoveryGoldenTest extends TestCase {

	/**
	 * Run every discovery helper over the fixture inputs.
	 *
	 * @return array<string, mixed> The results, keyed like the golden.
	 */
	public static function discover(): array {
		$class   = new ReflectionClass(SchemaService::class);
		$service = $class->newInstanceWithoutConstructor();
		$call    = static function (string $method, array $args) use ($class, $service) {
			return $class->getMethod($method)->invokeArgs($service, $args);
		};
		$input   = require __DIR__.'/fixtures/schema-discovery/input.php';
		$out     = ['formats' => [], 'patterns' => [], 'values' => [], 'ranges' => [], 'objects' => null];
		foreach ($input['strings'] as $string) {
			$out['formats'][$string]  = $call('detectStringFormat', [$string]);
			$out['patterns'][$string] = $call('analyzeStringPattern', [$string]);
		}

		foreach ($input['values'] as $key => $value) {
			$out['values'][$key] = $call('analyzePropertyValue', [$value]);
		}

		foreach ($input['ranges'] as $key => [$existing, $new]) {
			$out['ranges'][$key] = $call('mergeNumericRanges', [$existing, $new]);
		}

		$objects = [];
		foreach ($input['objects'] as $data) {
			$object = new ObjectEntity();
			$object->setObject($data);
			$objects[] = $object;
		}

		$out['objects'] = $call('analyzeObjectProperties', [$objects]);

		array_walk_recursive(
			$out,
			static function (&$value): void {
				if ($value === PHP_INT_MAX) {
					$value = 'PHP_INT_MAX';
				}
			}
		);
		return $out;
	}//end discover()

	/**
	 * The discovery output is exactly the golden.
	 *
	 * @return void
	 */
	public function testDiscoveryMatchesTheGolden(): void {
		$this->assertSame(
			file_get_contents(__DIR__.'/fixtures/schema-discovery/golden.txt'),
			var_export(self::discover(), true)."\n"
		);
	}//end testDiscoveryMatchesTheGolden()
}//end class
