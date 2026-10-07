<?php

/**
 * The check an administrator wrote, and the sentence it says.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Rules
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/rules-compose-read-transitions-and-time/specs/object-lifecycle/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Rules;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Calculation\CalculationEvaluator;
use OCA\OpenRegister\Service\Rules\AdministeredValidations;
use OCA\OpenRegister\Service\Rules\ConditionDialect;
use OCA\OpenRegister\Service\Rules\NamedConditionEvaluator;
use OCA\OpenRegister\Service\Rules\NamedConditionLibrary;
use OCA\OpenRegister\Service\Rules\RuleVocabulary;
use PHPUnit\Framework\TestCase;

/**
 * Verifies REQ-RCT-004.
 */
class AdministeredValidationsTest extends TestCase {

	/**
	 * The subject under test.
	 *
	 * @var AdministeredValidations
	 */
	private AdministeredValidations $validations;

	/**
	 * Build the collaborators; none of them touches a database.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$library = new NamedConditionLibrary();
		$this->validations = new AdministeredValidations(
			evaluator: new NamedConditionEvaluator(
				dialect: new ConditionDialect(ast: $this->createMock(CalculationEvaluator::class)),
				library: $library
			)
		);
	}//end setUp()

	/**
	 * The spec's example: over 50,000 euro a mandate is required.
	 *
	 * @param string $severity The severity to declare.
	 *
	 * @return array<string, mixed> The annotation.
	 */
	private function mandaatVereist(string $severity = AdministeredValidations::REFUSE): array {
		return [
			'mandaat-boven-50k' => [
				'severity' => $severity,
				'properties' => ['mandaat'],
				'condition' => [
					'and' => [
						['>' => [['var' => 'bedrag'], 50000]],
						['==' => [['var' => 'mandaat'], '']],
					],
				],
				'message' => [
					'nl' => 'Boven 50.000 euro is een mandaat verplicht',
					'en' => 'Above 50,000 euro a mandate is required',
				],
			],
		];
	}//end mandaatVereist()

	/**
	 * 🔴 The handler reads the sentence somebody wrote.
	 *
	 * @return void
	 */
	public function testTheHandlerReadsTheSentenceSomebodyWrote(): void {
		$outcome = $this->validations->evaluate(
			annotation: $this->mandaatVereist(),
			document: ['bedrag' => 75000, 'mandaat' => '']
		);

		$this->assertCount(1, $outcome['refusals']);
		$this->assertSame(
			'Boven 50.000 euro is een mandaat verplicht',
			$outcome['refusals'][0]['message'],
			'verbatim: not prefixed, not summarised, not replaced by a generic sentence'
		);
		$this->assertSame(['mandaat'], $outcome['refusals'][0]['properties'], 'and it names the property it concerns');
		$this->assertSame([], $outcome['warnings']);
	}//end testTheHandlerReadsTheSentenceSomebodyWrote()

	/**
	 * The control: an object the check does not object to passes.
	 *
	 * Without it, every refusal here could be passing on a validator that
	 * refuses everything.
	 *
	 * @return void
	 */
	public function testAnObjectTheCheckDoesNotObjectToPasses(): void {
		$outcome = $this->validations->evaluate(
			annotation: $this->mandaatVereist(),
			document: ['bedrag' => 75000, 'mandaat' => 'B-2026-014']
		);

		$this->assertSame([], $outcome['refusals'], 'the control: a mandate is present, so nothing is refused');
		$this->assertSame([], $outcome['warnings']);
	}//end testAnObjectTheCheckDoesNotObjectToPasses()

	/**
	 * A warning does not block the work, and still carries its message.
	 *
	 * @return void
	 */
	public function testAWarningDoesNotBlockTheWork(): void {
		$outcome = $this->validations->evaluate(
			annotation: $this->mandaatVereist(severity: AdministeredValidations::WARN),
			document: ['bedrag' => 75000, 'mandaat' => '']
		);

		$this->assertSame([], $outcome['refusals'], 'a warning must not refuse');
		$this->assertCount(1, $outcome['warnings']);
		$this->assertSame('Boven 50.000 euro is een mandaat verplicht', $outcome['warnings'][0]['message']);
	}//end testAWarningDoesNotBlockTheWork()

	/**
	 * The message is translatable content, and the caller's language wins.
	 *
	 * @return void
	 */
	public function testTheMessageIsTranslatableContent(): void {
		$outcome = $this->validations->evaluate(
			annotation: $this->mandaatVereist(),
			document: ['bedrag' => 75000, 'mandaat' => ''],
			library: [],
			language: 'en'
		);

		$this->assertSame('Above 50,000 euro a mandate is required', $outcome['refusals'][0]['message']);
	}//end testTheMessageIsTranslatableContent()

