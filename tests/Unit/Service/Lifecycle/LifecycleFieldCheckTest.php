<?php

/**
 * The lifecycle `field` check, in graph mode and in provider mode.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Lifecycle
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Lifecycle;

use OCA\OpenRegister\Service\Lifecycle\LifecycleGraphValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Graph-mode `field` errors from LifecycleGraphValidator.
 */
class LifecycleFieldCheckTest extends TestCase {

	/**
	 * A graph block with every required key.
	 *
	 * @var array<string, mixed>
	 */
	private const GRAPH = [
		'edgeSchema' => 'e',
		'fromProperty' => 'from',
		'toProperty' => 'to',
		'labelProperty' => 'label',
		'nodeSchema' => 'n',
	];

	/**
	 * Annotation `field`, schema properties, and the field errors expected.
	 *
	 * @return array<string, array{mixed, mixed, list<string>}>
	 */
	public static function cases(): array {
		return [
			'no field' => [null, ['status' => []], ['lifecycle-missing-key']],
			'empty field' => ['', ['status' => []], ['lifecycle-missing-key']],
			'non-string field' => [5, ['status' => []], ['lifecycle-missing-key']],
			'undeclared field' => ['state', ['status' => []], ['lifecycle-field-missing']],
			'declared field' => ['status', ['status' => []], []],
			'properties not a list' => ['state', 'nope', []],
		];
	}//end cases()

	/**
	 * Graph mode reports a missing or undeclared `field` with the shared codes and messages.
	 *
	 * @param mixed        $field      The annotation's field.
	 * @param mixed        $properties The schema's properties.
	 * @param list<string> $codes      The field error codes expected.
	 *
	 * @return void
	 */
	#[DataProvider('cases')]
	public function testGraphModeFieldErrors(mixed $field, mixed $properties, array $codes): void {
		$annotation = ['graph' => self::GRAPH, 'final' => ['states' => ['done']]];
		if ($field !== null) {
			$annotation['field'] = $field;
		}

		$errors = (new LifecycleGraphValidator())->validate(annotation: $annotation, schema: ['properties' => $properties]);
		$fieldErrors = array_values(
			array_filter($errors, static fn (array $e): bool => in_array($e['code'], ['lifecycle-missing-key', 'lifecycle-field-missing'], true))
		);

		$this->assertSame($codes, array_column($fieldErrors, 'code'));
		$messages = [
			'lifecycle-missing-key' => 'x-openregister-lifecycle is missing required key "field".',
			'lifecycle-field-missing' => sprintf('x-openregister-lifecycle.field "%s" is not declared in `properties`.', (string)$field),
		];
		foreach ($fieldErrors as $error) {
			$this->assertSame($messages[$error['code']], $error['message']);
		}
	}//end testGraphModeFieldErrors()
}//end class
