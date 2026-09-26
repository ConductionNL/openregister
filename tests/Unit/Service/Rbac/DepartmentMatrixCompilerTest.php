<?php

/**
 * A department by role matrix, and the one way it leaks (row B13).
 *
 * The matrix exists so a group can read the objects of its OWN department
 * rather than all of them or none. Every test below is a way that could be
 * wrong while the compiled JSON still looks like a narrowing:
 *
 *  - 🔴 an EMPTY `$in`. `buildArrayOperatorCondition()` returns null for an
 *    empty operand, `buildMatchConditions()` then drops the predicate, and the
 *    rule meant to say "only your own departments" becomes an unconditional
 *    grant to the whole group. A user with no department would see EVERYTHING
 *    rather than nothing, and nothing anywhere would report it. That is the
 *    single most important assertion in this file;
 *  - rows sharing a group emitted as separate rules, which works and puts four
 *    predicates in an OR where one `$in` belongs;
 *  - the compiled rules REPLACING the schema's own, retiring every rule an
 *    administrator wrote by hand;
 *  - `handle` resolving to `read`, which would grant a right the row's author
 *    plainly did not mean;
 *  - an unknown action being dropped silently, so the grid shows a right
 *    nobody holds.
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
 * @spec openspec/changes/rbac-department-role-matrix/specs/rbac-scopes/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\Rbac;

use OCA\OpenRegister\Service\Rbac\DepartmentMatrixCompiler;
use OCA\OpenRegister\Service\Rbac\DepartmentMatrixValidator;
use OCA\OpenRegister\Service\Rbac\PermissionCatalogue;
use PHPUnit\Framework\TestCase;

/**
 * Pins what a matrix compiles to, and what it refuses to compile.
 */
class DepartmentMatrixCompilerTest extends TestCase {

	private DepartmentMatrixCompiler $compiler;

	/**
	 * The declaration-time checks, which moved out of the compiler.
	 *
	 * @var DepartmentMatrixValidator
	 */
	private DepartmentMatrixValidator $validator;

	/**
	 * Set up the compiler.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->compiler = new DepartmentMatrixCompiler();
		$this->validator = new DepartmentMatrixValidator();
	}//end setUp()

	/**
	 * A matrix keyed on `department`, group prefix `dept:`.
	 *
	 * @param array<int, array<string, mixed>> $rows The rows.
	 *
	 * @return array<string, mixed> The matrix.
	 */
	private function matrix(array $rows): array {
		return [
			'field' => 'department',
			'userSource' => ['groupPrefix' => 'dept:'],
			'rows' => $rows,
		];
	}//end matrix()

	/**
	 * A literal row compiles to a conditional scope on the field.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/rbac-department-role-matrix/specs/rbac-scopes/spec.md
	 */
	public function testALiteralRowCompilesToAConditionalScope(): void {
		$compiled = $this->compiler->compile(
			matrix: $this->matrix(
				[['value' => 'VTH', 'group' => 'handlers', 'actions' => ['read']]]
			),
			ownValues: []
		);

		$this->assertSame(
			[
				'read' => [
					['group' => 'handlers', 'match' => ['department' => ['$in' => ['VTH']]]],
				],
			],
			$compiled
		);
	}//end testALiteralRowCompilesToAConditionalScope()

	/**
	 * `$self` resolves to the caller's own values.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/rbac-department-role-matrix/specs/rbac-scopes/spec.md
	 */
	public function testSelfResolvesToTheCallersOwnValues(): void {
		$compiled = $this->compiler->compile(
			matrix: $this->matrix(
				[['value' => '$self', 'group' => 'handlers', 'actions' => ['read']]]
			),
			ownValues: ['VTH']
		);

		$this->assertSame(
			['VTH'],
			$compiled['read'][0]['match']['department']['$in']
		);
	}//end testSelfResolvesToTheCallersOwnValues()

	/**
	 * 🔴 A row whose values resolve to nothing is DROPPED, never emitted empty.
	 *
	 * The assertion this whole change turns on. An empty `$in` is dropped by
	 * the SQL builder and the rule becomes an unconditional grant to the group,
	 * so a user with no department would see every object rather than none.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/rbac-department-role-matrix/specs/rbac-scopes/spec.md
	 */
	public function testARowThatResolvesToNothingIsDroppedWhole(): void {
		$compiled = $this->compiler->compile(
			matrix: $this->matrix(
				[['value' => '$self', 'group' => 'handlers', 'actions' => ['read']]]
			),
			ownValues: []
		);

		$this->assertSame([], $compiled, 'no rule at all, rather than a rule matching everything');
	}//end testARowThatResolvesToNothingIsDroppedWhole()

