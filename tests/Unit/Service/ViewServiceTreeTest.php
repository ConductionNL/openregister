<?php

/**
 * Unit tests for the tree presentation of a saved view.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
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

namespace OCA\OpenRegister\Tests\Unit\Service;

use InvalidArgumentException;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Search\Db\ViewMapperInterface;
use OCA\OpenRegister\Search\Service\ViewAudience;
use OCA\OpenRegister\Search\Service\ViewDefinition;
use OCA\OpenRegister\Search\Service\ViewEdit;
use OCA\OpenRegister\Search\Service\ViewService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Which tree presentations a view save accepts, and why it refuses the others.
 *
 * @covers \OCA\OpenRegister\Search\Service\ViewService
 * @uses \OCA\OpenRegister\Db\Schema
 * @uses \OCA\OpenRegister\Search\Db\View
 * @uses \OCA\OpenRegister\Search\Db\ViewFormatting
 * @uses \OCA\OpenRegister\Search\Service\PresentationCheck
 * @uses \OCA\OpenRegister\Search\Service\ViewAudience
 * @uses \OCA\OpenRegister\Search\Service\ViewDefinition
 * @uses \OCA\OpenRegister\Search\Service\ViewEdit
 * @uses \OCA\OpenRegister\Service\Rbac\AccessDeclarationValidators
 * @uses \OCA\OpenRegister\Service\Rbac\HierarchyGrantExpander
 */
final class ViewServiceTreeTest extends TestCase {

	/**
	 * The service over `afdelingen` (a hierarchy) and `meldingen` (flat).
	 *
	 * @return ViewService
	 */
	private function service(): ViewService {
		$mapper = $this->createMock(ViewMapperInterface::class);
		$mapper->method('insert')->willReturnArgument(0);
		$mapper->method('findAll')->willReturn([]);

		$afdelingen = new Schema();
		$afdelingen->setProperties(
			[
				'naam' => ['type' => 'string'],
				'code' => ['type' => 'string'],
				'bovenliggendeAfdeling' => ['type' => 'string', 'format' => 'uuid'],
			]
		);
		$afdelingen->setConfiguration(['x-openregister-hierarchy' => ['parent' => 'bovenliggendeAfdeling']]);

		$legacy = new Schema();
		$legacy->setProperties(['naam' => ['type' => 'string'], 'ouder' => ['type' => 'string']]);
		$legacy->setConfiguration(['x-openregister-hierarchy' => ['parentField' => 'ouder']]);

		$meldingen = new Schema();
		$meldingen->setProperties(['naam' => ['type' => 'string']]);

		$blank = new Schema();
		$blank->setProperties(['naam' => ['type' => 'string']]);
		$blank->setConfiguration(['x-openregister-hierarchy' => ['parent' => '  ']]);

		$schemas = $this->createMock(SchemaMapperInterface::class);
		$schemas->method('find')->willReturnCallback(
			static fn (string|int $id): Schema => ['afdelingen' => $afdelingen, 'legacy' => $legacy, 'meldingen' => $meldingen, 'blank' => $blank][$id]
		);

		return new ViewService($mapper, $this->createMock(LoggerInterface::class), $schemas);
	}//end service()

	/**
	 * Tree shapes, the schema they are saved on, and the outcome.
	 *
	 * @return array<string, array<int, mixed>>
	 */
	public static function trees(): array {
		$noHierarchy = 'A tree presentation requires a schema that declares x-openregister-hierarchy; the view\'s schema declares no hierarchy';
		return [
			'the department tree' => ['afdelingen', ['labelField' => 'naam'], 'ok'],
			'with extra fields' => ['afdelingen', ['labelField' => 'naam', 'fields' => ['code']], 'ok'],
			'the older parentField spelling' => ['legacy', ['labelField' => 'naam'], 'ok'],
			'a flat schema' => ['meldingen', ['labelField' => 'naam'], $noHierarchy],
			'a blank parent is no hierarchy' => ['blank', ['labelField' => 'naam'], $noHierarchy],
			'no label' => ['afdelingen', [], 'A tree presentation requires a non-empty tree.labelField'],
			'an unknown label' => ['afdelingen', ['labelField' => 'nope'], 'Presentation tree.labelField "nope" is not a property of the view\'s schema'],
			'an unknown extra field' => ['afdelingen', ['labelField' => 'naam', 'fields' => ['code', 'nope']], 'Presentation tree.fields ""nope"" is not a property of the view\'s schema'],
			'extra fields not a list' => ['afdelingen', ['labelField' => 'naam', 'fields' => 'code'], 'Presentation tree.fields must be a list of property names'],
		];
	}//end trees()

	/**
	 * A save accepts a valid tree as given and refuses an invalid one with its reason.
	 *
	 * @param string               $schema   The view's schema.
	 * @param array<string, mixed> $tree     The tree config.
	 * @param string               $expected 'ok' or the exact refusal.
	 *
	 * @return void
	 */
	#[DataProvider('trees')]
	public function testTreeSave(string $schema, array $tree, string $expected): void {
		$presentation = ['viewType' => 'tree', 'tree' => $tree];
		try {
			$view = $this->service()->create(
				new ViewEdit(definition: new ViewDefinition('Afdelingen', '', ['schemas' => [$schema]], $presentation), audience: new ViewAudience('alice', false, false))
			);
			$this->assertSame($presentation, $view->getPresentation());
			$outcome = 'ok';
		} catch (InvalidArgumentException $e) {
			$outcome = $e->getMessage();
		}

		$this->assertSame($expected, $outcome);
	}//end testTreeSave()

	/**
	 * A tree view saved without a schema is refused before any field is read.
	 *
	 * @return void
	 */
	public function testATreeWithoutASchemaIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Cannot validate tree: the view has no schema configured to validate against');

		$this->service()->create(
			new ViewEdit(
				definition: new ViewDefinition('Afdelingen', '', [], ['viewType' => 'tree', 'tree' => ['labelField' => 'naam']]),
				audience: new ViewAudience('alice', false, false)
			)
		);
	}//end testATreeWithoutASchemaIsRefused()
}//end class
