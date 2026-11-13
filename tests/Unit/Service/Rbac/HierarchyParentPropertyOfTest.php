<?php

/**
 * Unit tests for the shared reader of a schema hierarchy's parent property.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/records-tree-view/specs/saved-search-views/spec.md#requirement-a-saved-view-can-present-hierarchical-records-as-a-tree
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Rbac;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Rbac\HierarchyDescender;
use OCA\OpenRegister\Service\Rbac\HierarchyGrantExpander;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The tree and access inheritance read one declaration, through one reader.
 *
 * @covers \OCA\OpenRegister\Service\Rbac\HierarchyGrantExpander
 * @uses \OCA\OpenRegister\Db\Schema
 */
final class HierarchyParentPropertyOfTest extends TestCase {

	/**
	 * Configurations and the parent property they name.
	 *
	 * @return array<string, array<int, mixed>>
	 */
	public static function configurations(): array {
		return [
			'no configuration' => [null, null],
			'no annotation' => [['other' => true], null],
			'annotation not an object' => [['x-openregister-hierarchy' => 'ouder'], null],
			'canonical parent' => [['x-openregister-hierarchy' => ['parent' => 'ouder']], 'ouder'],
			'older parentField' => [['x-openregister-hierarchy' => ['parentField' => 'ouder']], 'ouder'],
			'parent wins over parentField' => [['x-openregister-hierarchy' => ['parent' => 'a', 'parentField' => 'b']], 'a'],
			'trimmed' => [['x-openregister-hierarchy' => ['parent' => '  ouder ']], 'ouder'],
			'blank parent' => [['x-openregister-hierarchy' => ['parent' => ' ']], null],
		];
	}//end configurations()

	/**
	 * The static reader names the same parent declarationFor() expands along.
	 *
	 * @param array|null  $configuration The schema configuration.
	 * @param string|null $expected      The parent property, or null.
	 *
	 * @return void
	 */
	#[DataProvider('configurations')]
	public function testParentPropertyMatchesTheDeclaration(?array $configuration, ?string $expected): void {
		$schema = new Schema();
		$schema->setConfiguration($configuration);

		$this->assertSame($expected, HierarchyGrantExpander::parentPropertyOf(schema: $schema));

		$expander = new HierarchyGrantExpander(
			$this->createMock(HierarchyDescender::class),
			$this->createMock(LoggerInterface::class)
		);
		$this->assertSame($expected, $expander->declarationFor(schema: $schema)['parent'] ?? null);
	}//end testParentPropertyMatchesTheDeclaration()
}//end class
