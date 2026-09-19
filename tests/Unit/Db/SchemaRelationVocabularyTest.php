<?php

declare(strict_types=1);

/*
 * Schema relation-vocabulary round-trip test.
 *
 * `x-openregister-relation-types` is dropped by setConfiguration() unless it
 * is in ANNOTATION_VOCABULARY, and the drop is silent: the save answers 200
 * and every property naming a key resolves to the generic fallback forever.
 * The constant's own comments record that exact bug happening five times, so
 * this test asserts the round trip rather than the constant alone.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author  Conduction Development Team <dev@conduction.nl>
 * @license EUPL-1.2
 *
 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
 */

namespace Unit\Db;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Relation\RelationAnnotationValidator;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * @covers \OCA\OpenRegister\Db\Schema
 */
class SchemaRelationVocabularyTest extends TestCase {
	/**
	 * The key the resolver reads is the key the vocabulary declares. A test
	 * naming the string itself would still pass if the two drifted apart.
	 */
	public function testTheVocabularyDeclaresTheKeyTheValidatorReads(): void {
		$vocabulary = (new ReflectionClass(Schema::class))->getConstant('ANNOTATION_VOCABULARY');

		$this->assertIsArray($vocabulary);
		$this->assertContains(RelationAnnotationValidator::VOCABULARY_ANNOTATION, $vocabulary);
		$this->assertSame('x-openregister-relation-types', RelationAnnotationValidator::VOCABULARY_ANNOTATION);
	}//end testTheVocabularyDeclaresTheKeyTheValidatorReads()

	/**
	 * The annotation survives the configuration round trip intact.
	 */
	public function testTheRelationVocabularySurvivesTheConfigurationRoundTrip(): void {
		$declared = [
			['key' => 'blocks', 'label' => 'blocks', 'inverseLabel' => 'blocked by', 'symmetric' => false],
		];

		$schema = new Schema();
		$schema->setConfiguration([RelationAnnotationValidator::VOCABULARY_ANNOTATION => $declared]);

		$configuration = ($schema->getConfiguration() ?? []);

		$this->assertArrayHasKey(RelationAnnotationValidator::VOCABULARY_ANNOTATION, $configuration);
		$this->assertSame($declared, $configuration[RelationAnnotationValidator::VOCABULARY_ANNOTATION]);
	}//end testTheRelationVocabularySurvivesTheConfigurationRoundTrip()

	/**
	 * A typo in the key is still dropped and still reported, so the round trip
	 * above is evidence of the vocabulary entry and not of a blanket
	 * pass-through.
	 */
	public function testATypoedRelationKeyIsStillDroppedAndReported(): void {
		$schema = new Schema();
		$schema->setConfiguration(['x-openregister-relation-type' => [['key' => 'blocks']]]);

		$this->assertArrayNotHasKey('x-openregister-relation-type', ($schema->getConfiguration() ?? []));
		$this->assertContains('x-openregister-relation-type', $schema->consumeDroppedAnnotationKeys());
	}//end testATypoedRelationKeyIsStillDroppedAndReported()
}//end class
