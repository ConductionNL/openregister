<?php

declare(strict_types=1);

/*
 * RelationTypeResolver unit tests.
 *
 * What the resolver has to get right is the DIRECTION: the near side reads the
 * label and the far side reads the inverse. Every test here asserts the exact
 * string the spec names rather than "a label came back", because a resolver
 * that returned the near label on both sides would satisfy the weaker
 * assertion and be exactly the bug this change exists to end.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Relation
 *
 * @author  Conduction Development Team <dev@conduction.nl>
 * @license EUPL-1.2
 *
 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
 */

namespace Unit\Service\Relation;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Relation\RelationTypeResolver;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Relation\RelationTypeResolver
 */
class RelationTypeResolverTest extends TestCase {
	private RelationTypeResolver $resolver;

	protected function setUp(): void {
		parent::setUp();
		$this->resolver = new RelationTypeResolver();
	}//end setUp()

	/**
	 * Build a schema with the given properties and relation vocabulary.
	 *
	 * @param array<string, mixed> $properties The properties.
	 * @param array<int, array<string, mixed>>|null $vocabulary The relation types.
	 *
	 * @return Schema The schema.
	 */
	private function schema(array $properties, ?array $vocabulary = null): Schema {
		$schema = new Schema();
		$schema->setId(7);
		$schema->setProperties($properties);

		if ($vocabulary !== null) {
			$schema->setConfiguration(['x-openregister-relation-types' => $vocabulary]);
		}

		return $schema;
	}//end schema()

	/**
	 * An inline declaration reads one way near and the other way far.
	 *
	 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
	 */
	public function testAnInlineDeclarationNamesBothEnds(): void {
		$schema = $this->schema(
			[
				'blokkeert' => [
					'$ref' => 'cases',
					'x-openregister-relation' => [
						'label' => 'blocks',
						'inverseLabel' => 'blocked by',
					],
				],
			]
		);

		$descriptor = $this->resolver->descriptorFor(schema: $schema, property: 'blokkeert');

		$this->assertSame('blocks', $descriptor['label']);
		$this->assertSame('blocked by', $descriptor['inverseLabel']);

		$near = $this->resolver->row(
			descriptor: $descriptor,
			direction: RelationTypeResolver::DIRECTION_OUTGOING,
			path: 'blokkeert'
		);
		$far = $this->resolver->row(
			descriptor: $descriptor,
			direction: RelationTypeResolver::DIRECTION_INCOMING,
			path: 'blokkeert'
		);

		$this->assertSame('blocks', $near['displayLabel']);
		$this->assertSame('blocked by', $far['displayLabel']);
	}//end testAnInlineDeclarationNamesBothEnds()

	/**
	 * Scenario: a vocabulary key is shared by two properties.
	 *
	 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
	 */
	public function testTwoPropertiesPointingAtOneKeyResolveToTheSamePair(): void {
		$schema = $this->schema(
			[
				'blokkeert' => ['$ref' => 'cases', 'x-openregister-relation' => ['type' => 'blocks']],
				'houdtTegen' => ['$ref' => 'cases', 'x-openregister-relation' => ['type' => 'blocks']],
			],
			[['key' => 'blocks', 'label' => 'blocks', 'inverseLabel' => 'blocked by']]
		);

		$descriptors = $this->resolver->descriptors(schema: $schema);

		$this->assertSame('blocks', $descriptors['blokkeert']['label']);
		$this->assertSame('blocked by', $descriptors['blokkeert']['inverseLabel']);
		$this->assertSame($descriptors['blokkeert']['label'], $descriptors['houdtTegen']['label']);
		$this->assertSame(
			$descriptors['blokkeert']['inverseLabel'],
			$descriptors['houdtTegen']['inverseLabel']
		);
		$this->assertSame('blocks', $descriptors['houdtTegen']['type']);
	}//end testTwoPropertiesPointingAtOneKeyResolveToTheSamePair()

