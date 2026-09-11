<?php

/**
 * Save-time shape checks for `autoWhen` and `executionMode`.
 *
 * A transition may declare `autoWhen`, a JSONLogic rule object that makes the
 * transition fire on its own after a write, and `executionMode`, one of the
 * flow engine's two values. Both are shape-checked here, and all four codes
 * this file exercises refuse the schema save rather than warn.
 *
 * The scalar case is the one worth staring at. A scalar handed to
 * `FlowExpression::isValid()` is a literal and always valid, so nothing below
 * this method would catch it, and a truthy literal does not fail open once: it
 * fires the transition on every write from its `from` state.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Lifecycle
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/lifecycle-auto-transitions/specs/object-lifecycle/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\Lifecycle;

use OCA\OpenRegister\Service\Lifecycle\LifecycleAnnotationValidator;
use PHPUnit\Framework\TestCase;

/**
 * Validation of the automatic-transition keys.
 */
class LifecycleAutoWhenValidationTest extends TestCase {

	private LifecycleAnnotationValidator $validator;

	/**
	 * @return void
	 */
	protected function setUp(): void {
		$this->validator = new LifecycleAnnotationValidator();
	}//end setUp()

	/**
	 * Validate a static lifecycle whose `beslissen` transition carries extras.
	 *
	 * @param array<string, mixed> $extra Extra keys on the transition.
	 *
	 * @return array<int, string> The error codes returned.
	 */
	private function codesFor(array $extra): array {
		$errors = $this->validator->validate(
			[
				'x-openregister-lifecycle' => [
					'field' => 'status',
					'initial' => 'open',
					'transitions' => [
						'beslissen' => (['from' => ['open'], 'to' => 'besloten'] + $extra),
					],
				],
				'properties' => [
					'status' => ['type' => 'string', 'enum' => ['open', 'besloten']],
					'motivering' => ['type' => 'string'],
				],
			]
		);

		return array_column($errors, 'code');
	}//end codesFor()

	/**
	 * Read the message of the first error carrying a code.
	 *
	 * @param array<string, mixed> $extra Extra keys on the transition.
	 * @param string $code The code to look for.
	 *
	 * @return string The message, or an empty string when the code is absent.
	 */
	private function messageFor(array $extra, string $code): string {
		$errors = $this->validator->validate(
			[
				'x-openregister-lifecycle' => [
					'field' => 'status',
					'initial' => 'open',
					'transitions' => [
						'beslissen' => (['from' => ['open'], 'to' => 'besloten'] + $extra),
					],
				],
				'properties' => [
					'status' => ['type' => 'string', 'enum' => ['open', 'besloten']],
					'motivering' => ['type' => 'string'],
				],
			]
		);

		foreach ($errors as $error) {
			if ($error['code'] === $code) {
				return $error['message'];
			}
		}

		return '';
	}//end messageFor()

	/**
	 * @return void
	 */
	public function testABooleanAutoWhenIsRefused(): void {
		// 🔴 `true` is the whole reason this check exists: FlowExpression calls
		// it valid, and stored it fires the transition on every write.
		$this->assertContains('lifecycle-autowhen-malformed', $this->codesFor(['autoWhen' => true]));
	}//end testABooleanAutoWhenIsRefused()

	/**
	 * @return void
	 */
	public function testAnActionDialectStringAutoWhenIsRefused(): void {
		$codes = $this->codesFor(['autoWhen' => "@self.motivering != ''"]);
		$this->assertContains('lifecycle-autowhen-malformed', $codes);

		$message = $this->messageFor(['autoWhen' => "@self.motivering != ''"], 'lifecycle-autowhen-malformed');
		$this->assertStringContainsString('JSONLogic rule object', $message);
		$this->assertStringContainsString('"var"', $message);
	}//end testAnActionDialectStringAutoWhenIsRefused()

	/**
	 * @return void
	 */
	public function testAnEmptyAutoWhenIsRefused(): void {
		$this->assertContains('lifecycle-autowhen-malformed', $this->codesFor(['autoWhen' => []]));
	}//end testAnEmptyAutoWhenIsRefused()

	/**
	 * @return void
	 */
	public function testAnUnknownOperatorIsRefused(): void {
		$this->assertContains(
			'lifecycle-autowhen-malformed',
			$this->codesFor(['autoWhen' => ['nosuchoperator' => [1]]])
		);
	}//end testAnUnknownOperatorIsRefused()

