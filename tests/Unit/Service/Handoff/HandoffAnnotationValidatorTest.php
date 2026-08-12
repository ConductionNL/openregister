<?php

/**
 * Unit tests for {@see \OCA\OpenRegister\Service\Handoff\HandoffAnnotationValidator}.
 *
 * Accept/reject matrix for the `x-openregister-handoff` dialect, covering
 * the contract's typed error codes (`handoff-bad-target-type`,
 * `handoff-bad-mapping-expression`, `handoff-bad-success-update`) plus id
 * uniqueness, trigger/lifecycle validation, mandatory-field coverage, and
 * `whenUnavailable` mode validation.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Handoff
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
 * @spec openspec/changes/semantic-object-handoff-engine/specs/semantic-object-handoff/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\Handoff;

use OCA\OpenRegister\Service\Handoff\HandoffAnnotationValidator;
use PHPUnit\Framework\TestCase;

/**
 * HandoffAnnotationValidatorTest.
 */
class HandoffAnnotationValidatorTest extends TestCase {

	private const CASE_URI = 'https://openregister.app/ns#Case';

	private HandoffAnnotationValidator $validator;

	protected function setUp(): void {
		$this->validator = new HandoffAnnotationValidator();

	}//end setUp()

	/**
	 * A canonical valid declaration (design.md example shape) is accepted.
	 *
	 * @return void
	 */
	public function testValidDeclarationIsAccepted(): void {
		$errors = $this->validator->validate($this->shape());
		$this->assertSame([], $errors);

	}//end testValidDeclarationIsAccepted()

	/**
	 * Schemas without the annotation pass through untouched.
	 *
	 * @return void
	 */
	public function testAbsentAnnotationIsAccepted(): void {
		$this->assertSame([], $this->validator->validate(['properties' => []]));

	}//end testAbsentAnnotationIsAccepted()

	/**
	 * A relative / malformed target kind URI → handoff-bad-target-type.
	 *
	 * @return void
	 */
	public function testMalformedTargetTypeIsRejected(): void {
		$shape = $this->shape(overrides: ['targetSemanticType' => 'ns#Case']);
		$this->assertContains('handoff-bad-target-type', $this->codes($shape));

	}//end testMalformedTargetTypeIsRejected()

	/**
	 * An expression kind outside the allowed five → handoff-bad-mapping-expression.
	 *
	 * @return void
	 */
	public function testUnknownExpressionKindIsRejected(): void {
		$shape = $this->shape();
		$shape['x-openregister-handoff'][0]['mapping']['title'] = ['javascript' => 'evil()'];
		$this->assertContains('handoff-bad-mapping-expression', $this->codes($shape));

	}//end testUnknownExpressionKindIsRejected()

	/**
	 * onSuccess.set naming a property the source schema lacks → handoff-bad-success-update.
	 *
	 * @return void
	 */
	public function testOnSuccessUnknownPropertyIsRejected(): void {
		$shape = $this->shape();
		$shape['x-openregister-handoff'][0]['onSuccess'] = ['set' => ['nonexistent' => 'x']];
		$this->assertContains('handoff-bad-success-update', $this->codes($shape));

	}//end testOnSuccessUnknownPropertyIsRejected()

	/**
	 * Duplicate handoff ids are rejected.
	 *
	 * @return void
	 */
	public function testDuplicateIdsAreRejected(): void {
		$shape = $this->shape();
		$shape['x-openregister-handoff'][] = $shape['x-openregister-handoff'][0];
		$this->assertContains('handoff-duplicate-id', $this->codes($shape));

	}//end testDuplicateIdsAreRejected()

	/**
	 * Mapping keys outside the kind contract + unmapped mandatory fields.
	 *
	 * @return void
	 */
	public function testContractFieldCoverage(): void {
		// Unknown mapping field.
		$shape = $this->shape();
		$shape['x-openregister-handoff'][0]['mapping']['zaaknummer'] = ['const' => 'Z-1'];
		$this->assertContains('handoff-unknown-mapping-field', $this->codes($shape));

		// Missing mandatory field (drop `channel`).
		$shape = $this->shape();
		unset($shape['x-openregister-handoff'][0]['mapping']['channel']);
		$this->assertContains('handoff-missing-mandatory-field', $this->codes($shape));

	}//end testContractFieldCoverage()

