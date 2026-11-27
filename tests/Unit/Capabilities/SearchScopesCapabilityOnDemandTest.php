<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Capabilities;

use OCA\OpenRegister\Capabilities\SearchScopesCapability;
use OCA\OpenRegister\Capabilities\SearchScopesCapabilityOnDemand;
use OCP\Capabilities\ICapability;
use OCP\Capabilities\IInitialStateExcludedCapability;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * The on-demand search scopes capability builds SearchScopesCapability only
 * when the block is asked for, and answers exactly its block.
 *
 * @spec openspec/changes/content-search-index/specs/unified-search-provider/spec.md#requirement-the-provider-accepts-scopes-and-advertises-them
 */
class SearchScopesCapabilityOnDemandTest extends TestCase {

	/**
	 * Constructing it asks the container for nothing, and it stays out of the page state.
	 *
	 * @return void
	 */
	public function testConstructingItBuildsNothing(): void {
		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->expects($this->never())->method('get');

		$capability = new SearchScopesCapabilityOnDemand(container: $container);

		$this->assertInstanceOf(expected: ICapability::class, actual: $capability);
		$this->assertInstanceOf(expected: IInitialStateExcludedCapability::class, actual: $capability);
	}//end testConstructingItBuildsNothing()

	/**
	 * Asked for its block, it builds SearchScopesCapability and answers its block unchanged.
	 *
	 * @return void
	 */
	public function testItAnswersTheSearchScopesBlock(): void {
		$block = ['openregister' => ['search' => ['contractVersion' => 1, 'scopes' => [['type' => 'app', 'value' => 'docs']]]]];
		$inner = $this->createMock(originalClassName: SearchScopesCapability::class);
		$inner->expects($this->once())->method('getCapabilities')->willReturn($block);
		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->expects($this->once())->method('get')->with(SearchScopesCapability::class)->willReturn($inner);

		$capability = new SearchScopesCapabilityOnDemand(container: $container);

		$this->assertSame(expected: $block, actual: $capability->getCapabilities());
	}//end testItAnswersTheSearchScopesBlock()
}//end class