	/**
	 * A user in two departments gets both, in one rule.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/rbac-department-role-matrix/specs/rbac-scopes/spec.md
	 */
	public function testTwoOwnValuesBecomeOneRule(): void {
		$compiled = $this->compiler->compile(
			matrix: $this->matrix(
				[['value' => '$self', 'group' => 'handlers', 'actions' => ['read']]]
			),
			ownValues: ['Belastingen', 'VTH']
		);

		$this->assertCount(1, $compiled['read']);
		$this->assertSame(
			['Belastingen', 'VTH'],
			$compiled['read'][0]['match']['department']['$in']
		);
	}//end testTwoOwnValuesBecomeOneRule()

	/**
	 * Rows sharing a group merge into one scope (D-1).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/rbac-department-role-matrix/specs/rbac-scopes/spec.md
	 */
	public function testRowsSharingAGroupMerge(): void {
		$compiled = $this->compiler->compile(
			matrix: $this->matrix(
				[
					['value' => 'VTH', 'group' => 'handlers', 'actions' => ['read']],
					['value' => 'Belastingen', 'group' => 'handlers', 'actions' => ['read']],
					['value' => 'VTH', 'group' => 'managers', 'actions' => ['read']],
				]
			),
			ownValues: []
		);

		$this->assertCount(2, $compiled['read'], 'one rule per group, not per row');

		$byGroup = [];
		foreach ($compiled['read'] as $rule) {
			$byGroup[$rule['group']] = $rule['match']['department']['$in'];
		}

		$this->assertSame(['Belastingen', 'VTH'], $byGroup['handlers']);
		$this->assertSame(['VTH'], $byGroup['managers']);
	}//end testRowsSharingAGroupMerge()

	/**
	 * A row granting several actions compiles once per action.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/rbac-department-role-matrix/specs/rbac-scopes/spec.md
	 */
	public function testARowCompilesOncePerAction(): void {
		$compiled = $this->compiler->compile(
			matrix: $this->matrix(
				[['value' => 'VTH', 'group' => 'handlers', 'actions' => ['read', 'delete']]]
			),
			ownValues: []
		);

		$this->assertArrayHasKey('read', $compiled);
		$this->assertArrayHasKey('delete', $compiled);
		$this->assertSame('handlers', $compiled['delete'][0]['group']);
	}//end testARowCompilesOncePerAction()

	/**
	 * `handle` falls back to `update` when no voter claims it (D-3).
	 *
	 * Not to `read`: handling an object is at least changing it, and resolving
	 * it downward would grant a right the row's author plainly did not mean.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/rbac-department-role-matrix/specs/rbac-scopes/spec.md
	 */
	public function testHandleFallsBackToUpdate(): void {
		$compiled = $this->compiler->compile(
			matrix: $this->matrix(
				[['value' => 'VTH', 'group' => 'handlers', 'actions' => ['handle']]]
			),
			ownValues: []
		);

		$this->assertArrayHasKey('update', $compiled);
		$this->assertArrayNotHasKey('handle', $compiled);
		$this->assertArrayNotHasKey('read', $compiled);

		$this->assertSame('handle', $this->compiler->resolveAction('handle', ['handle']));
		$this->assertSame('update', $this->compiler->resolveAction('handle', []));
		$this->assertSame('read', $this->compiler->resolveAction('read', []));
	}//end testHandleFallsBackToUpdate()

	/**
	 * An unknown action contributes nothing at compile time.
	 *
	 * It is REFUSED at save, which is where an author can still see it; this
	 * pins that a declaration which somehow got stored does not compile into a
	 * rule under a verb nothing enforces.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/rbac-department-role-matrix/specs/rbac-scopes/spec.md
	 */
	public function testAnUnknownActionCompilesToNothing(): void {
		$compiled = $this->compiler->compile(
			matrix: $this->matrix(
				[['value' => 'VTH', 'group' => 'handlers', 'actions' => ['handel']]]
			),
			ownValues: []
		);

		$this->assertSame([], $compiled);
	}//end testAnUnknownActionCompilesToNothing()

