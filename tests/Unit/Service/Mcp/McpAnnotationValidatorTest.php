<?php

/**
 * Unit tests for {@see \OCA\OpenRegister\Service\Mcp\McpAnnotationValidator}.
 *
 * Accept/reject matrix for the `x-openregister-mcp` dialect: valid full
 * block acceptance, `enabled` type/presence, unknown verb rejection,
 * per-verb shape (`description`/`scope`/hints), `filters` cross-referencing
 * real schema properties and being restricted to `search`, and the
 * default-OFF / opt-out round-trip.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Mcp
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/or-mcp-schema-dialect/specs/ai-mcp/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Mcp;

use OCA\OpenRegister\Service\Mcp\McpAnnotationValidator;
use PHPUnit\Framework\TestCase;

/**
 * McpAnnotationValidatorTest.
 */
class McpAnnotationValidatorTest extends TestCase {

	private McpAnnotationValidator $validator;

	protected function setUp(): void {
		$this->validator = new McpAnnotationValidator();

	}//end setUp()

	/**
	 * A canonical, fully-populated valid declaration is accepted.
	 *
	 * @return void
	 */
	public function testValidFullBlockIsAccepted(): void {
		$errors = $this->validator->validate($this->shape());
		$this->assertSame([], $errors);

	}//end testValidFullBlockIsAccepted()

	/**
	 * Schemas without the annotation pass through untouched.
	 *
	 * @return void
	 */
	public function testAbsentAnnotationIsAccepted(): void {
		$this->assertSame([], $this->validator->validate(['properties' => []]));

	}//end testAbsentAnnotationIsAccepted()

	/**
	 * `enabled:false` is a valid, explicit opt-out.
	 *
	 * @return void
	 */
	public function testEnabledFalseIsAccepted(): void {
		$errors = $this->validator->validate(
			[
				'properties' => [],
				'x-openregister-mcp' => ['enabled' => false],
			]
		);
		$this->assertSame([], $errors);

	}//end testEnabledFalseIsAccepted()

	/**
	 * `enabled` missing or non-boolean is rejected.
	 *
	 * @return void
	 */
	public function testEnabledMustBeBoolean(): void {
		$missing = $this->validator->validate(
			[
				'properties' => [],
				'x-openregister-mcp' => [],
			]
		);
		$this->assertContains('mcp-missing-enabled', $this->codesOf($missing));

		$wrongType = $this->validator->validate(
			[
				'properties' => [],
				'x-openregister-mcp' => ['enabled' => 'yes'],
			]
		);
		$this->assertContains('mcp-bad-enabled', $this->codesOf($wrongType));

	}//end testEnabledMustBeBoolean()

	/**
	 * The annotation itself must be an object.
	 *
	 * @return void
	 */
	public function testAnnotationMustBeArray(): void {
		$errors = $this->validator->validate(
			[
				'properties' => [],
				'x-openregister-mcp' => 'enabled',
			]
		);
		$this->assertContains('mcp-bad-annotation', $this->codesOf($errors));

	}//end testAnnotationMustBeArray()

	/**
	 * A `tools` key outside the fixed five-verb set is rejected.
	 *
	 * @return void
	 */
	public function testUnknownVerbIsRejected(): void {
		$shape = $this->shape();
		$shape['x-openregister-mcp']['tools']['list'] = ['description' => 'List everything'];
		$this->assertContains('mcp-unknown-verb', $this->codes($shape));

	}//end testUnknownVerbIsRejected()

	/**
	 * `tools` itself must be an object; a verb config must be an object.
	 *
	 * @return void
	 */
	public function testToolsAndVerbConfigMustBeObjects(): void {
		$badTools = $this->validator->validate(
			[
				'properties' => [],
				'x-openregister-mcp' => ['enabled' => true, 'tools' => 'search'],
			]
		);
		$this->assertContains('mcp-bad-tools', $this->codesOf($badTools));

		$badVerbConfig = $this->validator->validate(
			[
				'properties' => [],
				'x-openregister-mcp' => ['enabled' => true, 'tools' => ['search' => 'yes please']],
			]
		);
		$this->assertContains('mcp-bad-verb-config', $this->codesOf($badVerbConfig));

	}//end testToolsAndVerbConfigMustBeObjects()

	/**
	 * `description` must be a string.
	 *
	 * @return void
	 */
	public function testDescriptionMustBeString(): void {
		$shape = $this->shape();
		$shape['x-openregister-mcp']['tools']['get']['description'] = 123;
		$this->assertContains('mcp-bad-description', $this->codes($shape));

	}//end testDescriptionMustBeString()

	/**
	 * `scope` must be one of the known enum values.
	 *
	 * @return void
	 */
	public function testScopeMustBeKnownEnumValue(): void {
		$shape = $this->shape();
		$shape['x-openregister-mcp']['tools']['delete']['scope'] = 'admin';
		$this->assertContains('mcp-bad-scope', $this->codes($shape));

	}//end testScopeMustBeKnownEnumValue()

