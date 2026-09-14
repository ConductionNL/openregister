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
	 * Collect every properties object nested anywhere in a descriptor.
	 *
	 * @param mixed $node The current node.
	 * @param string $path Where the node sits, for the failure message.
	 * @param array $found Accumulator of path to properties object.
	 *
	 * @return void
	 */
	private function collectPropertyObjects(mixed $node, string $path, array &$found): void {
		if (is_array($node) === false) {
			return;
		}

		if (isset($node['properties']) === true && is_array($node['properties']) === true) {
			$found[$path . '/properties'] = $node['properties'];
		}

		foreach ($node as $key => $child) {
			if (is_array($child) === true) {
				$this->collectPropertyObjects(node: $child, path: $path . '/' . (string)$key, found: $found);
			}
		}
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

			$found = [];
			$this->collectPropertyObjects(node: $descriptor, path: basename($file), found: $found);
			foreach ($found as $path => $properties) {
				$checked++;
				try {
					$this->validator->validateProperties(properties: $properties, path: '');
				} catch (Throwable $refusal) {
					$failures[] = $path . ': ' . $refusal->getMessage();
				}
			}
		}

		$this->assertGreaterThan(
			expected: 50,
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