	/**
	 * Scenario: an unannotated reference still reads.
	 *
	 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
	 */
	public function testAnUnannotatedReferenceReadsAsItsTitleAndReferencedBy(): void {
		$schema = $this->schema(['owner' => ['$ref' => 'people', 'title' => 'Eigenaar']]);

		$descriptor = $this->resolver->descriptorFor(schema: $schema, property: 'owner');

		$this->assertSame('owner', $descriptor['property']);
		$this->assertSame('Eigenaar', $descriptor['label']);
		$this->assertSame('referenced by', $descriptor['inverseLabel']);
		$this->assertNull($descriptor['type']);
	}//end testAnUnannotatedReferenceReadsAsItsTitleAndReferencedBy()

	/**
	 * With no title either, the property's own name is the label.
	 */
	public function testAReferenceWithNoTitleReadsAsItsOwnName(): void {
		$schema = $this->schema(['owner' => ['$ref' => 'people']]);

		$this->assertSame(
			'owner',
			$this->resolver->descriptorFor(schema: $schema, property: 'owner')['label']
		);
	}//end testAReferenceWithNoTitleReadsAsItsOwnName()

	/**
	 * A symmetric relation reads the same from both ends.
	 */
	public function testASymmetricRelationReadsTheSameBothWays(): void {
		$schema = $this->schema(
			[
				'duplicaatVan' => [
					'$ref' => 'cases',
					'x-openregister-relation' => ['label' => 'duplicate of', 'symmetric' => true],
				],
			]
		);

		$descriptor = $this->resolver->descriptorFor(schema: $schema, property: 'duplicaatVan');

		$this->assertTrue($descriptor['symmetric']);
		$this->assertSame('duplicate of', $descriptor['label']);
		$this->assertSame('duplicate of', $descriptor['inverseLabel']);
	}//end testASymmetricRelationReadsTheSameBothWays()

	/**
	 * A stored path names the property it belongs to, index and all.
	 */
	public function testAStoredPathResolvesToItsProperty(): void {
		$schema = $this->schema(
			[
				'blokkeert' => [
					'type' => 'array',
					'items' => [
						'$ref' => 'cases',
						'x-openregister-relation' => ['label' => 'blocks', 'inverseLabel' => 'blocked by'],
					],
				],
			]
		);

		$this->assertSame('blokkeert', RelationTypeResolver::propertyNameOf(path: 'blokkeert.2'));
		$this->assertSame(
			'blocked by',
			$this->resolver->descriptorFor(schema: $schema, property: 'blokkeert.2')['inverseLabel']
		);
	}//end testAStoredPathResolvesToItsProperty()

	/**
	 * A label may be a per-language map, resolved like other schema labels.
	 */
	public function testALabelMayBeAPerLanguageMap(): void {
		$schema = $this->schema(
			[
				'blokkeert' => [
					'$ref' => 'cases',
					'x-openregister-relation' => [
						'label' => ['nl' => 'blokkeert', 'en' => 'blocks'],
						'inverseLabel' => ['nl' => 'geblokkeerd door', 'en' => 'blocked by'],
					],
				],
			]
		);

		$dutch = $this->resolver->descriptorFor(schema: $schema, property: 'blokkeert', language: 'nl');
		$english = $this->resolver->descriptorFor(schema: $schema, property: 'blokkeert', language: 'en-GB');

		$this->assertSame('blokkeert', $dutch['label']);
		$this->assertSame('geblokkeerd door', $dutch['inverseLabel']);
		$this->assertSame('blocks', $english['label']);
		$this->assertSame('blocked by', $english['inverseLabel']);
	}//end testALabelMayBeAPerLanguageMap()

