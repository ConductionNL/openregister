<?php

/**
 * Unit tests for LeafRenderModeParity.
 *
 * Covers:
 *  - a render-mode mismatch across the server and JS layers is flagged;
 *  - agreeing layers, including an unstated JS mode against a component
 *    leaf, report nothing;
 *  - leaves known to one layer only, and non-render leaves, are not judged;
 *  - a malformed JS value is reported rather than passing.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Integration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/leaf-mount-render-escape-hatch/specs/leaf-mount-render/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Integration;

use OCA\OpenRegister\Service\Integration\LeafAvailability;
use OCA\OpenRegister\Service\Integration\LeafDescriptor;
use OCA\OpenRegister\Service\Integration\LeafIdentity;
use OCA\OpenRegister\Service\Integration\LeafRegistryInterface;
use OCA\OpenRegister\Service\Integration\LeafRenderModeParity;
use OCA\OpenRegister\Service\Integration\LeafRendering;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the cross-layer renderMode correlation.
 */
#[CoversClass(LeafRenderModeParity::class)]
#[UsesClass(LeafDescriptor::class)]
#[UsesClass(LeafIdentity::class)]
#[UsesClass(LeafRendering::class)]
#[UsesClass(LeafAvailability::class)]
class LeafRenderModeParityTest extends TestCase {

	/**
	 * A parity checker over a registry holding the given descriptors.
	 *
	 * @param array<int, LeafDescriptor> $descriptors The server descriptors.
	 *
	 * @return LeafRenderModeParity The checker.
	 */
	private function parity(array $descriptors): LeafRenderModeParity {
		$registry = $this->createMock(LeafRegistryInterface::class);
		$registry->expects($this->once())->method('getDescriptors')->willReturn($descriptors);

		return new LeafRenderModeParity(leafRegistry: $registry);
	}//end parity()

	/**
	 * A descriptor with the given id, kinds and render mode.
	 *
	 * @param string             $id         The leaf id.
	 * @param string             $renderMode The server render mode.
	 * @param array<int, string> $kinds      The leaf kinds.
	 *
	 * @return LeafDescriptor The descriptor.
	 */
	private function leaf(
		string $id,
		string $renderMode = LeafDescriptor::RENDER_MODE_COMPONENT,
		array $kinds = [LeafDescriptor::KIND_RENDER_SURFACE]
	): LeafDescriptor {
		return new LeafDescriptor(
			identity: new LeafIdentity(id: $id, label: 'Leaf', icon: 'Cube'),
			kinds: $kinds,
			rendering: new LeafRendering(renderMode: $renderMode),
		);
	}//end leaf()

	/**
	 * Server mount against JS component under the same id is a parity violation.
	 *
	 * @return void
	 */
	public function testServerMountAgainstJsComponentIsFlagged(): void {
		$parity = $this->parity(descriptors: [$this->leaf(id: 'hermiq-agent', renderMode: LeafDescriptor::RENDER_MODE_MOUNT)]);

		$this->assertSame(
			[['id' => 'hermiq-agent', 'server' => 'mount', 'js' => 'component']],
			$parity->mismatches(jsRenderModes: ['hermiq-agent' => 'component'])
		);
	}//end testServerMountAgainstJsComponentIsFlagged()

	/**
	 * A JS registration that states no mode is component, so it clashes with a mount server leaf.
	 *
	 * @return void
	 */
	public function testUnstatedJsModeCountsAsComponent(): void {
		$parity = $this->parity(
			descriptors: [
				$this->leaf(id: 'mount-leaf', renderMode: LeafDescriptor::RENDER_MODE_MOUNT),
				$this->leaf(id: 'sfc-leaf'),
				$this->leaf(id: 'empty-leaf'),
			]
		);

		$this->assertSame(
			[['id' => 'mount-leaf', 'server' => 'mount', 'js' => 'component']],
			$parity->mismatches(jsRenderModes: ['mount-leaf' => null, 'sfc-leaf' => null, 'empty-leaf' => ''])
		);
	}//end testUnstatedJsModeCountsAsComponent()

	/**
	 * Agreeing layers report nothing, in either mode.
	 *
	 * @return void
	 */
	public function testAgreeingLayersReportNothing(): void {
		$parity = $this->parity(
			descriptors: [
				$this->leaf(id: 'a', renderMode: LeafDescriptor::RENDER_MODE_MOUNT),
				$this->leaf(id: 'b'),
			]
		);

		$this->assertSame([], $parity->violations(jsRenderModes: ['a' => 'mount', 'b' => 'component']));
	}//end testAgreeingLayersReportNothing()

	/**
	 * A leaf on one layer only, or without a render surface, is not judged here.
	 *
	 * @return void
	 */
	public function testOneSidedAndNonRenderLeavesAreSkipped(): void {
		$parity = $this->parity(
			descriptors: [
				$this->leaf(id: 'server-only', renderMode: LeafDescriptor::RENDER_MODE_MOUNT),
				$this->leaf(id: 'data-only', renderMode: LeafDescriptor::RENDER_MODE_MOUNT, kinds: [LeafDescriptor::KIND_DATA_PROVIDER]),
			]
		);

		$this->assertSame([], $parity->mismatches(jsRenderModes: ['data-only' => 'component', 'js-only' => 'mount']));
	}//end testOneSidedAndNonRenderLeavesAreSkipped()

	/**
	 * Violation lines name the leaf and both modes, in registration order.
	 *
	 * @return void
	 */
	public function testViolationLinesNameLeafAndBothModes(): void {
		$parity = $this->parity(
			descriptors: [
				$this->leaf(id: 'first'),
				$this->leaf(id: 'second', renderMode: LeafDescriptor::RENDER_MODE_MOUNT),
			]
		);

		$this->assertSame(
			[
				'leaf "first": server renderMode "component" differs from JS registration renderMode "mount"',
				'leaf "second": server renderMode "mount" differs from JS registration renderMode "component"',
			],
			$parity->violations(jsRenderModes: ['second' => 'component', 'first' => 'mount'])
		);
	}//end testViolationLinesNameLeafAndBothModes()

	/**
	 * A non-string JS value is a mismatch, reported in its JSON spelling.
	 *
	 * @return void
	 */
	public function testMalformedJsValueIsReported(): void {
		$parity = $this->parity(descriptors: [$this->leaf(id: 'odd')]);

		$this->assertSame(
			[['id' => 'odd', 'server' => 'component', 'js' => 'true']],
			$parity->mismatches(jsRenderModes: ['odd' => true])
		);
	}//end testMalformedJsValueIsReported()
}//end class
