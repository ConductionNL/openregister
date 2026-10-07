<?php

/**
 * A hierarchy declaration is refused before it can grant anything (REQ-RIC-001).
 *
 * The annotation names the edge a GRANT travels down, which is why this
 * validator throws where most of its neighbours warn. The case that matters is
 * the second one below: `assignee` on a case references a USER, so a hierarchy
 * declared over it would hand everybody who may read one object every object
 * filed to the same person, and from that moment it looks exactly like working
 * inheritance. The save is the last point at which the two can be told apart.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/rbac-inherits-to-children/specs/rbac-scopes/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\Rbac;

use OCA\OpenRegister\Service\Rbac\HierarchyAnnotationValidator;
use OCA\OpenRegister\Service\Rbac\HierarchyGrantExpander;
use PHPUnit\Framework\TestCase;

/**
 * Pins what a hierarchy declaration may and may not say.
 */
class HierarchyAnnotationValidatorTest extends TestCase {

	private HierarchyAnnotationValidator $validator;

	/**
	 * Set up the validator.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->validator = new HierarchyAnnotationValidator();
	}//end setUp()

	/**
	 * A `case` schema shape.
	 *
	 * @param array<string, mixed>|null $annotation The hierarchy block.
	 *
	 * @return array<string, mixed> The shape.
	 */
	private function caseSchema(?array $annotation): array {
		$shape = [
			'slug' => 'case',
			'properties' => [
				'parentCase' => ['type' => 'string', '$ref' => 'case'],
				'assignee' => ['type' => 'string', '$ref' => 'user'],
				'title' => ['type' => 'string'],
			],
		];

		if ($annotation !== null) {
			$shape[HierarchyGrantExpander::ANNOTATION] = $annotation;
		}

		return $shape;
	}//end caseSchema()

	/**
	 * The fatal findings of one validation.
	 *
	 * @param array<string, mixed>|null $annotation The block.
	 *
	 * @return array<int, array<string, string>> The errors.
	 */
	private function errors(?array $annotation): array {
		return HierarchyAnnotationValidator::partition(
			findings: $this->validator->validate($this->caseSchema($annotation))
		)['errors'];
	}//end errors()

	/**
	 * A valid declaration is accepted.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/rbac-inherits-to-children/specs/rbac-scopes/spec.md
	 */
	public function testAValidDeclarationIsAccepted(): void {
		$this->assertSame(
			[],
			$this->errors(['parent' => 'parentCase', 'maxDepth' => 5])
		);
	}//end testAValidDeclarationIsAccepted()

	/**
	 * 🔴 A parent property that points at another schema is refused.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/rbac-inherits-to-children/specs/rbac-scopes/spec.md
	 */
	public function testAParentPointingElsewhereIsRefused(): void {
		$errors = $this->errors(['parent' => 'assignee']);

		$this->assertCount(1, $errors);
		$this->assertSame('hierarchy.foreign-reference', $errors[0]['code']);
		$this->assertStringContainsString('assignee', $errors[0]['message']);
		$this->assertStringContainsString('user', $errors[0]['message']);
	}//end testAParentPointingElsewhereIsRefused()

	/**
	 * A property that is not a reference at all is refused.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/rbac-inherits-to-children/specs/rbac-scopes/spec.md
	 */
	public function testANonReferencePropertyIsRefused(): void {
		$errors = $this->errors(['parent' => 'title']);

		$this->assertCount(1, $errors);
		$this->assertSame('hierarchy.not-a-reference', $errors[0]['code']);
	}//end testANonReferencePropertyIsRefused()

	/**
	 * A property the schema does not declare is refused.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/rbac-inherits-to-children/specs/rbac-scopes/spec.md
	 */
	public function testAnUnknownPropertyIsRefused(): void {
		$errors = $this->errors(['parent' => 'notAProperty']);

		$this->assertCount(1, $errors);
		$this->assertSame('hierarchy.unknown-property', $errors[0]['code']);
	}//end testAnUnknownPropertyIsRefused()

