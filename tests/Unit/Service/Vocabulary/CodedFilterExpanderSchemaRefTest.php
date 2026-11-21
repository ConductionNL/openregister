<?php

/**
 * CodedFilterExpander: which schema references it resolves (a scalar, a
 * one-element list; never several, none or one that fails), blank branches,
 * a non-numeric depth, and concepts without a notation.
 *
 * CodedFilterExpanderTest covers expansion against a resolved schema.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Vocabulary
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Vocabulary;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.
// phpcs:disable Squiz.Commenting.VariableComment.Missing -- typed mock fixtures; the declaration IS the description.

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Service\Vocabulary\CodedFilterExpander;
use OCA\OpenRegister\Service\Vocabulary\CodedPropertyDeclaration;
use OCA\OpenRegister\Service\Vocabulary\CodedPropertyDeclarationFactory;
use OCA\OpenRegister\Service\Vocabulary\ConceptHierarchy;
use OCA\OpenRegister\Service\Vocabulary\ConceptLifecycle;
use OCA\OpenRegister\Service\Vocabulary\ConceptRepository;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Vocabulary\CodedFilterExpander
 * @uses \OCA\OpenRegister\Db\Schema
 * @uses \OCA\OpenRegister\Service\Vocabulary\BranchFilter
 * @uses \OCA\OpenRegister\Service\Vocabulary\CodedNarrowing
 * @uses \OCA\OpenRegister\Service\Vocabulary\CodedPropertyDeclaration
 * @uses \OCA\OpenRegister\Service\Vocabulary\CodedPropertyDeclarationFactory
 * @uses \OCA\OpenRegister\Service\Vocabulary\CodedSchemaTables
 * @uses \OCA\OpenRegister\Service\Vocabulary\CodedValueRules
 * @uses \OCA\OpenRegister\Service\Vocabulary\ConceptBranch
 * @uses \OCA\OpenRegister\Service\Vocabulary\ConceptHierarchy
 * @uses \OCA\OpenRegister\Service\Vocabulary\ConceptRelations
 * @uses \OCA\OpenRegister\Service\Vocabulary\ConceptSchemeAccess
 */
class CodedFilterExpanderSchemaRefTest extends TestCase {

	private SchemaMapperInterface&MockObject $schemas;
	private CodedFilterExpander $expander;

	protected function setUp(): void {
		parent::setUp();
		$concepts = $this->createMock(ConceptRepository::class);
		$concepts->method('conceptsOf')->willReturn([
			'urn:root' => ['uri' => 'urn:root', 'narrower' => ['urn:a', 'urn:b'], 'notation' => ' R '],
			'urn:a' => ['uri' => 'urn:a', 'broader' => ['urn:root']],
			'urn:b' => ['uri' => 'urn:b', 'broader' => ['urn:root'], 'notation' => 'B'],
		]);
		$this->schemas = $this->createMock(SchemaMapperInterface::class);
		$lifecycle = new ConceptLifecycle();
		$this->expander = new CodedFilterExpander(
			new \OCA\OpenRegister\Service\Vocabulary\ConceptSchemeAccess($concepts, new ConceptHierarchy($lifecycle), $lifecycle),
			new \OCA\OpenRegister\Service\Vocabulary\CodedSchemaTables($this->schemas, new CodedPropertyDeclarationFactory(), $this->createMock(\OCA\OpenRegister\Db\MagicMapperInterface::class))
		);
	}

	private static function schema(string $store = 'uri'): Schema {
		$schema = new Schema();
		$schema->setProperties(['kind' => ['type' => 'string', CodedPropertyDeclaration::ANNOTATION => ['scheme' => 'urn:scheme', 'store' => $store]]]);
		return $schema;
	}

	public function testAOneElementListOfSchemaReferencesIsResolved(): void {
		$this->schemas->expects($this->once())->method('find')->with('7', [], false, false)->willReturn(self::schema());

		$this->assertSame(
			['kind' => ['in' => ['urn:root', 'urn:a', 'urn:b']], 'other' => 'x'],
			$this->expander->expand(['kind' => ['branch' => ' urn:root '], 'other' => 'x'], ['7'])
		);
	}

	public function testSeveralNoneOrABlankSchemaReferenceLeavesTheFiltersAlone(): void {
		$this->schemas->expects($this->never())->method('find');
		$filters = ['kind' => ['branch' => 'urn:root']];

		$this->assertSame($filters, $this->expander->expand($filters, [7, 8]));
		$this->assertSame($filters, $this->expander->expand($filters, null));
		$this->assertSame($filters, $this->expander->expand($filters, ''));
	}

	public function testASchemaThatDoesNotResolveLeavesTheFiltersAlone(): void {
		$this->schemas->method('find')->willThrowException(new DoesNotExistException('gone'));
		$filters = ['kind' => ['branch' => 'urn:root']];

		$this->assertSame($filters, $this->expander->expand($filters, 7));
	}

	public function testABlankOrNonStringBranchIsNoBranchFilter(): void {
		$this->schemas->expects($this->never())->method('find');
		$filters = ['kind' => ['branch' => '  '], 'size' => ['branch' => 3]];

		$this->assertSame($filters, $this->expander->expand($filters, 7));
	}

	public function testANonNumericDepthFallsBackToTheDeclaredDepth(): void {
		$this->schemas->method('find')->willReturn(self::schema());

		$expanded = $this->expander->expand(['kind' => ['branch' => 'urn:root', 'depth' => 'deep']], 7);

		$this->assertSame(['in' => ['urn:root', 'urn:a', 'urn:b']], $expanded['kind']);
	}

	public function testConceptsWithoutANotationAreLeftOutOfANotationExpansion(): void {
		$this->schemas->method('find')->willReturn(self::schema('notation'));

		$this->assertSame(['in' => ['R', 'B']], $this->expander->expand(['kind' => ['branch' => 'urn:root']], 7)['kind']);
	}
}
