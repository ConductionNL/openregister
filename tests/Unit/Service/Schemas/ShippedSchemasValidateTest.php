<?php

/**
 * Unit tests that every schema shipped in this repository still validates.
 *
 * The vocabulary is the validator's own list published, not a new list
 * imposed. This test is what turns that sentence into a claim a build can
 * refute: it walks every schema under lib/Settings and runs its properties
 * through the same validator the save path uses. A key the vocabulary forgot
 * fails here, on the shipped registers, before it fails on somebody's import.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Schemas
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/property-vocabulary-published/specs/runtime-schema-api/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\Schemas;

use OCA\OpenRegister\Service\Schemas\PropertyValidatorHandler;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * Backwards compatibility, measured on the schemas we ship.
 */
class ShippedSchemasValidateTest extends TestCase {

	/**
	 * The save-time validator.
	 *
	 * @var PropertyValidatorHandler
	 */
	private PropertyValidatorHandler $validator;

	/**
	 * Wire the collaborators this suite needs.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->validator = new PropertyValidatorHandler();
	}

	/**
	 * The repository root, from this file.
	 *
	 * @return string The absolute path.
	 */
	private function repositoryRoot(): string {
		return dirname(__DIR__, 4);
	}

	/**
	 * Every register descriptor we ship.
	 *
	 * @return array<int, string> Absolute file paths.
	 */
	private function shippedDescriptors(): array {
		$settings = $this->repositoryRoot() . '/lib/Settings';

		$top = glob($settings . '/*.json');
		if ($top === false) {
			$top = [];
		}

		$nested = glob($settings . '/register.d/*/*.json');
		if ($nested === false) {
			$nested = [];
		}

		return array_merge($top, $nested);
	}

	/**
	 * The schemas one descriptor declares, keyed by name.
	 *
	 * Read from `components.schemas` rather than walked for anything holding a
	 * `properties` key. A generic walk cannot tell a schema from a property
	 * that happens to be NAMED `properties`, and `vocabulary_register.json`
	 * has exactly that: `conceptShape.properties.properties`. The walk called
	 * it a schema and the test failed on its own bookkeeping instead of on the
	 * vocabulary. Nested properties and array items need no walk here anyway,
	 * because `validateProperties` recurses into both itself.
	 *
	 * @param array $descriptor The parsed descriptor.
	 *
	 * @return array<string, array<string, mixed>> Schema name to its properties object.
	 */
	private function schemasIn(array $descriptor): array {
		$components = ($descriptor['components'] ?? null);
		if (is_array($components) === false || is_array($components['schemas'] ?? null) === false) {
			return [];
		}

		$found = [];
		foreach ($components['schemas'] as $name => $schema) {
			if (is_array($schema) === false || is_array($schema['properties'] ?? null) === false) {
				continue;
			}

			$found[(string)$name] = $schema['properties'];
		}

		return $found;
	}

	/**
	 * Every shipped schema still validates.
	 *
	 * @return void
	 */
	public function testEveryShippedSchemaStillValidates(): void {
		$descriptors = $this->shippedDescriptors();
		$this->assertNotEmpty(
			actual: $descriptors,
			message: 'no shipped register descriptors were found, so this test proves nothing'
		);

		$checked = 0;
		$failures = [];
		foreach ($descriptors as $file) {
			$descriptor = json_decode((string)file_get_contents($file), true);
			if (is_array($descriptor) === false) {
				continue;
			}

			foreach ($this->schemasIn(descriptor: $descriptor) as $name => $properties) {
				$checked++;
				try {
					$this->validator->validateProperties(properties: $properties, path: '');
				} catch (Throwable $refusal) {
					$failures[] = basename($file) . '/' . $name . ': ' . $refusal->getMessage();
				}
			}
		}

		$this->assertGreaterThan(
			expected: 20,
			actual: $checked,
			message: 'far fewer schemas were walked than this repository ships, so the walk is broken, not clean'
		);
		$this->assertSame(
			expected: [],
			actual: $failures,
			message: "the vocabulary refuses a schema this repository ships:\n" . implode("\n", $failures)
		);
	}
}//end class
