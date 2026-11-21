<?php

/**
 * NamedConditionCheckTest
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db\SchemaMapper
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db\SchemaMapper;

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.
// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.

use Exception;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper\NamedConditionCheck;
use PHPUnit\Framework\TestCase;

/**
 * Schema save refuses a named condition it cannot resolve (REQ-RCT-001, task 1.3b).
 *
 * @covers \OCA\OpenRegister\Db\SchemaMapper\NamedConditionCheck
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Db\SchemaMapper\AnnotationFaults
 * @uses   \OCA\OpenRegister\Service\Rules\NamedConditionLibrary
 * @uses   \OCA\OpenRegister\Service\Rules\DeclaredRuleDescriptors
 * @uses   \OCA\OpenRegister\Service\Rules\RuleDescriptor
 * @uses   \OCA\OpenRegister\Service\Rules\RuleVocabulary
 */
class NamedConditionCheckTest extends TestCase {

	/**
	 * A schema `meldingen` with this configuration.
	 *
	 * @param array<string, mixed> $configuration The configuration.
	 *
	 * @return Schema
	 */
	private static function schema(array $configuration): Schema {
		$schema = new Schema();
		$schema->setSlug('meldingen');
		$schema->setConfiguration($configuration);

		return $schema;
	}//end schema()

	private static function refusal(Schema $schema): ?string {
		try {
			(new NamedConditionCheck())->check($schema);
		} catch (Exception $e) {
			return $e->getMessage();
		}

		return null;
	}//end refusal()

	private static function validation(array $condition): array {
		return ['condition' => $condition, 'severity' => 'refuse', 'message' => 'Spoed vraagt een reden.'];
	}//end validation()

	public function testAValidationReferencingAnUndeclaredNameIsRefusedNamingBoth(): void {
		$refusal = self::refusal(self::schema(['x-openregister-validations' => ['spoedreden' => self::validation(['$condition' => 'spoedeisend'])]]));

		$this->assertNotNull($refusal);
		$this->assertStringStartsWith('x-openregister-conditions: ', $refusal);
		$this->assertStringContainsString('"spoedeisend"', $refusal);
		$this->assertStringContainsString('spoedreden', $refusal, 'the rule that referenced it is named');
	}//end testAValidationReferencingAnUndeclaredNameIsRefusedNamingBoth()

	public function testTheSameReferenceSavesOnceTheNameIsDeclared(): void {
		$schema = self::schema(
			[
				'x-openregister-conditions'  => ['spoedeisend' => ['description' => 'Spoed', 'expression' => ['==' => [['var' => 'spoed'], true]]]],
				'x-openregister-validations' => ['spoedreden' => self::validation(['not' => ['$condition' => 'spoedeisend']])],
			]
		);

		$this->assertNull(self::refusal($schema));
	}//end testTheSameReferenceSavesOnceTheNameIsDeclared()

	public function testACycleIsRefusedNamingBothConditions(): void {
		$refusal = self::refusal(
			self::schema(
				[
					'x-openregister-conditions' => [
						'a' => ['expression' => ['and' => [['$condition' => 'b']]]],
						'b' => ['expression' => ['or' => [['$condition' => 'a']]]],
					],
				]
			)
		);

		$this->assertNotNull($refusal);
		$this->assertStringContainsString('cycle', $refusal);
		$this->assertStringContainsString('a', $refusal);
		$this->assertStringContainsString('b', $refusal);
	}//end testACycleIsRefusedNamingBothConditions()

	public function testATransitionGuardReferencingAnUndeclaredNameIsRefused(): void {
		$schema = self::schema(
			[
				'x-openregister-lifecycle' => [
					'states'      => ['nieuw' => [], 'afgehandeld' => []],
					'transitions' => ['afhandelen' => ['from' => 'nieuw', 'to' => 'afgehandeld', 'condition' => ['$condition' => 'compleet']]],
				],
			]
		);

		$refusal = self::refusal($schema);

		$this->assertNotNull($refusal);
		$this->assertStringContainsString('"compleet"', $refusal);
		$this->assertStringContainsString('afhandelen', $refusal);
	}//end testATransitionGuardReferencingAnUndeclaredNameIsRefused()

	public function testASchemaWithoutNamedConditionsSaves(): void {
		$this->assertNull(self::refusal(self::schema([])));
		$this->assertNull(self::refusal(self::schema(['x-openregister-validations' => ['leeg' => self::validation(['==' => [1, 1]])]])));
	}//end testASchemaWithoutNamedConditionsSaves()

	public function testTheRuleIdsNameEveryConditionThatCanReferenceOne(): void {
		$nodes = NamedConditionCheck::conditionNodes(
			self::schema([]),
			[
				'x-openregister-lifecycle'   => ['transitions' => ['afhandelen' => ['condition' => ['$condition' => 'x']], 'heropenen' => []]],
				'x-openregister-validations' => ['spoedreden' => self::validation(['$condition' => 'y']), 'kapot' => 'not a declaration'],
			]
		);

		$this->assertCount(2, $nodes);
		$this->assertSame([['$condition' => 'x'], ['$condition' => 'y']], array_values($nodes));
	}//end testTheRuleIdsNameEveryConditionThatCanReferenceOne()
}//end class
