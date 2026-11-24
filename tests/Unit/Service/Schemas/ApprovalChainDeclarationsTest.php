<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The schema body's approval-chain declaration, read the way the guard read it
 * before the reading moved to Core.
 *
 * @spec openspec/changes/approval-position-task-form/specs/flow-approval-consolidation/spec.md#requirement-an-approval-position-may-declare-the-form-its-task-presents
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Schemas;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- PHPUnit arrange/act/assert conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Kernel\Contracts\Core\ApprovalPositionFormCheckInterface;
use OCA\OpenRegister\Service\Flow\FlowWiring;
use OCA\OpenRegister\Service\Schemas\ApprovalChainDeclarations;
use OCA\OpenRegister\Service\Task\ApprovalPositionFormGuard;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Schemas\ApprovalChainDeclarations
 */
final class ApprovalChainDeclarationsTest extends TestCase {
	/**
	 * A body declaring the given chains.
	 *
	 * @param mixed $chains The chains value.
	 *
	 * @return array<string, mixed>
	 */
	private static function body(mixed $chains): array {
		return ['configuration' => [ApprovalChainDeclarations::CHAINS_KEY => $chains]];
	}//end body()

	public function testTheKeyIsTheOneTheGuardDeclares(): void {
		$this->assertSame('x-openregister-approval-chains', ApprovalChainDeclarations::CHAINS_KEY);
		$this->assertSame(ApprovalChainDeclarations::CHAINS_KEY, ApprovalPositionFormGuard::CHAINS_KEY);
	}

	public function testAPositionWithAFormIsDeclared(): void {
		$body = self::body(chains: ['budget' => ['approvers' => [['role' => 'a'], ['role' => 'b', 'form' => null]]]]);

		$this->assertTrue(ApprovalChainDeclarations::declaresForms(data: $body));
		$this->assertTrue(ApprovalPositionFormGuard::declaresForms(data: $body));
	}

	public function testChainsWithoutFormsDeclareNone(): void {
		$this->assertFalse(ApprovalChainDeclarations::declaresForms(data: self::body(chains: ['budget' => ['approvers' => [['role' => 'a'], 'b']]])));
		$this->assertFalse(ApprovalChainDeclarations::declaresForms(data: self::body(chains: 'nope')));
		$this->assertFalse(ApprovalChainDeclarations::declaresForms(data: []));
	}

	public function testOnlyChainsThatAreMapsAreRead(): void {
		$chains = ['budget' => ['approvers' => []], 'broken' => 'text', 7 => ['approvers' => [['form' => []]]]];

		$this->assertSame(['budget' => ['approvers' => []], 7 => ['approvers' => [['form' => []]]]], ApprovalChainDeclarations::chainsOf(data: self::body(chains: $chains)));
		$this->assertSame([], ApprovalChainDeclarations::chainsOf(data: ['configuration' => null]));
	}

	public function testTheGuardAnswersTheCorePortInItsOwnRegistrar(): void {
		$this->assertTrue(is_subclass_of(ApprovalPositionFormGuard::class, ApprovalPositionFormCheckInterface::class));
		$this->assertSame(ApprovalPositionFormGuard::class, FlowWiring::BINDINGS[ApprovalPositionFormCheckInterface::class]);
	}
}//end class