	/**
	 * A property's own declaration wins over the vocabulary entry it points at,
	 * so sharing a vocabulary does not stop one property saying something of
	 * its own.
	 */
	public function testAPropertyOverridesTheVocabularyEntryItPointsAt(): void {
		$schema = $this->schema(
			[
				'vervolg' => [
					'$ref' => 'cases',
					'x-openregister-relation' => ['type' => 'blocks', 'label' => 'vervolg op'],
				],
			],
			[['key' => 'blocks', 'label' => 'blocks', 'inverseLabel' => 'blocked by']]
		);

		$descriptor = $this->resolver->descriptorFor(schema: $schema, property: 'vervolg');

		$this->assertSame('vervolg op', $descriptor['label']);
		$this->assertSame('blocked by', $descriptor['inverseLabel']);
	}//end testAPropertyOverridesTheVocabularyEntryItPointsAt()

	/**
	 * A type naming nothing falls back rather than resolving to a stale entry.
	 */
	public function testATypeTheVocabularyDoesNotHoldFallsBack(): void {
		$schema = $this->schema(
			[
				'vervolg' => [
					'$ref' => 'cases',
					'title' => 'Vervolg',
					'x-openregister-relation' => ['type' => 'folows'],
				],
			],
			[['key' => 'blocks', 'label' => 'blocks', 'inverseLabel' => 'blocked by']]
		);

		$descriptor = $this->resolver->descriptorFor(schema: $schema, property: 'vervolg');

		$this->assertNull($descriptor['type']);
		$this->assertSame('Vervolg', $descriptor['label']);
		$this->assertSame('referenced by', $descriptor['inverseLabel']);
	}//end testATypeTheVocabularyDoesNotHoldFallsBack()

	/**
	 * Both inheritance forms resolve to the same role-to-property map.
	 */
	public function testBothInheritanceFormsResolveToOneShape(): void {
		$schema = $this->schema(
			[
				'a' => [
					'$ref' => 'cases',
					'x-openregister-relation' => [
						'label' => 'part of',
						'inherits' => ['classification'],
					],
				],
				'b' => [
					'$ref' => 'cases',
					'x-openregister-relation' => [
						'label' => 'part of',
						'inherits' => ['classification' => 'classificatie', 'budget' => 'begroting'],
					],
				],
			]
		);

		$descriptors = $this->resolver->descriptors(schema: $schema);

		$this->assertSame(['classification' => 'classification'], $descriptors['a']['inherits']);
		// The role outside the closed set is dropped, not carried through.
		$this->assertSame(['classification' => 'classificatie'], $descriptors['b']['inherits']);
	}//end testBothInheritanceFormsResolveToOneShape()

	/**
	 * A property that holds no reference gets no descriptor at all, so a
	 * caller iterating descriptors never renders a relation where there is
	 * none.
	 */
	public function testAPropertyWithoutAReferenceGetsNoDescriptor(): void {
		$schema = $this->schema(
			[
				'onderwerp' => ['type' => 'string'],
				'owner' => ['$ref' => 'people'],
			]
		);

		$this->assertSame(['owner'], array_keys($this->resolver->descriptors(schema: $schema)));
	}//end testAPropertyWithoutAReferenceGetsNoDescriptor()

	/**
	 * With no descriptor at all, a row still reads: the path names the
	 * property and the far side says "referenced by".
	 */
	public function testARowWithNoDescriptorStillReads(): void {
		$row = $this->resolver->row(
			descriptor: null,
			direction: RelationTypeResolver::DIRECTION_INCOMING,
			path: 'owner.0'
		);

		$this->assertSame('owner', $row['property']);
		$this->assertSame('referenced by', $row['displayLabel']);
		$this->assertSame('owner.0', $row['path']);
	}//end testARowWithNoDescriptorStillReads()

	/**
	 * A null schema resolves to nothing rather than throwing, because a
	 * relation whose schema cannot be read must still render as the fallback.
	 */
	public function testANullSchemaResolvesToNothing(): void {
		$this->assertSame([], $this->resolver->descriptors(schema: null));
		$this->assertNull($this->resolver->descriptorFor(schema: null, property: 'owner'));
	}//end testANullSchemaResolvesToNothing()
}//end class