	/**
	 * A block naming no parent at all is refused.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/rbac-inherits-to-children/specs/rbac-scopes/spec.md
	 */
	public function testABlockWithNoParentIsRefused(): void {
		$errors = $this->errors(['maxDepth' => 3]);

		$this->assertCount(1, $errors);
		$this->assertSame('hierarchy.no-parent', $errors[0]['code']);
	}//end testABlockWithNoParentIsRefused()

	/**
	 * A schema with no annotation at all passes.
	 *
	 * The regression clause: an undeclared hierarchy changes nothing.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/rbac-inherits-to-children/specs/rbac-scopes/spec.md
	 */
	public function testNoAnnotationIsNoFinding(): void {
		$this->assertSame([], $this->validator->validate($this->caseSchema(null)));
	}//end testNoAnnotationIsNoFinding()

	/**
	 * The alias spelling is accepted, and validated exactly as the canonical one.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/rbac-inherits-to-children/specs/rbac-scopes/spec.md
	 */
	public function testTheAliasSpellingIsValidatedToo(): void {
		$this->assertSame([], $this->errors(['parentField' => 'parentCase']));

		$errors = $this->errors(['parentField' => 'assignee']);
		$this->assertCount(1, $errors);
		$this->assertSame('hierarchy.foreign-reference', $errors[0]['code']);
	}//end testTheAliasSpellingIsValidatedToo()

	/**
	 * An unknown key warns and does not refuse the schema.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/rbac-inherits-to-children/specs/rbac-scopes/spec.md
	 */
	public function testAnUnknownKeyWarnsRatherThanRefusing(): void {
		$split = HierarchyAnnotationValidator::partition(
			findings: $this->validator->validate(
				$this->caseSchema(['parent' => 'parentCase', 'cascade' => true])
			)
		);

		$this->assertSame([], $split['errors']);
		$this->assertCount(1, $split['warnings']);
		$this->assertStringContainsString('cascade', $split['warnings'][0]['message']);
	}//end testAnUnknownKeyWarnsRatherThanRefusing()

	/**
	 * A nonsensical depth or verb list is refused.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/rbac-inherits-to-children/specs/rbac-scopes/spec.md
	 */
	public function testABadDepthOrVerbListIsRefused(): void {
		$this->assertSame(
			'hierarchy.bad-depth',
			$this->errors(['parent' => 'parentCase', 'maxDepth' => 0])[0]['code']
		);
		$this->assertSame(
			'hierarchy.bad-depth',
			$this->errors(['parent' => 'parentCase', 'maxDepth' => 'five'])[0]['code']
		);
		$this->assertSame(
			'hierarchy.bad-verbs',
			$this->errors(['parent' => 'parentCase', 'inheritedVerbs' => 'read'])[0]['code']
		);
		$this->assertSame(
			'hierarchy.bad-verbs',
			$this->errors(['parent' => 'parentCase', 'inheritedVerbs' => ['read', '']])[0]['code']
		);
	}//end testABadDepthOrVerbListIsRefused()

	/**
	 * A block that is not an object at all is refused.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/rbac-inherits-to-children/specs/rbac-scopes/spec.md
	 */
	public function testANonObjectBlockIsRefused(): void {
		$findings = $this->validator->validate(
			['slug' => 'case', 'properties' => [], HierarchyGrantExpander::ANNOTATION => 'parentCase']
		);

		$this->assertCount(1, $findings);
		$this->assertSame('hierarchy.not-object', $findings[0]['code']);
	}//end testANonObjectBlockIsRefused()

	/**
	 * A reference written as a path still names this schema.
	 *
	 * An imported schema writes `$ref` as a path, and comparing the whole
	 * string would refuse a declaration that is perfectly good.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/rbac-inherits-to-children/specs/rbac-scopes/spec.md
	 */
	public function testAPathStyleReferenceIsAccepted(): void {
		$findings = $this->validator->validate(
			[
				'slug' => 'case',
				'properties' => ['parentCase' => ['$ref' => '#/components/schemas/case']],
				HierarchyGrantExpander::ANNOTATION => ['parent' => 'parentCase'],
			]
		);

		$this->assertSame([], HierarchyAnnotationValidator::partition($findings)['errors']);
	}//end testAPathStyleReferenceIsAccepted()
}//end class
