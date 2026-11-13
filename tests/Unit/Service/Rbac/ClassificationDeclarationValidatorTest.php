<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Rbac;

use OCA\OpenRegister\Service\Rbac\ClassificationDeclarationValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Schema save refuses a classification block that cannot be honoured.
 *
 * @covers \OCA\OpenRegister\Service\Rbac\ClassificationDeclarationValidator
 */
final class ClassificationDeclarationValidatorTest extends TestCase {

	private const PROPERTIES = ['label' => ['type' => 'string'], 'type' => ['type' => 'string'], 'standaard' => ['type' => 'string']];

	/**
	 * A valid block with one entry replaced.
	 *
	 * @param array<string, mixed> $change Entries to set.
	 *
	 * @return array<string, mixed>
	 */
	private static function block(array $change=[]): array {
		return array_merge(
			[
				'property' => 'label',
				'levels' => ['open', 'intern', 'geheim'],
				'default' => 'intern',
				'clearances' => [['group' => 'staff', 'level' => 'geheim']],
				'ceilings' => [['scope' => 'public', 'atOrAbove' => 'intern']],
				'floor' => ['property' => 'standaard'],
			],
			$change
		);
	}

	public function testAValidBlockHasNoFaults(): void {
		$validator = new ClassificationDeclarationValidator();
		$this->assertSame([], $validator->validate(self::block(), self::PROPERTIES));
		$this->assertSame([], $validator->validate(['property' => 'label', 'levels' => ['a', 'b']], self::PROPERTIES));
		$this->assertSame([], $validator->validate(self::block(['floor' => ['sourceRelation' => 'type', 'property' => 'elsewhere']]), self::PROPERTIES));
		$this->assertSame([], $validator->validate(self::block(['default' => null, 'floor' => null]), self::PROPERTIES));
	}

	/**
	 * Each broken block and the fault it must produce.
	 *
	 * @return array<string, array{0: mixed, 1: array<int, string>}>
	 */
	public static function broken(): array {
		return [
			'not an object' => ['geheim', ['must be an object with property and levels']],
			'one level' => [self::block(['levels' => ['only']]), ['levels must list at least two labels']],
			'levels not a list' => [self::block(['levels' => 'a,b']), ['levels must list at least two labels']],
			'duplicate level' => [self::block(['levels' => ['open', 'intern', 'open']]), ['levels names "open" twice']],
			'empty level' => [self::block(['levels' => ['open', ' ', 5]]), ['levels must be non-empty strings']],
			'undeclared property' => [self::block(['property' => 'afdeling']), ['property "afdeling" is not a property of this schema']],
			'no property' => [self::block(['property' => '']), ['property must name a property']],
			'level and property both wrong' => [
				self::block(['levels' => ['x'], 'property' => 5]),
				['levels must list at least two labels', 'property must name a property'],
			],
			'unknown default' => [self::block(['default' => 'top']), ['default must name a declared level']],
			'clearances not a list' => [self::block(['clearances' => 'staff:geheim']), ['clearances must be a list of {group, level}']],
			'clearance without group' => [self::block(['clearances' => [['level' => 'intern']]]), ['clearances[0] must name a group']],
			'clearance unknown level' => [self::block(['clearances' => [['group' => 'g', 'level' => 'top']]]), ['clearances[0].level must name a declared level']],
			'ceilings not a list' => [self::block(['ceilings' => 'public']), ['ceilings must be a list of {scope, atOrAbove}']],
			'ceiling without scope' => [self::block(['ceilings' => [['atOrAbove' => 'intern']]]), ['ceilings[0] must name a scope']],
			'ceiling unknown level' => [self::block(['ceilings' => [['scope' => 'public', 'atOrAbove' => 'top']]]), ['ceilings[0].atOrAbove must name a declared level']],
			'floor not an object' => [self::block(['floor' => 'standaard']), ['floor must name a property']],
			'floor undeclared property' => [self::block(['floor' => ['property' => 'nope']]), ['floor.property "nope" is not a property of this schema']],
			'floor undeclared relation' => [
				self::block(['floor' => ['property' => 'x', 'sourceRelation' => 'zaaktype']]),
				['floor.sourceRelation "zaaktype" is not a property of this schema'],
			],
		];
	}

	/**
	 * @param mixed              $block    The block.
	 * @param array<int, string> $expected The faults.
	 */
	#[DataProvider('broken')]
	public function testABrokenBlockIsRefusedNamingTheFault(mixed $block, array $expected): void {
		$this->assertSame($expected, (new ClassificationDeclarationValidator())->validate($block, self::PROPERTIES));
	}
}