	/**
	 * The three MCP hints must be booleans; a valid boolean value (including
	 * `false`) is accepted without treating it as a security decision.
	 *
	 * @return void
	 */
	public function testHintsMustBeBoolean(): void {
		$shape = $this->shape();
		$shape['x-openregister-mcp']['tools']['delete']['destructiveHint'] = 'true';
		$this->assertContains('mcp-bad-hint', $this->codes($shape));

		$valid = $this->shape();
		$valid['x-openregister-mcp']['tools']['delete']['destructiveHint'] = false;
		$this->assertSame([], $this->validator->validate($valid));

	}//end testHintsMustBeBoolean()

	/**
	 * An unrecognised key inside a verb config is reported (typo-safety).
	 *
	 * @return void
	 */
	public function testUnknownVerbConfigKeyIsRejected(): void {
		$shape = $this->shape();
		$shape['x-openregister-mcp']['tools']['get']['descripton'] = 'typo';
		$this->assertContains('mcp-unknown-key', $this->codes($shape));

	}//end testUnknownVerbConfigKeyIsRejected()

	/**
	 * `filters` must reference an existing schema property.
	 *
	 * @return void
	 */
	public function testFiltersMustReferenceExistingProperty(): void {
		$shape = $this->shape();
		$shape['x-openregister-mcp']['tools']['search']['filters'][] = 'nonexistentProperty';
		$this->assertContains('mcp-unknown-filter-property', $this->codes($shape));

	}//end testFiltersMustReferenceExistingProperty()

	/**
	 * `filters` is permitted on `search` only.
	 *
	 * @return void
	 */
	public function testFiltersOnlyPermittedOnSearch(): void {
		$shape = $this->shape();
		$shape['x-openregister-mcp']['tools']['create']['filters'] = ['status'];
		$this->assertContains('mcp-filters-not-search', $this->codes($shape));

	}//end testFiltersOnlyPermittedOnSearch()

	/**
	 * `filters` entries must each be a string.
	 *
	 * @return void
	 */
	public function testFiltersMustBeListOfStrings(): void {
		$shape = $this->shape();
		$shape['x-openregister-mcp']['tools']['search']['filters'] = ['status', 42];
		$this->assertContains('mcp-bad-filters', $this->codes($shape));

		$shape2 = $this->shape();
		$shape2['x-openregister-mcp']['tools']['search']['filters'] = 'status';
		$this->assertContains('mcp-bad-filters', $this->codes($shape2));

	}//end testFiltersMustBeListOfStrings()

	/**
	 * A well-formed full block round-trips unchanged: validating the exact
	 * shape twice yields the same (empty) error list — the annotation is
	 * pure data, the validator never mutates it.
	 *
	 * @return void
	 */
	public function testValidBlockRoundTrips(): void {
		$shape = $this->shape();
		$before = $shape['x-openregister-mcp'];

		$this->assertSame([], $this->validator->validate($shape));
		$this->assertSame($before, $shape['x-openregister-mcp']);

	}//end testValidBlockRoundTrips()

	/**
	 * Build the canonical valid shape (design.md example): `enabled:true`,
	 * all five verbs configured, `search.filters` referencing real
	 * properties.
	 *
	 * @return array<string, mixed>
	 */
	private function shape(): array {
		return [
			'properties' => [
				'status' => ['type' => 'string'],
				'assignee' => ['type' => 'string'],
				'createdAt' => ['type' => 'string', 'format' => 'date-time'],
			],
			'x-openregister-mcp' => [
				'enabled' => true,
				'tools' => [
					'search' => [
						'description' => 'Search cases by status, assignee and free text.',
						'filters' => ['status', 'createdAt'],
						'scope' => 'read',
						'readOnlyHint' => true,
						'destructiveHint' => false,
						'idempotentHint' => true,
					],
					'get' => [
						'description' => 'Get a case by id.',
						'scope' => 'read',
						'readOnlyHint' => true,
					],
					'create' => [
						'description' => 'Create a case.',
						'scope' => 'create',
						'destructiveHint' => false,
						'idempotentHint' => false,
					],
					'update' => [
						'description' => 'Update a case.',
						'scope' => 'update',
						'destructiveHint' => false,
						'idempotentHint' => true,
					],
					'delete' => [
						'description' => 'Delete a case.',
						'scope' => 'delete',
						'destructiveHint' => true,
						'idempotentHint' => true,
					],
				],
			],
		];

	}//end shape()

	/**
	 * Validate a shape and return only the error codes (for assertContains).
	 *
	 * @param array<string, mixed> $shape The shape to validate.
	 *
	 * @return array<int, string>
	 */
	private function codes(array $shape): array {
		return $this->codesOf($this->validator->validate($shape));
	}//end codes()

	/**
	 * Extract error codes from a validate() error list.
	 *
	 * @param array<int, array{code: string, message: string}> $errors The error list.
	 *
	 * @return array<int, string>
	 */
	private function codesOf(array $errors): array {
		return array_map(static fn (array $err) => $err['code'], $errors);
	}//end codesOf()
}//end class
