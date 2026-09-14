<?php

/**
 * Unit tests for {@see \OCA\OpenRegister\Service\Vocabulary\CodedFilterExpander}.
 *
 * The assertion that carries the most weight is the negative one: an
 * unresolvable branch is LEFT ALONE rather than expanded to the empty set. A
 * filter that quietly becomes the empty set is indistinguishable from one
 * that matched nothing, and only one of those is a bug.
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
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/code-list-lifecycle-and-hierarchy/specs/skos-concept-registers/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\Vocabulary;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Vocabulary\CodedFilterExpander;
use OCA\OpenRegister\Service\Vocabulary\CodedPropertyDeclaration;
use OCA\OpenRegister\Service\Vocabulary\ConceptHierarchy;
use OCA\OpenRegister\Service\Vocabulary\ConceptLifecycle;
use OCA\OpenRegister\Service\Vocabulary\ConceptRepository;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class CodedFilterExpanderTest extends TestCase {

	private const SCHEME = 'https://example.org/vergunningen';

	private CodedFilterExpander $expander;

	private SchemaMapper&MockObject $schemas;

	/**
	 * @return void
	 */
	protected function setUp(): void {
		$scheme = [
			'urn:vergunning' => ['uri' => 'urn:vergunning', 'narrower' => ['urn:kap'], 'notation' => 'V'],
			'urn:kap' => ['uri' => 'urn:kap', 'broader' => ['urn:vergunning'], 'narrower' => ['urn:kap-spoed'], 'notation' => 'VK'],
			'urn:kap-spoed' => ['uri' => 'urn:kap-spoed', 'broader' => ['urn:kap'], 'notation' => 'VKS'],
			'urn:melding' => ['uri' => 'urn:melding', 'notation' => 'M'],
		];

		$concepts = $this->createMock(ConceptRepository::class);
		$concepts->method('conceptsOf')->willReturn($scheme);

		$lifecycle = new ConceptLifecycle();
		$this->schemas = $this->createMock(SchemaMapper::class);

		$this->expander = new CodedFilterExpander(
			concepts: $concepts,
			hierarchy: new ConceptHierarchy(lifecycle: $lifecycle),
			schemas: $this->schemas
		);
	}//end setUp()

	/**
	 * Register a schema declaring one coded property.
	 *
	 * @param array<string,mixed> $declaration The x-openregister-concepts block.
	 *
	 * @return void
	 */
	private function givenSchema(array $declaration = []): void {
		$schema = new Schema();
		$schema->setProperties(
			[
				'categorie' => [
					'type' => 'string',
					CodedPropertyDeclaration::ANNOTATION => array_merge(['scheme' => self::SCHEME], $declaration),
				],
			]
		);

		$this->schemas->method('find')->willReturn($schema);
	}//end givenSchema()

	/**
	 * Filtering on a branch finds the leaves, and the root itself.
	 *
	 * @return void
	 */
	public function testFilteringByABranchFindsTheLeaves(): void {
		$this->givenSchema();

		$expanded = $this->expander->expand(
			filters: ['categorie' => ['branch' => 'urn:vergunning']],
			schemaRef: 7
		);

		$this->assertSame(
			['in' => ['urn:vergunning', 'urn:kap', 'urn:kap-spoed']],
			$expanded['categorie']
		);
	}//end testFilteringByABranchFindsTheLeaves()

	/**
	 * The depth bound on the request wins over the one on the declaration.
	 *
	 * @return void
	 */
	public function testTheRequestedDepthBoundsTheWalk(): void {
		$this->givenSchema(declaration: ['maxDepth' => 5]);

		$expanded = $this->expander->expand(
			filters: ['categorie' => ['branch' => 'urn:vergunning', 'depth' => 1]],
			schemaRef: 7
		);

		$this->assertSame(['in' => ['urn:vergunning', 'urn:kap']], $expanded['categorie']);
	}//end testTheRequestedDepthBoundsTheWalk()

	/**
	 * A notation-storing property expands to notations, not uris, because
	 * that is what its objects hold.
	 *
	 * @return void
	 */
	public function testANotationStoringPropertyExpandsToNotations(): void {
		$this->givenSchema(declaration: ['store' => 'notation']);

		$expanded = $this->expander->expand(
			filters: ['categorie' => ['branch' => 'urn:kap']],
			schemaRef: 7
		);

		$this->assertSame(['in' => ['VK', 'VKS']], $expanded['categorie']);
	}//end testANotationStoringPropertyExpandsToNotations()

	/**
	 * A branch the scheme does not hold leaves the filter exactly as it
	 * arrived, so the query fails visibly rather than returning nothing.
	 *
	 * @return void
	 */
	public function testAnUnresolvableBranchIsLeftAlone(): void {
		$this->givenSchema();

		$filters = ['categorie' => ['branch' => 'urn:er-is-niets']];
		$this->assertSame($filters, $this->expander->expand(filters: $filters, schemaRef: 7));
	}//end testAnUnresolvableBranchIsLeftAlone()

	/**
	 * An ordinary filter is untouched, and a query with no branch filter
	 * never reaches the schema at all.
	 *
	 * @return void
	 */
	public function testAnOrdinaryFilterIsUntouchedAndCostsNoSchemaRead(): void {
		$this->schemas->expects($this->never())->method('find');

		$filters = ['categorie' => 'urn:kap', 'status' => ['in' => ['open']]];
		$this->assertSame($filters, $this->expander->expand(filters: $filters, schemaRef: 7));
	}//end testAnOrdinaryFilterIsUntouchedAndCostsNoSchemaRead()

	/**
	 * A cross-schema query is left alone: one schema's declaration must not
	 * be applied to every schema in the query.
	 *
	 * @return void
	 */
	public function testACrossSchemaQueryIsLeftAlone(): void {
		$this->schemas->expects($this->never())->method('find');

		$filters = ['categorie' => ['branch' => 'urn:vergunning']];
		$this->assertSame($filters, $this->expander->expand(filters: $filters, schemaRef: [7, 8]));
	}//end testACrossSchemaQueryIsLeftAlone()

	/**
	 * A branch filter on a property that declares no code list is left alone.
	 *
	 * @return void
	 */
	public function testAPropertyWithoutADeclarationIsLeftAlone(): void {
		$this->givenSchema();

		$filters = ['onderwerp' => ['branch' => 'urn:vergunning']];
		$this->assertSame($filters, $this->expander->expand(filters: $filters, schemaRef: 7));
	}//end testAPropertyWithoutADeclarationIsLeftAlone()
}//end class
