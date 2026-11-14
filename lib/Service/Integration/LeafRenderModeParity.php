<?php

/**
 * LeafRenderModeParity: correlates a render-surface leaf's server renderMode with its JS registration.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Integration
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

namespace OCA\OpenRegister\Service\Integration;

/**
 * The cross-layer half of render-surface parity for the render mode.
 *
 * A render-surface leaf declares its render mode twice: on the server
 * `LeafDescriptor` and on its JS registration, under the same id. The two MUST
 * be equal, or the host renders the leaf one way while discovery reports the
 * other. This class compares the server side, read from the leaf registry, with
 * the JS side the parity check hands in, and lists every leaf where they differ.
 *
 * A JS registration that states no render mode is `component`, the same default
 * the server applies. A leaf known to only one layer is not a render-mode
 * mismatch: whether a leaf is registered on both layers is the tab/widget half
 * of parity, which this class does not judge. Leaves without the render-surface
 * kind have no render mode to correlate and are skipped.
 *
 * @spec openspec/changes/leaf-mount-render-escape-hatch/specs/leaf-mount-render/spec.md
 */
final class LeafRenderModeParity {

	/**
	 * Constructor.
	 *
	 * @param LeafRegistryInterface $leafRegistry The server-side leaf registry.
	 */
	public function __construct(
		private readonly LeafRegistryInterface $leafRegistry,
	) {
	}//end __construct()

	/**
	 * Every render-surface leaf whose server and JS render modes differ.
	 *
	 * @param array<string, mixed> $jsRenderModes The JS registrations' render modes keyed by leaf id;
	 *                                            null or an empty string means the registration
	 *                                            stated none.
	 *
	 * @return array<int, array{id: string, server: string, js: string}> One row per mismatch,
	 *                                                                    in server registration order.
	 *
	 * @spec openspec/changes/leaf-mount-render-escape-hatch/specs/leaf-mount-render/spec.md
	 */
	public function mismatches(array $jsRenderModes): array {
		$mismatches = [];
		foreach ($this->leafRegistry->getDescriptors() as $descriptor) {
			if ($descriptor->hasKind(kind: LeafDescriptor::KIND_RENDER_SURFACE) === false) {
				continue;
			}

			$id = $descriptor->getId();
			if (array_key_exists($id, $jsRenderModes) === false) {
				continue;
			}

			$jsMode = self::jsRenderMode(value: $jsRenderModes[$id]);
			if ($jsMode === $descriptor->getRenderMode()) {
				continue;
			}

			$mismatches[] = ['id' => $id, 'server' => $descriptor->getRenderMode(), 'js' => $jsMode];
		}

		return $mismatches;
	}//end mismatches()

	/**
	 * One human-readable violation line per mismatch, for a parity report.
	 *
	 * @param array<string, mixed> $jsRenderModes The JS registrations' render modes keyed by leaf id.
	 *
	 * @return array<int, string> The violation lines; empty when the layers agree.
	 *
	 * @spec openspec/changes/leaf-mount-render-escape-hatch/specs/leaf-mount-render/spec.md
	 */
	public function violations(array $jsRenderModes): array {
		$lines = [];
		foreach ($this->mismatches(jsRenderModes: $jsRenderModes) as $row) {
			$lines[] = sprintf(
				'leaf "%s": server renderMode "%s" differs from JS registration renderMode "%s"',
				$row['id'],
				$row['server'],
				$row['js']
			);
		}

		return $lines;
	}//end violations()

	/**
	 * The JS render mode as the server reads it: unstated is `component`.
	 *
	 * A value that is not a string is reported as its JSON spelling, so a
	 * malformed registration still shows up as a mismatch rather than passing.
	 *
	 * @param mixed $value The JS registration's renderMode value.
	 *
	 * @return string The normalised render mode.
	 */
	private static function jsRenderMode(mixed $value): string {
		if ($value === null || $value === '') {
			return LeafDescriptor::RENDER_MODE_COMPONENT;
		}

		if (is_string($value) === true) {
			return $value;
		}

		return (string) json_encode($value);
	}//end jsRenderMode()
}//end class
