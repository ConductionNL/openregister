<?php

/**
 * LinkedTypesRule: the linkedTypes configuration value.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db\Schema
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

namespace OCA\OpenRegister\Db\Schema;

use InvalidArgumentException;

/**
 * Checks `linkedTypes` against the integration registry and the legacy ids.
 *
 * No strict_types: moved out of Schema, which declares none, so every value
 * keeps the coercion it had there.
 *
 * @spec exclude schema plumbing moved out of the entity, not a capability of its own
 */
final class LinkedTypesRule {
	/**
	 * Validate the linkedTypes configuration value.
	 *
	 * Registry-driven validation per AD-5 of pluggable-integration-registry:
	 * an id is valid when it appears in the registry's `listIds()`
	 * output OR in the legacy-id allow-list returned by
	 * `legacyLinkedTypeIds()` (see that method for the rationale — it
	 * preserves backwards-compat for `linkedTypes` values that were
	 * created before the matching provider was renamed/added).
	 *
	 * When the integration registry isn't reachable (entity built
	 * outside a request context, e.g. unit tests constructing Schema
	 * directly), validation falls back to the legacy allow-list alone.
	 *
	 * Per `cleanup-linked-entity-type-map`, the public linked-types
	 * constant was removed; the surviving allow-list lives inside
	 * `legacyLinkedTypeIds()` as an implementation detail.
	 *
	 * @param mixed $value The linkedTypes value to validate.
	 *
	 * @throws InvalidArgumentException If validation fails.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/pluggable-integration-registry/tasks.md#task-7
	 * @spec openspec/specs/cleanup-linked-entity-type-map/spec.md "Registry-Driven Behaviour Unchanged"
	 */
	public function validateLinkedTypesValue(mixed $value): void {
		if ((new ConfigurationValueRules())->isAbsentConfig(value: $value, refusal: "Configuration 'linkedTypes' must be an array or null") === true) {
			return;
		}

		$known = ['registry' => $this->resolveIntegrationRegistryIds(), 'legacy' => self::legacyLinkedTypeIds()];
		foreach ($value as $type) {
			$this->assertLinkedType(type: $type, known: $known);
		}
	}//end validateLinkedTypesValue()

	/**
	 * Refuse a linked type that is no string, or that neither the legacy allow-list nor the registry knows.
	 *
	 * @param mixed                                                      $type  One configured linked type.
	 * @param array{registry: array<int, string>, legacy: array<int, string>} $known The registry ids and the legacy ids.
	 *
	 * @throws InvalidArgumentException If the type is not valid.
	 *
	 * @return void
	 */
	private function assertLinkedType(mixed $type, array $known): void {
		if (is_string($type) === false) {
			throw new InvalidArgumentException("All values in 'linkedTypes' must be strings");
		}

		if (in_array($type, $known['legacy'], true) === true || in_array($type, $known['registry'], true) === true) {
			return;
		}

		$combined = array_unique(array_merge($known['legacy'], $known['registry']));
		sort($combined);
		throw new InvalidArgumentException("Invalid linked type '$type'. Valid values: " . implode(', ', $combined));
	}//end assertLinkedType()

	/**
	 * Legacy linked-type id allow-list — internal implementation detail.
	 *
	 * Returns the set of legacy `linkedTypes` ids that were valid
	 * before the IntegrationRegistry became the authoritative source.
	 * Preserved so existing schemas declaring `linkedTypes: ['mail',
	 * 'todos', ...]` keep validating after the public linked-types
	 * constant was removed by `cleanup-linked-entity-type-map`.
	 * New consumers SHOULD register
	 * a provider via `IntegrationRegistry::addProvider()`; this
	 * allow-list MUST NOT grow.
	 *
	 * NOT exposed as a public constant — the public symbol was the
	 * removal target. Method form keeps the values reachable from
	 * tests and inspectable in PHPStan without making them part of OR's
	 * API surface.
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/specs/cleanup-linked-entity-type-map/spec.md "Constants Removed"
	 */
	private static function legacyLinkedTypeIds(): array {
		return [
			'files',
			'mail',
			'contacts',
			'notes',
			'todos',
			'calendar',
			'talk',
			'deck',
		];
	}//end legacyLinkedTypeIds()

	/**
	 * Resolve the current set of registered integration ids.
	 *
	 * Schema is a Nextcloud Entity, not a service, so DI does not reach it,
	 * and this is the one place in lib/ (outside AppInfo, AppHost and
	 * Migration) that still reads the global server. It runs from
	 * setConfiguration(), which every mapper calls while hydrating a row and
	 * which some thirty call sites reach; threading the registry ids through
	 * all of them is a change of its own. Until then the lookup stays behind
	 * the isset() guard: without a booted container (unit tests, occ before
	 * boot) it returns an empty list and the legacy allow-list keeps working.
	 *
	 * @return array<int,string> Registered integration ids, possibly empty.
	 */
	private function resolveIntegrationRegistryIds(): array {
		if (class_exists('\OC') === false || isset(\OC::$server) === false) {
			return [];
		}

		try {
			// phpcs:ignore CustomSniffs.Nextcloud.NoLegacyServerAccessors,CustomSniffs.Nextcloud.NoServiceLocator.GlobalContainerLookup -- entity, not DI-built; see the docblock above.
			$registry = \OC::$server->get(
				\OCA\OpenRegister\Service\Integration\IntegrationRegistry::class
			);
			if ($registry instanceof \OCA\OpenRegister\Service\Integration\IntegrationRegistry) {
				return $registry->listIds();
			}
		} catch (\Throwable $e) {
			// Registry binding not available — fall back to legacy list only.
		}

		return [];
	}//end resolveIntegrationRegistryIds()
}//end class
