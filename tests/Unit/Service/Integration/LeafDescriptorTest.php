<?php

/**
 * Unit tests for LeafDescriptor.
 *
 * Covers:
 *  - accessors round-trip the constructed metadata;
 *  - kinds are reported and `hasKind()` answers correctly;
 *  - renderMode defaults to `component` and round-trips a `mount` value;
 *  - toArray() carries availability + capability metadata (incl. renderMode)
 *    but NO components;
 *  - the render-and-read boundary (ADR-066): the descriptor exposes no verb,
 *    command, handler, or dispatch method.
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
 * @spec openspec/changes/app-leaf-provider-registration/specs/leaf-provider-registration/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Integration;

use OCA\OpenRegister\Service\Integration\LeafDescriptor;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the LeafDescriptor value object.
 */
class LeafDescriptorTest extends TestCase {

	/**
	 * Accessors round-trip the constructed metadata.
	 *
	 * @return void
	 */
	public function testAccessorsRoundTripMetadata(): void {
		$descriptor = new LeafDescriptor(
			id: 'acme-notes',
			label: 'Notes',
			icon: 'NoteText',
			kinds: [LeafDescriptor::KIND_DATA_PROVIDER, LeafDescriptor::KIND_RENDER_SURFACE],
			requiredApp: 'acme',
			group: 'comms',
			surfaces: ['detail-page'],
			referenceType: 'acme-note',
			requiresPermission: 'acme.read',
		);

		$this->assertSame('acme-notes', $descriptor->getId());
		$this->assertSame('Notes', $descriptor->getLabel());
		$this->assertSame('NoteText', $descriptor->getIcon());
		$this->assertSame('acme', $descriptor->getRequiredApp());
		$this->assertSame('comms', $descriptor->getGroup());
		$this->assertSame(['detail-page'], $descriptor->getSurfaces());
		$this->assertSame('acme-note', $descriptor->getReferenceType());
		$this->assertSame('acme.read', $descriptor->requiresPermission());

	}//end testAccessorsRoundTripMetadata()

	/**
	 * Kinds are reported and hasKind answers correctly.
	 *
	 * @return void
	 */
	public function testKindsAreReported(): void {
		$descriptor = new LeafDescriptor(
			id: 'acme-notes',
			label: 'Notes',
			icon: 'NoteText',
			kinds: [LeafDescriptor::KIND_DATA_PROVIDER],
		);

		$this->assertSame([LeafDescriptor::KIND_DATA_PROVIDER], $descriptor->getKinds());
		$this->assertTrue($descriptor->hasKind(LeafDescriptor::KIND_DATA_PROVIDER));
		$this->assertFalse($descriptor->hasKind(LeafDescriptor::KIND_RENDER_SURFACE));
		$this->assertFalse($descriptor->hasKind(LeafDescriptor::KIND_AGENT_RUNNER));

	}//end testKindsAreReported()

	/**
	 * Defaults are null/empty for the optional metadata.
	 *
	 * @return void
	 */
	public function testOptionalMetadataDefaults(): void {
		$descriptor = new LeafDescriptor(
			id: 'acme-tab',
			label: 'Tab',
			icon: 'Cube',
			kinds: [LeafDescriptor::KIND_RENDER_SURFACE],
		);

		$this->assertNull($descriptor->getRequiredApp());
		$this->assertNull($descriptor->getGroup());
		$this->assertSame([], $descriptor->getSurfaces());
		$this->assertNull($descriptor->getReferenceType());
		$this->assertNull($descriptor->requiresPermission());
		// renderMode defaults to `component` — the existing SFC contract, so
		// every descriptor that does not opt in keeps rendering as before.
		$this->assertSame(LeafDescriptor::RENDER_MODE_COMPONENT, $descriptor->getRenderMode());
		$this->assertSame('component', $descriptor->getRenderMode());

	}//end testOptionalMetadataDefaults()

	/**
	 * A descriptor may declare the `mount` render mode; the getter round-trips it.
	 *
	 * @return void
	 */
	public function testRenderModeMountIsRoundTripped(): void {
		$descriptor = new LeafDescriptor(
			id: 'hermiq-agent',
			label: 'Agent',
			icon: 'Robot',
			kinds: [LeafDescriptor::KIND_RENDER_SURFACE],
			surfaces: ['single-entity'],
			renderMode: LeafDescriptor::RENDER_MODE_MOUNT,
		);

		$this->assertSame('mount', $descriptor->getRenderMode());
		$this->assertSame('mount', $descriptor->toArray()['renderMode']);

	}//end testRenderModeMountIsRoundTripped()

	/**
	 * toArray() carries capability metadata but no Vue components.
	 *
	 * @return void
	 */
	public function testToArrayCarriesMetadataNotComponents(): void {
		$descriptor = new LeafDescriptor(
			id: 'acme-notes',
			label: 'Notes',
			icon: 'NoteText',
			kinds: [LeafDescriptor::KIND_DATA_PROVIDER],
			requiredApp: 'acme',
			surfaces: ['detail-page'],
		);

		$array = $descriptor->toArray();

		$this->assertSame('acme-notes', $array['id']);
		$this->assertSame(['detail-page'], $array['surfaces']);
		$this->assertSame([LeafDescriptor::KIND_DATA_PROVIDER], $array['kinds']);
		// renderMode is carried as discovery metadata (HOW a render leaf
		// renders), defaulting to `component`; it is a mode string, not a
		// shipped Vue component.
		$this->assertSame('component', $array['renderMode']);

		// No component/render field is carried on the server descriptor;
		// render components live on the JS layer under the same id.
		$this->assertArrayNotHasKey('tab', $array);
		$this->assertArrayNotHasKey('widget', $array);
		$this->assertArrayNotHasKey('component', $array);

	}//end testToArrayCarriesMetadataNotComponents()

	/**
	 * Render-and-read boundary (ADR-066): the descriptor exposes no verb.
	 *
	 * @return void
	 */
	public function testDescriptorExposesNoCommandVerb(): void {
		$forbidden = ['call', 'invoke', 'dispatch', 'execute', 'run', 'handle', 'command', 'trigger', 'perform'];

		$reflection = new \ReflectionClass(LeafDescriptor::class);
		foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
			$name = strtolower($method->getName());
			foreach ($forbidden as $verb) {
				$this->assertStringNotContainsString(
					$verb,
					$name,
					sprintf('LeafDescriptor must expose no command verb; found "%s"', $method->getName())
				);
			}
		}

	}//end testDescriptorExposesNoCommandVerb()
}//end class