	/**
	 * A regional tag falls back to its base language, not to Dutch.
	 *
	 * @return void
	 */
	public function testARegionalTagFallsBackToItsBaseLanguage(): void {
		$outcome = $this->validations->evaluate(
			annotation: $this->mandaatVereist(),
			document: ['bedrag' => 75000, 'mandaat' => ''],
			library: [],
			language: 'en_GB'
		);

		$this->assertSame(
			'Above 50,000 euro a mandate is required',
			$outcome['refusals'][0]['message'],
			'en_GB should read the English sentence, not the Dutch one'
		);
	}//end testARegionalTagFallsBackToItsBaseLanguage()

	/**
	 * A language nobody declared falls back rather than returning nothing.
	 *
	 * @return void
	 */
	public function testAnUndeclaredLanguageFallsBack(): void {
		$outcome = $this->validations->evaluate(
			annotation: $this->mandaatVereist(),
			document: ['bedrag' => 75000, 'mandaat' => ''],
			library: [],
			language: 'fr'
		);

		$this->assertSame(
			'Boven 50.000 euro is een mandaat verplicht',
			$outcome['refusals'][0]['message'],
			'a refusal with no sentence is the generic message wearing a different hat'
		);
	}//end testAnUndeclaredLanguageFallsBack()

	/**
	 * 🔴 A validation without a message is refused at save.
	 *
	 * @return void
	 */
	public function testAValidationWithoutAMessageIsRefusedAtSave(): void {
		$annotation = $this->mandaatVereist();
		unset($annotation['mandaat-boven-50k']['message']);

		$refusal = $this->validations->refusalFor(annotation: $annotation);

		$this->assertNotNull($refusal, 'a default message is how every validation ends up saying the same thing');
		$this->assertStringContainsString('mandaat-boven-50k', (string)$refusal, 'and the refusal names the validation');
	}//end testAValidationWithoutAMessageIsRefusedAtSave()

	/**
	 * An empty message is no message.
	 *
	 * @return void
	 */
	public function testAnEmptyMessageIsNoMessage(): void {
		$annotation = $this->mandaatVereist();
		$annotation['mandaat-boven-50k']['message'] = ['nl' => '   '];

		$this->assertNotNull($this->validations->refusalFor(annotation: $annotation));
	}//end testAnEmptyMessageIsNoMessage()

	/**
	 * A bare string message is accepted, as the fallback language.
	 *
	 * @return void
	 */
	public function testABareStringMessageIsAccepted(): void {
		$annotation = $this->mandaatVereist();
		$annotation['mandaat-boven-50k']['message'] = 'Mandaat verplicht';

		$this->assertNull(
			$this->validations->refusalFor(annotation: $annotation),
			'refusing the simple case would make the simple case the awkward one'
		);
		$outcome = $this->validations->evaluate(
			annotation: $annotation,
			document: ['bedrag' => 75000, 'mandaat' => '']
		);
		$this->assertSame('Mandaat verplicht', $outcome['refusals'][0]['message']);
	}//end testABareStringMessageIsAccepted()

	/**
	 * A validation naming a property the schema does not declare is refused.
	 *
	 * @return void
	 */
	public function testAValidationNamingAnUndeclaredPropertyIsRefused(): void {
		$refusal = $this->validations->refusalFor(
			annotation: $this->mandaatVereist(),
			declaredProperties: ['bedrag', 'omschrijving']
		);

		$this->assertNotNull($refusal);
		$this->assertStringContainsString('mandaat', (string)$refusal);
	}//end testAValidationNamingAnUndeclaredPropertyIsRefused()

	/**
	 * The control: with the property declared, it saves.
	 *
	 * @return void
	 */
	public function testWithThePropertyDeclaredItSaves(): void {
		$this->assertNull(
			$this->validations->refusalFor(
				annotation: $this->mandaatVereist(),
				declaredProperties: ['bedrag', 'mandaat']
			)
		);
	}//end testWithThePropertyDeclaredItSaves()

	/**
	 * An unknown severity is refused, naming the ones that exist.
	 *
	 * @return void
	 */
	public function testAnUnknownSeverityIsRefused(): void {
		$refusal = $this->validations->refusalFor(annotation: $this->mandaatVereist(severity: 'maybe'));

		$this->assertNotNull($refusal);
		$this->assertStringContainsString('maybe', (string)$refusal);
	}//end testAnUnknownSeverityIsRefused()

	/**
	 * A validation declaring no condition is refused.
	 *
	 * @return void
	 */
	public function testAValidationWithNoConditionIsRefused(): void {
		$annotation = $this->mandaatVereist();
		unset($annotation['mandaat-boven-50k']['condition']);

		$this->assertNotNull($this->validations->refusalFor(annotation: $annotation));
	}//end testAValidationWithNoConditionIsRefused()

