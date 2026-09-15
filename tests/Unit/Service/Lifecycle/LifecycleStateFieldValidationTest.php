<?php

/**
 * Schema-save validation of `x-openregister-lifecycle.states`.
 *
 * A field rule that matches nothing reads as enforced and is not, so each of
 * these is REFUSED at schema save rather than warned about:
 *  - a `fields` entry naming a property the schema does not declare;
 *  - a state the lifecycle's own enum does not declare;
 *  - a condition reading a property the schema does not declare;
 *  - a transition `inputs` entry asking for a field the target state hides.
 *
 * The last one is a contradiction between two declarations that only schema
 * save has both halves of: at run time the form asks for the value and the
 * render strips it back out, and nothing anywhere reports a problem.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Lifecycle
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/field-rules-by-state/specs/object-lifecycle/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\Lifecycle;

use OCA\OpenRegister\Service\Lifecycle\LifecycleAnnotationValidator;
use PHPUnit\Framework\TestCase;

/**
 * @coversDefaultClass \OCA\OpenRegister\Service\Lifecycle\LifecycleAnnotationValidator
 */
class LifecycleStateFieldValidationTest extends TestCase {

	private LifecycleAnnotationValidator $validator;

	/**
	 * @return void
	 */
	protected function setUp(): void {
		$this->validator = new LifecycleAnnotationValidator();
	}

	/**
	 * A schema whose lifecycle is valid, with the states block substituted in.
	 *
	 * @param array<string, mixed> $states The states block under test.
	 * @param array<string, mixed> $transitions Transitions to override the default with.
	 * @param array<string, mixed> $properties Extra properties beyond the defaults.
	 *
	 * @return array<string, mixed> The schema definition.
	 */
	private function schema(array $states, array $transitions = [], array $properties = []): array {
		if ($transitions === []) {
			$transitions = ['sluiten' => ['from' => ['open'], 'to' => 'closed']];
		}

		return [
			'properties' => array_merge(
				[
					'status' => ['type' => 'string', 'enum' => ['open', 'closed']],
					'decision' => ['type' => 'string'],
					'bedrag' => ['type' => 'number'],
				],
				$properties
			),
			'x-openregister-lifecycle' => [
				'field' => 'status',
				'initial' => 'open',
				'transitions' => $transitions,
				'states' => $states,
			],
		];
	}

	/**
	 * Every error code the validator reported.
	 *
	 * @param array<int, array{code: string, message: string}> $errors The errors.
	 *
	 * @return array<int, string> The codes.
	 */
	private function codes(array $errors): array {
		return array_map(static fn (array $error): string => $error['code'], $errors);
	}

	/**
	 * The message of the first error carrying a code.
	 *
	 * @param array<int, array{code: string, message: string}> $errors The errors.
	 * @param string $code The code to find.
	 *
	 * @return string The message.
	 */
	private function messageOf(array $errors, string $code): string {
		foreach ($errors as $error) {
			if ($error['code'] === $code) {
				return $error['message'];
			}
		}

		return '';
	}

	/**
	 * @return void
	 */
	public function testAnUnknownFieldIsRefusedAtSchemaSave(): void {
		$errors = $this->validator->validate(
			$this->schema(['open' => ['fields' => ['required' => [['fields' => ['outcome']]]]]])
		);

		$this->assertContains('lifecycle-state-field-missing', $this->codes($errors));
		$message = $this->messageOf($errors, 'lifecycle-state-field-missing');
		$this->assertStringContainsString('outcome', $message);
		$this->assertStringContainsString('open', $message);
	}

	/**
	 * @return void
	 */
	public function testADeclaredFieldIsAccepted(): void {
		$errors = $this->validator->validate(
			$this->schema(['open' => ['fields' => ['readOnly' => [['fields' => ['decision']]]]]])
		);

		$this->assertSame([], $errors);
	}

	/**
	 * @return void
	 */
	public function testAStateOutsideTheEnumIsRefused(): void {
		$errors = $this->validator->validate(
			$this->schema(['afgehandeld' => ['fields' => ['required' => [['fields' => ['decision']]]]]])
		);

		$this->assertContains('lifecycle-state-unknown', $this->codes($errors));
		$this->assertStringContainsString('afgehandeld', $this->messageOf($errors, 'lifecycle-state-unknown'));
	}

