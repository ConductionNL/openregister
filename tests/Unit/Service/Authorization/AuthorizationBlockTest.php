<?php

/**
 * AuthorizationBlock: the owner sentinel and condition scopes in native rules.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Authorization
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/access-owner-and-condition-scopes/specs/authorization-rbac/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Authorization;

use OCA\OpenRegister\Service\Authorization\AuthorizationBlock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the normalisation of an authorization block.
 */
#[CoversClass(AuthorizationBlock::class)]
class AuthorizationBlockTest extends TestCase {

	/**
	 * `["@creator"]` alone leaves the action with no group, so only the owner rule admits.
	 *
	 * @return void
	 */
	public function testTheCreatorAloneLeavesAnEmptyList(): void {
		$this->assertSame(
			['read' => [], 'update' => ['redactie']],
			(new AuthorizationBlock())->normalise(authorization: ['read' => ['@creator'], 'update' => ['redactie']])
		);
	}

	/**
	 * `["@creator", "redactie"]` keeps the group and drops the sentinel.
	 *
	 * @return void
	 */
	public function testTheCreatorBesideAGroupKeepsTheGroup(): void {
		$this->assertSame(
			['read' => ['redactie']],
			(new AuthorizationBlock())->normalise(authorization: ['read' => ['@creator', 'redactie']])
		);
	}

	/**
	 * A condition with a literal becomes a conditional rule for signed-in users,
	 * appended to that action's list, and the conditions key is gone.
	 *
	 * @return void
	 */
	public function testALiteralConditionBecomesAConditionalRule(): void {
		$block = (new AuthorizationBlock())->normalise(
			authorization: [
				'read' => ['redactie'],
				'conditions' => ['read' => ['field' => 'afdeling', 'operator' => 'equals', 'value' => 'vergunningen']],
			]
		);

		$this->assertSame(
			['read' => ['redactie', ['group' => 'authenticated', 'match' => ['afdeling' => 'vergunningen']]]],
			$block
		);
	}

	/**
	 * `@user.uid` is the caller: the native `$userId` variable. A condition on an
	 * action without a list starts one.
	 *
	 * @return void
	 */
	public function testTheCallerValueBecomesTheUserIdVariable(): void {
		$block = (new AuthorizationBlock())->normalise(
			authorization: [
				'read' => ['@creator'],
				'conditions' => ['update' => ['field' => 'behandelaar', 'operator' => 'equals', 'value' => '@user.uid']],
			]
		);

		$this->assertSame(
			['read' => [], 'update' => [['group' => 'authenticated', 'match' => ['behandelaar' => '$userId']]]],
			$block
		);
	}

	/**
	 * A condition on a field the schema does not declare is kept; the match
	 * emitter refuses an undeclared field, so it is not this class's to drop.
	 *
	 * @return void
	 */
	public function testAConditionOnAnUndeclaredFieldIsKept(): void {
		$block = (new AuthorizationBlock())->normalise(
			authorization: ['conditions' => ['read' => ['field' => 'nietBestaand', 'operator' => 'equals', 'value' => 'x']]]
		);

		$this->assertSame(['read' => [['group' => 'authenticated', 'match' => ['nietBestaand' => 'x']]]], $block);
	}

	/**
	 * Several conditions on one action each become a rule.
	 *
	 * @return void
	 */
	public function testAListOfConditionsAddsARuleEach(): void {
		$block = (new AuthorizationBlock())->normalise(
			authorization: [
				'read' => [],
				'conditions' => [
					'read' => [
						['field' => 'afdeling', 'operator' => 'equals', 'value' => 'a'],
						['field' => 'afdeling', 'operator' => 'equals', 'value' => 'b'],
					],
				],
			]
		);

		$this->assertSame(['a', 'b'], array_map(static fn (array $rule): string => $rule['match']['afdeling'], $block['read']));
	}

	/**
	 * Conditions this class cannot read.
	 *
	 * @return array<string, array{0: mixed}>
	 */
	public static function unreadable(): array {
		return [
			'another operator' => [['field' => 'afdeling', 'operator' => 'contains', 'value' => 'a']],
			'no operator' => [['field' => 'afdeling', 'value' => 'a']],
			'no field' => [['operator' => 'equals', 'value' => 'a']],
			'blank field' => [['field' => ' ', 'operator' => 'equals', 'value' => 'a']],
			'an array value' => [['field' => 'afdeling', 'operator' => 'equals', 'value' => ['$in' => ['a']]]],
			'a native variable as literal' => [['field' => 'afdeling', 'operator' => 'equals', 'value' => '$userId']],
			'not an object' => ['afdeling=a'],
		];
	}

	/**
	 * An unreadable condition admits nobody: no rule is added for it.
	 *
	 * @param mixed $condition The condition.
	 *
	 * @return void
	 */
	#[DataProvider('unreadable')]
	public function testAnUnreadableConditionAddsNoRule(mixed $condition): void {
		$this->assertSame(
			['read' => []],
			(new AuthorizationBlock())->normalise(authorization: ['read' => [], 'conditions' => ['read' => $condition]])
		);
	}

	/**
	 * Native rules, control keys and the deny block pass through unchanged, and an
	 * empty or absent block stays as it is.
	 *
	 * @return void
	 */
	public function testEverythingElsePassesThrough(): void {
		$native = [
			'read' => ['public', ['group' => 'redactie', 'match' => ['status' => 'open']]],
			'deny' => ['read' => ['@creator']],
			'roles' => ['editor' => ['update']],
			'inheritFromPublic' => false,
		];

		$this->assertSame($native, (new AuthorizationBlock())->normalise(authorization: $native));
		$this->assertNull((new AuthorizationBlock())->normalise(authorization: null));
		$this->assertSame([], (new AuthorizationBlock())->normalise(authorization: []));
	}
}