	/**
	 * A validation may use a named condition, so one correction reaches it too.
	 *
	 * @return void
	 */
	public function testAValidationMayUseANamedCondition(): void {
		$annotation = [
			'mandaat-vereist' => [
				'severity' => AdministeredValidations::REFUSE,
				'properties' => ['mandaat'],
				'condition' => [NamedConditionLibrary::REF => 'groot-bedrag-zonder-mandaat'],
				'message' => 'Mandaat verplicht',
			],
		];

		$library = (new NamedConditionLibrary())->libraryFrom(
			annotation: [
				'groot-bedrag-zonder-mandaat' => [
					'expression' => [
						'and' => [
							['>' => [['var' => 'bedrag'], 50000]],
							['==' => [['var' => 'mandaat'], '']],
						],
					],
				],
			]
		);

		$outcome = $this->validations->evaluate(
			annotation: $annotation,
			document: ['bedrag' => 75000, 'mandaat' => ''],
			library: $library
		);

		$this->assertCount(1, $outcome['refusals']);
	}//end testAValidationMayUseANamedCondition()

	/**
	 * 🔴 An unevaluable condition REFUSES the save, whatever its severity.
	 *
	 * A check that could not be asked has not been passed, and a `warn` that
	 * quietly becomes "fine" is how a broken named condition switches off a
	 * mandatory control.
	 *
	 * @return void
	 */
	public function testAnUnevaluableConditionRefusesEvenWhenDeclaredAsAWarning(): void {
		$annotation = [
			'mandaat-vereist' => [
				'severity' => AdministeredValidations::WARN,
				'properties' => ['mandaat'],
				'condition' => [NamedConditionLibrary::REF => 'weggevallen'],
				'message' => 'Mandaat verplicht',
			],
		];

		$outcome = $this->validations->evaluate(
			annotation: $annotation,
			document: ['bedrag' => 75000, 'mandaat' => ''],
			library: []
		);

		$this->assertCount(1, $outcome['refusals'], 'a check nobody could ask is not a check that passed');
		$this->assertTrue($outcome['refusals'][0]['unevaluable']);
		$this->assertSame([], $outcome['warnings']);
	}//end testAnUnevaluableConditionRefusesEvenWhenDeclaredAsAWarning()

	/**
	 * Several validations are all reported, not only the first.
	 *
	 * A form that can show three problems at once should not make somebody
	 * save three times to find them.
	 *
	 * @return void
	 */
	public function testSeveralValidationsAreAllReported(): void {
		$annotation = $this->mandaatVereist();
		$annotation['omschrijving-verplicht'] = [
			'severity' => AdministeredValidations::REFUSE,
			'properties' => ['omschrijving'],
			'condition' => ['==' => [['var' => 'omschrijving'], '']],
			'message' => 'Een omschrijving is verplicht',
		];

		$outcome = $this->validations->evaluate(
			annotation: $annotation,
			document: ['bedrag' => 75000, 'mandaat' => '', 'omschrijving' => '']
		);

		$this->assertCount(2, $outcome['refusals']);
	}//end testSeveralValidationsAreAllReported()

	/**
	 * 🔴 The annotation is in the schema vocabulary, or the check is dropped.
	 *
	 * @return void
	 */
	public function testTheAnnotationIsInTheSchemaVocabulary(): void {
		$this->assertContains(
			AdministeredValidations::ANNOTATION,
			Schema::ANNOTATION_VOCABULARY,
			'absent from the vocabulary, setConfiguration() drops the checks and every violating object saves happily'
		);
	}//end testTheAnnotationIsInTheSchemaVocabulary()

	/**
	 * The validation appears in the rule vocabulary, so the inventory lists it.
	 *
	 * @return void
	 */
	public function testTheValidationIsAKindTheInventoryKnows(): void {
		$this->assertArrayHasKey(RuleVocabulary::KIND_ADMINISTERED_VALIDATION, RuleVocabulary::KINDS);
		$this->assertSame(
			AdministeredValidations::ANNOTATION,
			RuleVocabulary::KINDS[RuleVocabulary::KIND_ADMINISTERED_VALIDATION]['source'],
			'the inventory must read the checks from where they are actually declared'
		);
		$this->assertLessThan(
			RuleVocabulary::KINDS[RuleVocabulary::KIND_FLOW]['order'],
			RuleVocabulary::KINDS[RuleVocabulary::KIND_ADMINISTERED_VALIDATION]['order'],
			'a validation refuses BEFORE the object is stored; a flow runs after it is'
		);
	}//end testTheValidationIsAKindTheInventoryKnows()
}//end class
