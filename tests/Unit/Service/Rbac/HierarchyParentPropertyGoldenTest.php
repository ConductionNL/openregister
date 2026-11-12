<?php

/**
 * Golden test for the parent-property findings of HierarchyAnnotationValidator.
 *
 * Pins the exact accepted and refused outcomes of the parent check, messages
 * included, so the check can be split without changing what a schema author
 * is told or which declarations are accepted.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/rbac-scopes/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Rbac;

use OCA\OpenRegister\Service\Rbac\HierarchyAnnotationValidator;
use OCA\OpenRegister\Service\Rbac\HierarchyGrantExpander;
use PHPUnit\Framework\TestCase;

/**
 * Accepted and refused parent properties, pinned whole.
 */
class HierarchyParentPropertyGoldenTest extends TestCase {

	/**
	 * The findings for a `case` schema whose hierarchy names this parent.
	 *
	 * @param string $parent The parent property.
	 *
	 * @return array<int, array{code: string, message: string, severity: string}> The findings.
	 */
	private function findings(string $parent): array {
		return (new HierarchyAnnotationValidator())->validate(
			[
				'slug' => 'case',
				'id' => 169,
				'properties' => [
					'parentCase' => ['type' => 'string', '$ref' => 'case'],
					'parentById' => ['type' => 'string', '$ref' => '169'],
					'legacyParent' => ['type' => 'string', 'objectConfiguration' => ['schema' => 'case']],
					'assignee' => ['type' => 'string', '$ref' => 'user'],
					'title' => ['type' => 'string'],
					'scalar' => 'string',
				],
				HierarchyGrantExpander::ANNOTATION => ['parent' => $parent],
			]
		);
	}//end findings()

	/**
	 * One error finding.
	 *
	 * @param string $code    The code.
	 * @param string $message The message.
	 *
	 * @return array<int, array{code: string, message: string, severity: string}>
	 */
	private static function refusal(string $code, string $message): array {
		return [['code' => $code, 'message' => $message, 'severity' => 'error']];
	}//end refusal()

	public function testASelfReferenceInEitherSpellingOrByIdIsAccepted(): void {
		$this->assertSame([], $this->findings('parentCase'));
		$this->assertSame([], $this->findings('parentById'));
		$this->assertSame([], $this->findings('legacyParent'));
	}//end testASelfReferenceInEitherSpellingOrByIdIsAccepted()

	public function testEachRefusalIsPinned(): void {
		$this->assertSame(
			self::refusal(
				'hierarchy.foreign-reference',
				'The parent property "assignee" references "user" rather than this schema; a hierarchy edge must point at the same schema.'
			),
			$this->findings('assignee')
		);
		$this->assertSame(
			self::refusal('hierarchy.not-a-reference', 'The parent property "title" is not declared as a reference to another object.'),
			$this->findings('title')
		);
		$this->assertSame(
			self::refusal('hierarchy.unknown-property', 'The parent property "scalar" is not a property of this schema.'),
			$this->findings('scalar')
		);
		$this->assertSame(
			self::refusal('hierarchy.unknown-property', 'The parent property "missing" is not a property of this schema.'),
			$this->findings('missing')
		);
	}//end testEachRefusalIsPinned()

	public function testASchemaWithoutPropertiesHasNoParentProperty(): void {
		$this->assertSame(
			self::refusal('hierarchy.unknown-property', 'The parent property "p" is not a property of this schema.'),
			(new HierarchyAnnotationValidator())->validate(['properties' => 'none', HierarchyGrantExpander::ANNOTATION => ['parent' => 'p']])
		);
	}//end testASchemaWithoutPropertiesHasNoParentProperty()

	public function testASchemaWithoutIdentitiesAcceptsAnyReference(): void {
		$this->assertSame(
			[],
			(new HierarchyAnnotationValidator())->validate(
				['properties' => ['p' => ['$ref' => 'anything']], HierarchyGrantExpander::ANNOTATION => ['parent' => 'p']]
			)
		);
	}//end testASchemaWithoutIdentitiesAcceptsAnyReference()

	public function testTheAnnotationLevelFindingsAndTheirOrderArePinned(): void {
		$validator = new HierarchyAnnotationValidator();

		$this->assertSame([], $validator->validate(['properties' => []]));
		$this->assertSame(
			self::refusal('hierarchy.not-object', HierarchyGrantExpander::ANNOTATION . ' must be an object.'),
			$validator->validate([HierarchyGrantExpander::ANNOTATION => 'parent'])
		);
		$this->assertSame(
			[
				['code' => 'hierarchy.unknown-key', 'message' => 'Unknown key "typo" was ignored.', 'severity' => 'warning'],
				['code' => 'hierarchy.no-parent', 'message' => 'A hierarchy must name the property that points at the parent.', 'severity' => 'error'],
			],
			$validator->validate([HierarchyGrantExpander::ANNOTATION => ['typo' => 1, 'parent' => '  ', 'maxDepth' => 0]])
		);
		$this->assertSame(
			[
				['code' => 'hierarchy.unknown-key', 'message' => 'Unknown key "typo" was ignored.', 'severity' => 'warning'],
				['code' => 'hierarchy.unknown-property', 'message' => 'The parent property "up" is not a property of this schema.', 'severity' => 'error'],
				['code' => 'hierarchy.bad-depth', 'message' => 'maxDepth must be a positive integer.', 'severity' => 'error'],
			],
			$validator->validate([HierarchyGrantExpander::ANNOTATION => ['typo' => 1, 'parentField' => ' up ', 'maxDepth' => 0]])
		);
	}//end testTheAnnotationLevelFindingsAndTheirOrderArePinned()
}//end class