	/**
	 * whenUnavailable outside {hide, queue} is rejected; both modes accepted.
	 *
	 * @return void
	 */
	public function testWhenUnavailableModes(): void {
		$shape = $this->shape(overrides: ['whenUnavailable' => 'explode']);
		$this->assertContains('handoff-bad-when-unavailable', $this->codes($shape));

		foreach (['hide', 'queue'] as $mode) {
			$shape = $this->shape(overrides: ['whenUnavailable' => $mode]);
			$this->assertSame([], $this->validator->validate($shape));
		}

	}//end testWhenUnavailableModes()

	/**
	 * Lifecycle triggers: valid state accepted; unknown state and missing
	 * lifecycle annotation rejected.
	 *
	 * @return void
	 */
	public function testLifecycleTriggerValidation(): void {
		// Valid: state exists in the lifecycle enum.
		$shape = $this->shape(overrides: ['trigger' => 'lifecycle:won'], withLifecycle: true);
		$this->assertSame([], $this->validator->validate($shape));

		// Unknown state.
		$shape = $this->shape(overrides: ['trigger' => 'lifecycle:vanished'], withLifecycle: true);
		$this->assertContains('handoff-bad-trigger', $this->codes($shape));

		// Lifecycle trigger without a declared lifecycle.
		$shape = $this->shape(overrides: ['trigger' => 'lifecycle:won'], withLifecycle: false);
		$this->assertContains('handoff-bad-trigger', $this->codes($shape));

		// Garbage trigger.
		$shape = $this->shape(overrides: ['trigger' => 'onSave']);
		$this->assertContains('handoff-bad-trigger', $this->codes($shape));

	}//end testLifecycleTriggerValidation()

	/**
	 * Non-slug ids are rejected.
	 *
	 * @return void
	 */
	public function testBadIdIsRejected(): void {
		$shape = $this->shape(overrides: ['id' => 'Request To Case!']);
		$this->assertContains('handoff-bad-id', $this->codes($shape));

	}//end testBadIdIsRejected()

	/**
	 * Build the canonical valid shape, with optional entry overrides.
	 *
	 * @param array<string, mixed> $overrides Entry-level overrides.
	 * @param bool $withLifecycle Include an x-openregister-lifecycle block.
	 *
	 * @return array<string, mixed>
	 */
	private function shape(array $overrides = [], bool $withLifecycle = false): array {
		$entry = array_merge(
			[
				'id' => 'request-to-case',
				'targetSemanticType' => self::CASE_URI,
				'trigger' => 'manual',
				'whenUnavailable' => 'hide',
				'mapping' => [
					'title' => ['from' => 'subject'],
					'summary' => ['template' => '{{subject}} — {{details}}'],
					'requester' => ['semanticRef' => 'client'],
					'channel' => ['from' => 'channel'],
					'priority' => [
						'from' => 'priority',
						'default' => 'normal',
					],
					'source' => ['provenance' => true],
				],
				'onSuccess' => ['set' => ['status' => 'handed-off']],
			],
			$overrides
		);

		$shape = [
			'properties' => [
				'subject' => ['type' => 'string'],
				'details' => ['type' => 'string'],
				'client' => ['type' => 'string'],
				'channel' => ['type' => 'string'],
				'priority' => ['type' => 'string'],
				'status' => [
					'type' => 'string',
					'enum' => ['new', 'won', 'handed-off'],
				],
			],
			'x-openregister-handoff' => [$entry],
		];

		if ($withLifecycle === true) {
			$shape['x-openregister-lifecycle'] = [
				'field' => 'status',
				'initial' => 'new',
				'transitions' => [],
			];
		}

		return $shape;
	}//end shape()

	/**
	 * The error codes produced for a shape.
	 *
	 * @param array<string, mixed> $shape The shape to validate.
	 *
	 * @return array<int, string>
	 */
	private function codes(array $shape): array {
		return array_map(
			static fn (array $error) => $error['code'],
			$this->validator->validate($shape)
		);

	}//end codes()
}//end class
