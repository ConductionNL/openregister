<?php

/**
 * Characterisation of StateHistoryProjector::declaredProperty().
 *
 * Pins which lifecycle field a schema declares: none without a schema, without
 * an `x-openregister-lifecycle` block or with an empty field; `field` before
 * `property`; and the string form of a non-string field.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\History
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\History;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\History\StateHistoryProjector;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

class StateHistoryProjectorDeclaredPropertyTest extends TestCase {

	public static function configurations(): array {
		return [
			'no configuration' => [null, null],
			'no lifecycle block' => [['other' => true], null],
			'lifecycle block not a map' => [['x-openregister-lifecycle' => 'status'], null],
			'field' => [['x-openregister-lifecycle' => ['field' => 'status', 'property' => 'fase']], 'status'],
			'property' => [['x-openregister-lifecycle' => ['property' => 'fase']], 'fase'],
			'null field falls back to property' => [['x-openregister-lifecycle' => ['field' => null, 'property' => 'fase']], 'fase'],
			'empty field' => [['x-openregister-lifecycle' => ['field' => '', 'property' => 'fase']], null],
			'neither' => [['x-openregister-lifecycle' => []], null],
			'numeric field' => [['x-openregister-lifecycle' => ['field' => 7]], '7'],
		];
	}//end configurations()

	private function declared(?Schema $schema): ?string {
		$projector = (new ReflectionClass(StateHistoryProjector::class))->newInstanceWithoutConstructor();
		return (new ReflectionMethod(StateHistoryProjector::class, 'declaredProperty'))->invoke($projector, $schema);
	}//end declared()

	#[DataProvider('configurations')]
	public function testTheDeclaredLifecycleField(?array $configuration, ?string $expected): void {
		$schema = new Schema();
		$schema->setConfiguration($configuration);

		$this->assertSame($expected, $this->declared($schema));
	}//end testTheDeclaredLifecycleField()

	public function testNoSchemaDeclaresNothing(): void {
		$this->assertNull($this->declared(null));
	}//end testNoSchemaDeclaresNothing()
}//end class
