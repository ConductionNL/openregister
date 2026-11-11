<?php

/**
 * FlowPublishedGraph resolves the graph a flow publishes and the graph a run
 * was pinned to, and overlays a pinned graph onto the live definition.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Flow
 * @author   Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow;

use OCA\OpenRegister\Db\FlowRun;
use OCA\OpenRegister\Db\FlowVersion;
use OCA\OpenRegister\Db\FlowVersionMapperInterface;
use OCA\OpenRegister\Service\Flow\FlowDefinitionPin;
use OCA\OpenRegister\Service\Flow\FlowPublishedGraph;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * @covers \OCA\OpenRegister\Service\Flow\FlowPublishedGraph
 * @uses   \OCA\OpenRegister\Db\FlowRun
 * @uses   \OCA\OpenRegister\Db\FlowVersion
 */
class FlowPublishedGraphBehaviourTest extends TestCase {

	/**
	 * Version mapper.
	 *
	 * @var FlowVersionMapperInterface&MockObject
	 */
	private FlowVersionMapperInterface&MockObject $versions;

	/**
	 * Definition pin.
	 *
	 * @var FlowDefinitionPin&MockObject
	 */
	private FlowDefinitionPin&MockObject $pin;

	/**
	 * Class under test.
	 *
	 * @var FlowPublishedGraph
	 */
	private FlowPublishedGraph $graphs;

	/**
	 * Wire the container; the pin maps hash "h1" to a graph.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->versions = $this->createMock(FlowVersionMapperInterface::class);
		$this->pin      = $this->createMock(FlowDefinitionPin::class);
		$this->pin->method('graphFor')->willReturnCallback(
			static fn (?string $hash) => $hash === 'h1' ? ['nodes' => ['pinned'], 'edges' => [], 'executionMode' => 'queued'] : null
		);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnMap(
			[
				[FlowVersionMapperInterface::class, $this->versions],
				[FlowDefinitionPin::class, $this->pin],
			]
		);
		$this->graphs = new FlowPublishedGraph($container);
	}

	/**
	 * A version row with the given definition hash.
	 *
	 * @param string $hash The hash.
	 *
	 * @return FlowVersion
	 */
	private function version(string $hash): FlowVersion {
		$version = new FlowVersion();
		$version->setDefinitionHash($hash);
		return $version;
	}

	/**
	 * A run of flow "f-1", optionally pinned to a version.
	 *
	 * @param int|null $version The pinned version.
	 *
	 * @return FlowRun
	 */
	private function flowRun(?int $version): FlowRun {
		$run = new FlowRun();
		$run->setFlowId('f-1');
		$run->setFlowVersion($version);
		return $run;
	}

	/**
	 * The published version's graph is returned; an unpublished flow has none.
	 *
	 * @return void
	 */
	public function testGraphOf(): void {
		$this->versions->method('findPublished')->willReturnCallback(
			fn (string $uuid) => $uuid === 'f-1' ? $this->version('h1') : null
		);
		$this->assertSame(['nodes' => ['pinned'], 'edges' => [], 'executionMode' => 'queued'], $this->graphs->graphOf('f-1'));
		$this->assertNull($this->graphs->graphOf('f-2'));
	}

	/**
	 * A run pinned to a stored version gets that version's graph.
	 *
	 * @return void
	 */
	public function testOfPinnedRun(): void {
		$this->versions->expects($this->once())->method('find')->with('f-1', 3)->willReturn($this->version('h1'));
		$this->assertSame(['pinned'], $this->graphs->ofRun($this->flowRun(3))['nodes']);
	}

	/**
	 * An unpinned run has no pinned graph and nothing is looked up.
	 *
	 * @return void
	 */
	public function testOfUnpinnedRun(): void {
		$this->versions->expects($this->never())->method('find');
		$this->assertNull($this->graphs->ofRun($this->flowRun(null)));
	}

	/**
	 * A run pinned to a version that no longer exists has no graph.
	 *
	 * @return void
	 */
	public function testOfRunWithMissingVersion(): void {
		$this->versions->method('find')->willReturn(null);
		$this->assertNull($this->graphs->ofRun($this->flowRun(9)));
	}

	/**
	 * An unpinned run keeps the live definition untouched.
	 *
	 * @return void
	 */
	public function testOverlayKeepsLiveForUnpinnedRun(): void {
		$live = ['name' => 'Live', 'nodes' => ['live']];
		$this->assertSame($live, $this->graphs->overlayOnto($this->flowRun(null), $live));
	}

	/**
	 * A pinned run takes the pinned graph keys over the live ones and keeps the rest.
	 *
	 * @return void
	 */
	public function testOverlayReplacesPinnedKeys(): void {
		$this->versions->method('find')->willReturn($this->version('h1'));
		$this->assertSame(
			['name' => 'Live', 'nodes' => ['pinned'], 'edges' => [], 'limits' => ['max' => 5], 'executionMode' => 'queued'],
			$this->graphs->overlayOnto($this->flowRun(3), ['name' => 'Live', 'nodes' => ['live'], 'edges' => ['e'], 'limits' => ['max' => 5]])
		);
	}

	/**
	 * A pinned run whose graph is gone gives no definition rather than the live one.
	 *
	 * @return void
	 */
	public function testOverlayWithLostPinIsNull(): void {
		$this->versions->method('find')->willReturn($this->version('other'));
		$this->assertNull($this->graphs->overlayOnto($this->flowRun(3), ['nodes' => ['live']]));
	}
}
