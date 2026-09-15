<?php

declare(strict_types=1);

/*
 * RelationAnnotationValidator unit tests.
 *
 * The two refusals the spec names are the two this file exists for, and both
 * are tested by their CODE rather than by "errors is not empty": a validator
 * that refuses for a different reason than the one under test passes a
 * not-empty assertion while proving nothing.
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

use OCA\OpenRegister\Service\Relation\RelationAnnotationValidator;
use OCA\OpenRegister\Service\Relation\RelationDeclarationException;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Relation\RelationAnnotationValidator
 * @covers \OCA\OpenRegister\Service\Relation\RelationDeclarationException
 */
class RelationAnnotationValidatorTest extends TestCase {
	private RelationAnnotationValidator $validator;

	protected function setUp(): void {
		parent::setUp();
		$this->validator = new RelationAnnotationValidator();
	}//end setUp()

	/**
	 * A schema declaring nothing is valid, and a $ref with no annotation is too.
	 */
	public function testAnUnannotatedSchemaIsValid(): void {
		$this->assertSame([], $this->validator->validate(['properties' => []]));
		$this->assertSame(
			[],
			$this->validator->validate(
				['properties' => ['owner' => ['$ref' => 'people', 'title' => 'Eigenaar']]]
			)
		);
	}//end testAnUnannotatedSchemaIsValid()

	/**
	 * Scenario: a symmetric relation with an inverse label is refused.
	 *
	 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
	 */
	public function testASymmetricRelationWithAnInverseLabelIsRefused(): void {
		$errors = $this->validator->validate(
			[
				'properties' => [
					'duplicateOf' => [
						'$ref' => 'cases',
						'x-openregister-relation' => [
							'label' => 'duplicate of',
							'inverseLabel' => 'duplicated by',
							'symmetric' => true,
						],
					],
				],
			]
		);

		$this->assertContains('relation-symmetric-inverse-label', array_column($errors, 'code'));
		$this->assertStringContainsString('duplicateOf', $errors[0]['message']);
	}//end testASymmetricRelationWithAnInverseLabelIsRefused()

	/**
	 * The same declaration WITHOUT the inverse label is fine, so the refusal
	 * above is about the contradiction and not about symmetry.
	 */
	public function testASymmetricRelationWithoutAnInverseLabelIsValid(): void {
		$this->assertSame(
			[],
			$this->validator->validate(
				[
					'properties' => [
						'duplicateOf' => [
							'$ref' => 'cases',
							'x-openregister-relation' => [
								'label' => 'duplicate of',
								'symmetric' => true,
							],
						],
					],
				]
			)
		);
	}//end testASymmetricRelationWithoutAnInverseLabelIsValid()

	/**
	 * Scenario: a vocabulary key that does not exist is refused.
	 *
	 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
	 */
	public function testATypeTheVocabularyDoesNotHoldIsRefused(): void {
		$errors = $this->validator->validate(
			[
				'x-openregister-relation-types' => [
					['key' => 'blocks', 'label' => 'blocks', 'inverseLabel' => 'blocked by'],
				],
				'properties' => [
					'vervolg' => [
						'$ref' => 'cases',
						'x-openregister-relation' => ['type' => 'folows'],
					],
				],
			]
		);

		$codes = array_column($errors, 'code');
		$this->assertContains('relation-type-unknown', $codes);
		$this->assertStringContainsString('folows', $errors[0]['message']);
	}//end testATypeTheVocabularyDoesNotHoldIsRefused()

	/**
	 * Scenario: a vocabulary key is shared by two properties.
	 *
	 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
	 */
	public function testTwoPropertiesMayShareOneVocabularyKey(): void {
		$this->assertSame(
			[],
			$this->validator->validate(
				[
					'x-openregister-relation-types' => [
						['key' => 'blocks', 'label' => 'blocks', 'inverseLabel' => 'blocked by'],
					],
					'properties' => [
						'blokkeert' => ['$ref' => 'cases', 'x-openregister-relation' => ['type' => 'blocks']],
						'houdtTegen' => ['$ref' => 'cases', 'x-openregister-relation' => ['type' => 'blocks']],
					],
				]
			)
		);
	}//end testTwoPropertiesMayShareOneVocabularyKey()

