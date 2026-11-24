<?php

/**
 * AnnotatedProperties: the properties of a schema that carry a given annotation.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Rules
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
 * @spec openspec/changes/unit-test-coverage/tasks.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Rules;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Service\Rules\AnnotatedProperties;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Rules\AnnotatedProperties
 */
class AnnotatedPropertiesTest extends TestCase {

	public function testCollectsTheAnnotationOfEveryPropertyThatSetsIt(): void {
		$properties = [
			'age' => ['type' => 'integer', 'x-rule' => ['min' => 18]],
			'name' => ['type' => 'string'],
			'note' => 'not a property',
			'flag' => ['x-rule' => false],
			'empty' => ['x-rule' => null],
		];

		$annotated = (new AnnotatedProperties())->annotations($properties, 'x-rule');

		$this->assertSame(['age' => ['min' => 18], 'flag' => false], $annotated);
		$this->assertTrue((new AnnotatedProperties())->declares($properties, 'x-rule'));
	}

	public function testNoPropertyDeclaresAnAbsentAnnotation(): void {
		$properties = ['age' => ['type' => 'integer'], 'empty' => ['x-rule' => null]];

		$this->assertSame([], (new AnnotatedProperties())->annotations($properties, 'x-rule'));
		$this->assertFalse((new AnnotatedProperties())->declares($properties, 'x-rule'));
		$this->assertFalse((new AnnotatedProperties())->declares([], 'x-rule'));
	}
}