	/**
	 * @return void
	 */
	public function testAConditionOnAnUndeclaredPropertyIsRefused(): void {
		$errors = $this->validator->validate(
			$this->schema([
				'closed' => ['exit' => ['!!' => ['var' => 'object.heropeningsgrond']]],
			])
		);

		$this->assertContains('lifecycle-state-condition-property-missing', $this->codes($errors));
		$this->assertStringContainsString(
			'heropeningsgrond',
			$this->messageOf($errors, 'lifecycle-state-condition-property-missing')
		);
	}

	/**
	 * @return void
	 */
	public function testAConditionOnADeclaredPropertyIsAccepted(): void {
		$errors = $this->validator->validate(
			$this->schema([
				'closed' => [
					'entry' => ['>' => [['var' => 'object.bedrag'], 50000]],
				],
			])
		);

		$this->assertSame([], $errors);
	}

	/**
	 * A reference into the document's other branches names no property.
	 *
	 * @return void
	 */
	public function testABuiltInReferenceIsNotReportedAsAMissingProperty(): void {
		$errors = $this->validator->validate(
			$this->schema([
				'closed' => [
					'entry' => ['in' => [['var' => 'user.groups'], ['behandelaars']]],
				],
			])
		);

		$this->assertSame([], $errors);
	}

	/**
	 * @return void
	 */
	public function testAFieldRuleConditionIsCheckedToo(): void {
		$errors = $this->validator->validate(
			$this->schema([
				'open' => [
					'fields' => [
						'required' => [
							['fields' => ['decision'], 'when' => ['>' => [['var' => 'object.somBedrag'], 1]]],
						],
					],
				],
			])
		);

		$this->assertContains('lifecycle-state-condition-property-missing', $this->codes($errors));
		$this->assertStringContainsString(
			'somBedrag',
			$this->messageOf($errors, 'lifecycle-state-condition-property-missing')
		);
	}

	/**
	 * @return void
	 */
	public function testATransitionMayNotAskForAFieldTheTargetStateHides(): void {
		$errors = $this->validator->validate(
			$this->schema(
				['closed' => ['fields' => ['hidden' => [['fields' => ['decision']]]]]],
				['sluiten' => ['from' => ['open'], 'to' => 'closed', 'inputs' => [['field' => 'decision', 'required' => true]]]]
			)
		);

		$this->assertContains('lifecycle-input-hidden-in-target', $this->codes($errors));
		$message = $this->messageOf($errors, 'lifecycle-input-hidden-in-target');
		$this->assertStringContainsString('decision', $message);
		$this->assertStringContainsString('closed', $message);
	}

	/**
	 * @return void
	 */
	public function testATransitionMayAskForAFieldTheTargetStateOnlyFreezes(): void {
		$errors = $this->validator->validate(
			$this->schema(
				['closed' => ['fields' => ['readOnly' => [['fields' => ['decision']]]]]],
				['sluiten' => ['from' => ['open'], 'to' => 'closed', 'inputs' => [['field' => 'decision', 'required' => true]]]]
			)
		);

		$this->assertSame([], $errors);
	}

	/**
	 * @return void
	 */
	public function testAnUnknownRuleKindIsRefused(): void {
		$errors = $this->validator->validate(
			$this->schema(['open' => ['fields' => ['mandatory' => [['fields' => ['decision']]]]]])
		);

		$this->assertContains('lifecycle-state-fields-unknown-kind', $this->codes($errors));
	}

	/**
	 * @return void
	 */
	public function testAScalarConditionIsRefusedRatherThanReadAsALiteral(): void {
		$errors = $this->validator->validate(
			$this->schema(['closed' => ['entry' => 'bedrag > 50000']])
		);

		$this->assertContains('lifecycle-state-condition-malformed', $this->codes($errors));
	}

	/**
	 * @return void
	 */
	public function testAMalformedGroupsClauseIsRefused(): void {
		$errors = $this->validator->validate(
			$this->schema(['open' => ['fields' => ['hidden' => [['fields' => ['decision'], 'groups' => [17]]]]]])
		);

		$this->assertContains('lifecycle-state-field-groups-malformed', $this->codes($errors));
	}

	/**
	 * A lifecycle with no `states` block is exactly as valid as it was before.
	 *
	 * @return void
	 */
	public function testALifecycleWithoutStatesIsUnaffected(): void {
		$schema = $this->schema([]);
		unset($schema['x-openregister-lifecycle']['states']);

		$this->assertSame([], $this->validator->validate($schema));
	}
}