	/**
	 * @return void
	 */
	public function testAValidRuleObjectProducesNoError(): void {
		$this->assertSame([], $this->codesFor(['autoWhen' => ['!!' => ['var' => 'object.motivering']]]));
	}//end testAValidRuleObjectProducesNoError()

	/**
	 * @return void
	 */
	public function testAnUnknownExecutionModeIsRefused(): void {
		$codes = $this->codesFor(
			['autoWhen' => ['!!' => ['var' => 'object.motivering']], 'executionMode' => 'background']
		);
		$this->assertContains('lifecycle-execution-mode-malformed', $codes);
	}//end testAnUnknownExecutionModeIsRefused()

	/**
	 * @return void
	 */
	public function testACaseVariantExecutionModeIsRefusedNotNormalised(): void {
		$codes = $this->codesFor(
			['autoWhen' => ['!!' => ['var' => 'object.motivering']], 'executionMode' => 'SYNC']
		);
		$this->assertContains('lifecycle-execution-mode-malformed', $codes);
	}//end testACaseVariantExecutionModeIsRefusedNotNormalised()

	/**
	 * @return void
	 */
	public function testBothFlowEngineValuesAreAccepted(): void {
		$rule = ['!!' => ['var' => 'object.motivering']];
		$this->assertSame([], $this->codesFor(['autoWhen' => $rule, 'executionMode' => 'sync']));
		$this->assertSame([], $this->codesFor(['autoWhen' => $rule, 'executionMode' => 'async']));
	}//end testBothFlowEngineValuesAreAccepted()

	/**
	 * @return void
	 */
	public function testARequiredInputBesideAutoWhenIsRefused(): void {
		$codes = $this->codesFor(
			[
				'autoWhen' => ['!!' => ['var' => 'object.motivering']],
				'inputs' => [['field' => 'motivering', 'required' => true]],
			]
		);
		$this->assertContains('lifecycle-autowhen-requires-input', $codes);
	}//end testARequiredInputBesideAutoWhenIsRefused()

	/**
	 * @return void
	 */
	public function testAnOptionalInputBesideAutoWhenIsAccepted(): void {
		$this->assertSame(
			[],
			$this->codesFor(
				[
					'autoWhen' => ['!!' => ['var' => 'object.motivering']],
					'inputs' => [['field' => 'motivering', 'required' => false]],
				]
			)
		);
	}//end testAnOptionalInputBesideAutoWhenIsAccepted()

	/**
	 * @return void
	 */
	public function testARequiredInputWithoutAutoWhenIsUnaffected(): void {
		$this->assertSame(
			[],
			$this->codesFor(['inputs' => [['field' => 'motivering', 'required' => true]]])
		);
	}//end testARequiredInputWithoutAutoWhenIsUnaffected()

	/**
	 * @return void
	 */
	public function testAutoWhenOnAGraphBlockIsRefused(): void {
		$errors = $this->validator->validate(
			[
				'x-openregister-lifecycle' => [
					'field' => 'fase',
					'graph' => [
						'schema' => 'fase',
						'parentField' => 'zaaktype',
						'parentFrom' => 'zaaktype',
						'orderField' => 'volgorde',
						'finalField' => 'eindfase',
						'allowedMoves' => 'forward',
						'autoWhen' => ['!!' => ['var' => 'object.motivering']],
					],
				],
				'properties' => ['fase' => ['type' => 'string']],
			]
		);

		$this->assertContains('lifecycle-autowhen-graph-unsupported', array_column($errors, 'code'));
	}//end testAutoWhenOnAGraphBlockIsRefused()

	/**
	 * @return void
	 */
	public function testAGraphBlockWithoutAutoWhenValidatesAsBefore(): void {
		$errors = $this->validator->validate(
			[
				'x-openregister-lifecycle' => [
					'field' => 'fase',
					'graph' => [
						'schema' => 'fase',
						'parentField' => 'zaaktype',
						'parentFrom' => 'zaaktype',
						'orderField' => 'volgorde',
						'finalField' => 'eindfase',
						'allowedMoves' => 'forward',
					],
				],
				'properties' => ['fase' => ['type' => 'string']],
			]
		);

		$this->assertSame([], $errors);
	}//end testAGraphBlockWithoutAutoWhenValidatesAsBefore()
}//end class