	/**
	 * 🔴 The compiled rules are ADDED beside the schema's own, never replacing them.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/rbac-department-role-matrix/specs/rbac-scopes/spec.md
	 */
	public function testMergeAddsBesideTheExistingRules(): void {
		$merged = $this->compiler->merge(
			authorization: [
				'read' => ['admin'],
				'delete' => ['admin'],
				DepartmentMatrixCompiler::KEY => ['field' => 'department'],
			],
			compiled: [
				'read' => [['group' => 'handlers', 'match' => ['department' => ['$in' => ['VTH']]]]],
			]
		);

		$this->assertSame('admin', $merged['read'][0], 'the hand-written rule survives');
		$this->assertSame('handlers', $merged['read'][1]['group']);
		$this->assertSame(['admin'], $merged['delete'], 'an untouched action is untouched');
	}//end testMergeAddsBesideTheExistingRules()

	/**
	 * The declaration is removed from the effective block.
	 *
	 * It is an INPUT to the compiler. Left beside the rules it would hand every
	 * reader of the block a key it has to know to ignore, and the deny resolver
	 * walks this structure.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/rbac-department-role-matrix/specs/rbac-scopes/spec.md
	 */
	public function testTheDeclarationIsStrippedFromTheEffectiveBlock(): void {
		$merged = $this->compiler->merge(
			authorization: ['read' => ['admin'], DepartmentMatrixCompiler::KEY => ['field' => 'department']],
			compiled: ['read' => [['group' => 'handlers', 'match' => []]]]
		);

		$this->assertArrayNotHasKey(DepartmentMatrixCompiler::KEY, $merged);
	}//end testTheDeclarationIsStrippedFromTheEffectiveBlock()

	/**
	 * Nothing compiled leaves the block exactly as it was.
	 *
	 * The control for the merge: a matrix that compiles to nothing must not
	 * quietly strip its own declaration or touch a rule.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/rbac-department-role-matrix/specs/rbac-scopes/spec.md
	 */
	public function testNothingCompiledChangesNothing(): void {
		$block = ['read' => ['admin']];

		$this->assertSame($block, $this->compiler->merge(authorization: $block, compiled: []));
	}//end testNothingCompiledChangesNothing()

	/**
	 * A user's own values come from their groups, prefix stripped.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/rbac-department-role-matrix/specs/rbac-scopes/spec.md
	 */
	public function testOwnValuesComeFromTheGroupPrefix(): void {
		$values = $this->compiler->valuesFromGroups(
			source: ['groupPrefix' => 'dept:'],
			userGroups: ['handlers', 'dept:VTH', 'dept:Belastingen', 'department:Other', 'dept:']
		);

		$this->assertSame(['VTH', 'Belastingen'], $values);
		$this->assertNotContains('Other', $values, 'a similar prefix is not the prefix');
		$this->assertNotContains('', $values, 'the bare prefix names no department');
	}//end testOwnValuesComeFromTheGroupPrefix()

	/**
	 * A user source with no prefix yields nothing.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/rbac-department-role-matrix/specs/rbac-scopes/spec.md
	 */
	public function testNoPrefixYieldsNoValues(): void {
		$this->assertSame([], $this->compiler->valuesFromGroups(source: null, userGroups: ['dept:VTH']));
		$this->assertSame(
			[],
			$this->compiler->valuesFromGroups(
				source: ['schema' => 'person', 'property' => 'department'],
				userGroups: ['dept:VTH']
			)
		);
	}//end testNoPrefixYieldsNoValues()

	/**
	 * 🔴 The matrix key is a CONTROL key, not a verb.
	 *
	 * This was shipped broken in the change that introduced the matrix and is
	 * caught here. `PermissionCatalogue::unknownVerbsIn()` walks the block's
	 * keys and skips the control keys; `matrix` was not among them, so the key
	 * was read as a VERB, `isGrantable()` answered no, and `assertGrantable()`
	 * refused the schema save with "unknown verb: matrix". Every unit test of
	 * the compiler passed, because none of them goes through that check, and
	 * the e2e that would have caught it could not be run in the phase that
	 * wrote it. The whole feature was unreachable.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/grants-that-follow-a-slot-a-relation-or-a-reason/specs/rbac-scopes/spec.md
	 */
	public function testTheMatrixKeyIsNotReadAsAVerb(): void {
		$catalogue = new PermissionCatalogue();

		$this->assertContains(
			DepartmentMatrixCompiler::KEY,
			PermissionCatalogue::CONTROL_KEYS,
			'a declaration read as a verb refuses the whole schema save'
		);
		$this->assertSame(
			[],
			$catalogue->unknownVerbsIn(
				[
					'read' => ['admin'],
					DepartmentMatrixCompiler::KEY => ['field' => 'department'],
				]
			)
		);
	}//end testTheMatrixKeyIsNotReadAsAVerb()

