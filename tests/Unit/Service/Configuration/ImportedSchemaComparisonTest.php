<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Configuration;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Configuration\ImportedSchemaComparison;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What counts as a difference between an incoming and a stored schema.
 *
 * @covers \OCA\OpenRegister\Service\Configuration\ImportedSchemaComparison
 * @uses \OCA\OpenRegister\Db\Schema
 */
class ImportedSchemaComparisonTest extends TestCase {

	/**
	 * The stored schema.
	 *
	 * @return Schema
	 */
	private function stored(): Schema {
		$schema = new Schema();
		$schema->setProperties(['a' => ['type' => 'string', 'enum' => ['x', 'y']], 'b' => ['type' => 'string']]);
		$schema->setRequired(['a', 'b']);
		$schema->setConfiguration(['x-openregister-mcp' => ['on' => true], 'objectNameField' => 'a']);

		return $schema;
	}//end stored()

	/**
	 * Incoming definitions and whether each differs from the stored schema.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: bool}>
	 */
	public static function definitions(): array {
		$properties = ['a' => ['type' => 'string', 'enum' => ['x', 'y']], 'b' => ['type' => 'string']];
		$same       = ['properties' => $properties, 'required' => ['a', 'b']];

		return [
			'the same'                     => [$same, false],
			'keys in another order'        => [['properties' => array_reverse($properties), 'required' => ['a', 'b']], false],
			'a list in another order'      => [['properties' => $properties, 'required' => ['b', 'a']], false],
			'an empty authorization'       => [$same + ['authorization' => []], false],
			'a new property'               => [['properties' => $properties + ['c' => []], 'required' => ['a', 'b']], true],
			'a new required'               => [['properties' => $properties, 'required' => ['a']], true],
			'an authorization'             => [$same + ['authorization' => ['read' => ['g']]], true],
			'the same annotation'          => [$same + ['x-openregister-mcp' => ['on' => true]], false],
			'another annotation'           => [$same + ['x-openregister-mcp' => ['on' => false]], true],
			'an unknown annotation'        => [$same + ['x-openregister-nonsense' => 1], false],
			'a key OpenRegister maintains' => [$same + ['objectNameField' => 'b'], false],
			'a nested annotation'          => [$same + ['configuration' => ['x-openregister-mcp' => ['on' => false]]], true],
			'nested wins over top level'   => [$same + ['x-openregister-mcp' => ['on' => false], 'configuration' => ['x-openregister-mcp' => ['on' => true]]], false],
			'a configuration not an array' => [$same + ['configuration' => 'text'], false],
			'a party kind'                 => [$same + ['partyKinds' => ['person']], true],
			'a link role'                  => [$same + ['configuration' => ['linkRoles' => ['owner']]], true],
		];
	}//end definitions()

	/**
	 * Each incoming definition compares as expected.
	 *
	 * @param array<string, mixed> $data    The incoming definition.
	 * @param bool                 $differs Whether it differs.
	 *
	 * @return void
	 */
	#[DataProvider('definitions')]
	public function testAnIncomingDefinitionComparesAsExpected(array $data, bool $differs): void {
		$this->assertSame($differs, (new ImportedSchemaComparison())->differs(data: $data, existing: $this->stored()));
	}//end testAnIncomingDefinitionComparesAsExpected()
}//end class
