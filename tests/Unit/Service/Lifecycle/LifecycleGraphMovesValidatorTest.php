<?php

/**
 * OpenRegister graph-mode `form`, `sideMoves` and `reopen` declaration checks
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
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
 * @spec openspec/changes/lifecycle-over-reference-field/specs/object-lifecycle/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\Lifecycle;

use OCA\OpenRegister\Service\Lifecycle\LifecycleGraphMovesValidator;
use OCA\OpenRegister\Service\Lifecycle\LifecycleGraphValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Lifecycle\LifecycleGraphMovesValidator
 * @covers \OCA\OpenRegister\Service\Lifecycle\LifecycleGraphValidator
 */
class LifecycleGraphMovesValidatorTest extends TestCase {

	/**
	 * The case schema.
	 *
	 * @var array<string, mixed>
	 */
	private const SCHEMA = [
		'properties' => [
			'status' => ['type' => 'string'],
			'caseType' => ['type' => 'string'],
			'result' => ['type' => 'string', 'enum' => ['granted', 'refused']],
			'comment' => ['type' => 'string'],
			'deadline' => ['type' => 'string', 'format' => 'date'],
			'suspended' => ['type' => 'boolean'],
		],
	];

	/**
	 * The error codes for a graph block.
	 *
	 * @param array<string, mixed> $graph The graph block.
	 *
	 * @return list<string>
	 */
	private static function codes(array $graph): array {
		return array_column((new LifecycleGraphMovesValidator())->validate(graph: $graph, schema: self::SCHEMA, field: 'status'), 'code');
	}//end codes()

	public function testAFullDeclarationIsValid(): void {
		$this->assertSame(
			[],
			self::codes(
				[
					'form' => ['properties' => ['result', 'comment'], 'required' => ['result'], 'on' => 'final'],
					'sideMoves' => [
						'suspend' => ['field' => 'suspended', 'set' => true, 'label' => 'Opschorten'],
						'resume' => ['field' => 'suspended', 'set' => false],
						'extend' => ['form' => ['properties' => ['deadline'], 'required' => ['deadline']]],
					],
					'reopen' => ['to' => 'first', 'requires' => 'manage', 'form' => ['properties' => ['comment']]],
				]
			)
		);
	}//end testAFullDeclarationIsValid()

	public function testAGraphWithoutExtrasIsValid(): void {
		$this->assertSame([], self::codes(['allowedMoves' => 'forward']));
	}//end testAGraphWithoutExtrasIsValid()

	/**
	 * @return array<string, array{array<string, mixed>, list<string>}>
	 */
	public static function invalidDeclarations(): array {
		return [
			'form without properties' => [['form' => ['required' => ['result']]], ['lifecycle-graph-form-invalid']],
			'form names an undeclared property' => [['form' => ['properties' => ['outcome']]], ['lifecycle-graph-form-invalid']],
			'form names the lifecycle field' => [['form' => ['properties' => ['status']]], ['lifecycle-graph-form-invalid']],
			'required outside the properties' => [['form' => ['properties' => ['comment'], 'required' => ['result']]], ['lifecycle-graph-form-invalid']],
			'required not a list' => [['form' => ['properties' => ['comment'], 'required' => 'comment']], ['lifecycle-graph-form-invalid']],
			'form on neither any nor final' => [['form' => ['properties' => ['comment'], 'on' => 'first']], ['lifecycle-graph-form-invalid']],
			'side moves as a list' => [['sideMoves' => [['field' => 'suspended', 'set' => true]]], ['lifecycle-graph-sidemove-invalid']],
			'side move named reopen' => [['sideMoves' => ['reopen' => ['field' => 'suspended', 'set' => true]]], ['lifecycle-graph-sidemove-invalid']],
			'side move named like a graph move' => [['sideMoves' => ['move-to-x' => ['field' => 'suspended', 'set' => true]]], ['lifecycle-graph-sidemove-invalid']],
			'side move with neither field nor form' => [['sideMoves' => ['nudge' => ['label' => 'Nudge']]], ['lifecycle-graph-sidemove-invalid']],
			'side move on the lifecycle field' => [['sideMoves' => ['skip' => ['field' => 'status', 'set' => true]]], ['lifecycle-graph-sidemove-invalid']],
			'side move set not a boolean' => [['sideMoves' => ['suspend' => ['field' => 'suspended', 'set' => 'yes']]], ['lifecycle-graph-sidemove-invalid']],
			'side move with an empty label' => [['sideMoves' => ['suspend' => ['field' => 'suspended', 'set' => true, 'label' => '']]], ['lifecycle-graph-sidemove-invalid']],
			'side move with a bad form' => [['sideMoves' => ['extend' => ['form' => ['properties' => ['nope']]]]], ['lifecycle-graph-form-invalid']],
			'reopen not an object' => [['reopen' => true], ['lifecycle-graph-reopen-invalid']],
			'reopen to a list' => [['reopen' => ['to' => ['a', 'b']]], ['lifecycle-graph-reopen-invalid']],
			'reopen requires nothing' => [['reopen' => ['requires' => ' ']], ['lifecycle-graph-reopen-invalid']],
		];
	}//end invalidDeclarations()

	/**
	 * @param array<string, mixed> $graph    The graph block.
	 * @param list<string>         $expected The error codes.
	 */
	#[DataProvider('invalidDeclarations')]
	public function testAnInvalidDeclarationIsRefused(array $graph, array $expected): void {
		$this->assertSame($expected, self::codes($graph));
	}//end testAnInvalidDeclarationIsRefused()

	public function testTheGraphValidatorReportsTheExtras(): void {
		$annotation = [
			'field' => 'status',
			'graph' => [
				'schema' => 'statustype',
				'parentField' => 'caseType',
				'parentFrom' => 'caseType',
				'orderField' => 'order',
				'finalField' => 'isFinal',
				'allowedMoves' => 'forward',
				'reopen' => ['to' => []],
			],
		];

		$codes = array_column((new LifecycleGraphValidator())->validate(annotation: $annotation, schema: self::SCHEMA), 'code');

		$this->assertSame(['lifecycle-graph-reopen-invalid'], $codes);
	}//end testTheGraphValidatorReportsTheExtras()
}//end class