	/**
	 * A matrix naming a field the schema does not declare is refused.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/rbac-department-role-matrix/specs/rbac-scopes/spec.md
	 */
	public function testAMatrixOnAMissingFieldIsRefused(): void {
		$findings = $this->validator->validate(
			properties: ['department' => ['type' => 'string']],
			authorization: [
				DepartmentMatrixCompiler::KEY => [
					'field' => 'afdeling',
					'userSource' => ['groupPrefix' => 'dept:'],
					'rows' => [['value' => 'VTH', 'group' => 'handlers', 'actions' => ['read']]],
				],
			]
		);

		$this->assertCount(1, $findings);
		$this->assertSame('matrix.unknown-field', $findings[0]['code']);
		$this->assertStringContainsString('afdeling', $findings[0]['message']);
	}//end testAMatrixOnAMissingFieldIsRefused()

	/**
	 * A valid matrix has no findings, and no matrix at all has none either.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/rbac-department-role-matrix/specs/rbac-scopes/spec.md
	 */
	public function testAValidMatrixAndNoMatrixBothPass(): void {
		$properties = ['department' => ['type' => 'string']];

		$this->assertSame([], $this->validator->validate(properties: $properties, authorization: null));
		$this->assertSame(
			[],
			$this->validator->validate(properties: $properties, authorization: ['read' => ['admin']])
		);
		$this->assertSame(
			[],
			$this->validator->validate(
				properties: $properties,
				authorization: [
					DepartmentMatrixCompiler::KEY => [
						'field' => 'department',
						'userSource' => ['groupPrefix' => 'dept:'],
						'rows' => [['value' => '$self', 'group' => 'handlers', 'actions' => ['read', 'handle']]],
					],
				]
			)
		);
	}//end testAValidMatrixAndNoMatrixBothPass()

	/**
	 * A missing user source, empty rows and a bad action are each refused.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/rbac-department-role-matrix/specs/rbac-scopes/spec.md
	 */
	public function testTheShapeOfTheDeclarationIsChecked(): void {
		$properties = ['department' => ['type' => 'string']];

		$codes = static fn (array $findings): array => array_column($findings, 'code');

		$this->assertContains(
			'matrix.no-user-source',
			$codes(
				$this->validator->validate(
					properties: $properties,
					authorization: [
						DepartmentMatrixCompiler::KEY => [
							'field' => 'department',
							'rows' => [['value' => 'VTH', 'group' => 'g', 'actions' => ['read']]],
						],
					]
				)
			)
		);

		$this->assertContains(
			'matrix.no-rows',
			$codes(
				$this->validator->validate(
					properties: $properties,
					authorization: [
						DepartmentMatrixCompiler::KEY => [
							'field' => 'department',
							'userSource' => ['groupPrefix' => 'dept:'],
							'rows' => [],
						],
					]
				)
			)
		);

		$this->assertContains(
			'matrix.unknown-action',
			$codes(
				$this->validator->validate(
					properties: $properties,
					authorization: [
						DepartmentMatrixCompiler::KEY => [
							'field' => 'department',
							'userSource' => ['groupPrefix' => 'dept:'],
							'rows' => [['value' => 'VTH', 'group' => 'g', 'actions' => ['handel']]],
						],
					]
				)
			)
		);

		$this->assertContains(
			'matrix.no-group',
			$codes(
				$this->validator->validate(
					properties: $properties,
					authorization: [
						DepartmentMatrixCompiler::KEY => [
							'field' => 'department',
							'userSource' => ['groupPrefix' => 'dept:'],
							'rows' => [['value' => 'VTH', 'actions' => ['read']]],
						],
					]
				)
			)
		);
	}//end testTheShapeOfTheDeclarationIsChecked()
}//end class
