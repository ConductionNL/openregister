<?php

/**
 * The shipped mock register's lifecycle annotations must actually validate.
 *
 * `openregister_mock_register.json` is the register a developer imports to see
 * the platform work, and its `dataSubjectRequest.refuse` transition is the
 * worked example for declarative conditions. An example that does not survive
 * the validator is worse than no example: it is copied, and it fails in the
 * copier's schema rather than here.
 *
 * This runs every lifecycle annotation in that file through the real
 * `LifecycleAnnotationValidator`, so the guarantee is mechanical rather than a
 * promise in a docblock. It also covers the annotations that were already
 * there, which nothing previously checked.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/lifecycle-declarative-conditions/specs/object-lifecycle/spec.md
 */

declare(strict_types=1);

namespace Unit\Settings;

use OCA\OpenRegister\Service\Lifecycle\LifecycleAnnotationValidator;
use PHPUnit\Framework\TestCase;

/**
 * Validates the mock register's declared lifecycles.
 */
class MockRegisterLifecycleTest extends TestCase {

	/**
	 * Every schema in the mock register that declares a lifecycle.
	 *
	 * @return array<string, array{0: string, 1: array<string, mixed>}>
	 */
	public static function lifecycleSchemaProvider(): array {
		$path = dirname(__DIR__, 3) . '/lib/Settings/openregister_mock_register.json';
		$decoded = json_decode((string)file_get_contents($path), true);

		$cases = [];
		foreach (($decoded['components']['schemas'] ?? []) as $name => $schema) {
			if (isset($schema['x-openregister-lifecycle']) === true) {
				$cases[$name] = [$name, $schema];
			}
		}

		return $cases;
	}//end lifecycleSchemaProvider()

	/**
	 * @dataProvider lifecycleSchemaProvider
	 *
	 * @param string $name The schema's name, for the failure message.
	 * @param array<string, mixed> $schema The full schema definition.
	 *
	 * @return void
	 */
	public function testShippedLifecycleAnnotationValidates(string $name, array $schema): void {
		$errors = (new LifecycleAnnotationValidator())->validate($schema);

		$this->assertSame(
			expected: [],
			actual: $errors,
			message: sprintf(
				'Mock register schema "%s" declares a lifecycle the validator rejects: %s',
				$name,
				json_encode($errors)
			)
		);
	}//end testShippedLifecycleAnnotationValidates()

	/**
	 * The worked example is the reason this file exists, so assert its shape
	 * directly rather than relying on it merely being among the provider's
	 * cases. A refactor that dropped the condition would still leave every
	 * other assertion above green.
	 *
	 * @return void
	 */
	public function testTheWorkedConditionExampleIsPresentAndWellFormed(): void {
		$path = dirname(__DIR__, 3) . '/lib/Settings/openregister_mock_register.json';
		$decoded = json_decode((string)file_get_contents($path), true);
		$refuse = $decoded['components']['schemas']['dataSubjectRequest']
			['x-openregister-lifecycle']['transitions']['refuse'];

		// A rule OBJECT, never a scalar: a scalar would validate as a literal
		// and then authorise every refusal it was written to gate.
		$this->assertIsArray(actual: ($refuse['condition'] ?? null));
		$this->assertIsArray(actual: ($refuse['message'] ?? null));
		$this->assertArrayHasKey(key: 'nl', array: $refuse['message']);
		$this->assertArrayHasKey(key: 'en', array: $refuse['message']);

		// The condition must name a property the schema actually declares.
		$properties = $decoded['components']['schemas']['dataSubjectRequest']['properties'];
		$this->assertArrayHasKey(key: 'denialGround', array: $properties);
	}//end testTheWorkedConditionExampleIsPresentAndWellFormed()
}//end class
