<?php

/**
 * OpenRegister AppHost — LeafAdoption
 *
 * What one leaf app asked {@see Bootstrap::register()} to wire: its app id
 * and the overrides it passed, read as typed values with their defaults.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category AppHost
 * @package  OCA\OpenRegister\AppHost
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/apphost-boilerplate/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\AppHost;

/**
 * One leaf app's adoption request: the app id plus the documented overrides of
 * {@see Bootstrap::register()}.
 *
 * @spec openspec/specs/apphost-boilerplate/spec.md
 */
final class LeafAdoption {

	/**
	 * Hold the app id and the overrides.
	 *
	 * @param string               $appId   Leaf app id.
	 * @param array<string, mixed> $options The overrides documented on {@see Bootstrap::register()}.
	 *
	 * @spec openspec/specs/apphost-boilerplate/spec.md
	 */
	public function __construct(
		public readonly string $appId,
		private readonly array $options = [],
	) {
	}//end __construct()

	/**
	 * The StudlyCase form of the app id (`pet_store` / `pet-store` → `PetStore`).
	 *
	 * @return string StudlyCase form.
	 *
	 * @spec openspec/specs/apphost-boilerplate/spec.md
	 */
	public function studly(): string {
		$parts = preg_split(pattern: '/[_\-]+/', subject: $this->appId);
		if ($parts === false || count($parts) === 0) {
			$parts = [$this->appId];
		}

		$studly = '';
		foreach ($parts as $part) {
			$studly .= ucfirst($part);
		}//end foreach

		return $studly;
	}//end studly()

	/**
	 * A leaf namespace: its override, or the base namespace plus the suffix.
	 *
	 * @param string $option The override key (e.g. `controllerNamespace`).
	 * @param string $suffix The segment under the base namespace (e.g. `Controller`).
	 *
	 * @return string The namespace.
	 *
	 * @spec openspec/specs/apphost-boilerplate/spec.md
	 */
	public function namespace(string $option, string $suffix): string {
		$base = rtrim(string: (string)($this->options['namespace'] ?? ('OCA\\' . $this->studly())), characters: '\\');
		return (string)($this->options[$option] ?? ($base . '\\' . $suffix));
	}//end namespace()

	/**
	 * The admin section the settings form and section register under.
	 *
	 * @return array{id: string, name: string, icon: string, priority: int, adminPriority: int} The section.
	 *
	 * @spec openspec/specs/apphost-boilerplate/spec.md
	 */
	public function section(): array {
		return [
			'id' => (string)($this->options['sectionId'] ?? $this->appId),
			'name' => (string)($this->options['sectionName'] ?? $this->studly()),
			'icon' => (string)($this->options['sectionIcon'] ?? 'app-dark.svg'),
			'priority' => (int)($this->options['sectionPriority'] ?? 75),
			'adminPriority' => (int)($this->options['adminPriority'] ?? 10),
		];
	}//end section()

	/**
	 * Whether a default-on feature flag (`deepLinks`, `observability`) is left on.
	 *
	 * @param string $flag The flag key.
	 *
	 * @return bool False only when the leaf passed `false`.
	 *
	 * @spec openspec/specs/apphost-boilerplate/spec.md
	 */
	public function enabled(string $flag): bool {
		return ($this->options[$flag] ?? true) !== false;
	}//end enabled()

	/**
	 * The dashboard widget classes to register.
	 *
	 * @return array<mixed> The widget classes.
	 *
	 * @spec openspec/specs/apphost-boilerplate/spec.md
	 */
	public function dashboardWidgets(): array {
		return (array)($this->options['dashboardWidgets'] ?? []);
	}//end dashboardWidgets()

	/**
	 * The MCP tool provider class to alias, when the leaf named one.
	 *
	 * @return string|null The provider class.
	 *
	 * @spec openspec/specs/apphost-boilerplate/spec.md
	 */
	public function mcpProvider(): ?string {
		if (isset($this->options['mcpProvider']) === false) {
			return null;
		}

		return (string)$this->options['mcpProvider'];
	}//end mcpProvider()
}//end class
