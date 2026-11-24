<?php

/**
 * NodeRightsMapInterface is bound to the flow node-rights guard.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Kernel
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Kernel;

use OCA\OpenRegister\Kernel\Contracts\Core\NodeRightsMapInterface;
use OCA\OpenRegister\Service\ProcessesServiceBindings;
use OCA\OpenRegister\Service\Flow\FlowNodeRightsGuard;
use OCA\OpenRegister\Service\Flow\FlowNodeRightsGuardInterface;
use OCA\OpenRegister\Service\Flow\FlowWiring;
use OCA\OpenRegister\Service\Flow\IFlowNodeRequiresRight;
use PHPUnit\Framework\TestCase;

/**
 * The action-rights API asks the Core port; the node-rights guard answers both names.
 */
final class NodeRightsMapBindingTest extends TestCase {

	public function testThePortAndTheGuardInterfaceResolveToTheSameGuard(): void {
		$this->assertSame(FlowNodeRightsGuard::class, FlowWiring::BINDINGS[NodeRightsMapInterface::class] ?? null);
		$this->assertSame(FlowNodeRightsGuard::class, ProcessesServiceBindings::BINDINGS[FlowNodeRightsGuardInterface::class] ?? null);
	}

	public function testTheGuardInterfaceIsThePort(): void {
		$this->assertTrue(is_subclass_of(FlowNodeRightsGuardInterface::class, NodeRightsMapInterface::class));
		$this->assertTrue(is_subclass_of(FlowNodeRightsGuard::class, NodeRightsMapInterface::class));
	}

	public function testANodeRightHasOnePrefix(): void {
		$this->assertSame('flow.node.', NodeRightsMapInterface::RIGHT_PREFIX);
		$this->assertSame(NodeRightsMapInterface::RIGHT_PREFIX, IFlowNodeRequiresRight::RIGHT_PREFIX);
	}
}//end class