	/**
	 * The annotation is read off `items` too, because that is where an ARRAY
	 * of references carries it. A reader that only looked at the property root
	 * would leave every array relation unvalidated, which is the common shape.
	 */
	public function testTheAnnotationIsReadOffItemsForAnArrayRelation(): void {
		$errors = $this->validator->validate(
			[
				'properties' => [
					'blokkeert' => [
						'type' => 'array',
						'items' => [
							'$ref' => 'cases',
							'x-openregister-relation' => [
								'label' => 'blocks',
								'inverseLabel' => 'blocked by',
								'symmetric' => true,
							],
						],
					],
				],
			]
		);

		$this->assertContains('relation-symmetric-inverse-label', array_column($errors, 'code'));
	}//end testTheAnnotationIsReadOffItemsForAnArrayRelation()

	/**
	 * An annotation on a property holding no reference names nothing.
	 */
	public function testARelationOnAPropertyWithoutARefIsRefused(): void {
		$errors = $this->validator->validate(
			[
				'properties' => [
					'onderwerp' => [
						'type' => 'string',
						'x-openregister-relation' => ['label' => 'blocks'],
					],
				],
			]
		);

		$this->assertContains('relation-without-ref', array_column($errors, 'code'));
	}//end testARelationOnAPropertyWithoutARefIsRefused()

	/**
	 * A declaration with neither a label nor a type says nothing at all.
	 */
	public function testADeclarationWithNeitherLabelNorTypeIsRefused(): void {
		$errors = $this->validator->validate(
			[
				'properties' => [
					'vervolg' => ['$ref' => 'cases', 'x-openregister-relation' => ['symmetric' => false]],
				],
			]
		);

		$this->assertContains('relation-label-missing', array_column($errors, 'code'));
	}//end testADeclarationWithNeitherLabelNorTypeIsRefused()

	/**
	 * The vocabulary itself is refused when it cannot be resolved.
	 */
	public function testAVocabularyEntryWithoutAKeyIsRefused(): void {
		$errors = $this->validator->validate(
			[
				'x-openregister-relation-types' => [['label' => 'blocks']],
				'properties' => [],
			]
		);

		$this->assertContains('relation-vocabulary-key-missing', array_column($errors, 'code'));
	}//end testAVocabularyEntryWithoutAKeyIsRefused()

	/**
	 * A key declared twice would resolve to whichever came last.
	 */
	public function testADuplicateVocabularyKeyIsRefused(): void {
		$errors = $this->validator->validate(
			[
				'x-openregister-relation-types' => [
					['key' => 'blocks', 'label' => 'blocks'],
					['key' => 'blocks', 'label' => 'blokkeert'],
				],
				'properties' => [],
			]
		);

		$this->assertContains('relation-vocabulary-key-duplicate', array_column($errors, 'code'));
	}//end testADuplicateVocabularyKeyIsRefused()

	/**
	 * Inheritance is a closed set of three, and naming a fourth is refused.
	 */
	public function testInheritingSomethingOutsideTheClosedSetIsRefused(): void {
		$errors = $this->validator->validate(
			[
				'properties' => [
					'deelzaakVan' => [
						'$ref' => 'cases',
						'x-openregister-relation' => [
							'label' => 'part of',
							'inherits' => ['classification', 'budget'],
						],
					],
				],
			]
		);

		$codes = array_column($errors, 'code');
		$this->assertContains('relation-inherits-unknown', $codes);
		$this->assertNotContains('relation-label-missing', $codes);
	}//end testInheritingSomethingOutsideTheClosedSetIsRefused()

	/**
	 * Both inheritance forms are accepted: a bare list of roles, and a map
	 * pointing each role at whatever the schema calls that property.
	 */
	public function testBothInheritanceFormsAreAccepted(): void {
		$this->assertSame(
			[],
			$this->validator->validate(
				[
					'properties' => [
						'a' => [
							'$ref' => 'cases',
							'x-openregister-relation' => [
								'label' => 'part of',
								'inherits' => ['classification', 'confidentiality'],
							],
						],
						'b' => [
							'$ref' => 'cases',
							'x-openregister-relation' => [
								'label' => 'part of',
								'inherits' => ['responsible' => 'behandelaar'],
							],
						],
					],
				]
			)
		);
	}//end testBothInheritanceFormsAreAccepted()

	/**
	 * The exception carries every refusal, so a 422 can name each property
	 * rather than only the first.
	 */
	public function testTheExceptionCarriesEveryRefusal(): void {
		$errors = [
			['code' => 'relation-type-unknown', 'message' => 'The property "a" points at "x".'],
			['code' => 'relation-label-missing', 'message' => 'The property "b" declares nothing.'],
		];

		$exception = new RelationDeclarationException(errors: $errors);

		$this->assertSame($errors, $exception->getErrors());
		$this->assertStringContainsString('The property "a"', $exception->getMessage());
		$this->assertStringContainsString('The property "b"', $exception->getMessage());
	}//end testTheExceptionCarriesEveryRefusal()
}//end class
