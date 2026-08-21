<?php

/**
 * AuditTrailProvider — exposes audit-trail entries for an OR object
 * via the IntegrationProvider contract.
 *
 * Storage strategy is `query-time` (AD-22): audit-trail entries are
 * OR's own data; the provider always reads live from `AuditTrailMapper`
 * rather than maintaining a parallel link table. Mutation methods
 * throw NotImplementedException — audit-trail entries are immutable
 * by construction.
 *
 * Always available — no required NC app — so `requiredApp` returns
 * null and `isEnabled()` is hardcoded true.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Integration\BuiltinProviders
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/pluggable-integration-registry/tasks.md#task-16
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Integration\BuiltinProviders;

use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Service\Integration\AbstractIntegrationProvider;
use OCP\IL10N;

/**
 * Audit-trail integration provider (read-only, query-time).
 */
class AuditTrailProvider extends AbstractIntegrationProvider {
	/**
	 * Constructor.
	 *
	 * @param AuditTrailMapper $mapper Audit-trail mapper.
	 * @param IL10N $l10n Localisation.
	 *
	 * @return void
	 */
	public function __construct(
		private AuditTrailMapper $mapper,
		private IL10N $l10n,
	) {
	}//end __construct()

	/**
	 * Stable provider id used in routes and configs.
	 *
	 * @return string Stable provider identifier.
	 */
	public function getId(): string {
		return 'audit-trail';
	}//end getId()

	/**
	 * Translated, human-readable provider label.
	 *
	 * @return string Translated, human-readable provider label.
	 */
	public function getLabel(): string {
		return $this->l10n->t('Audit trail');
	}//end getLabel()

	/**
	 * MDI icon name for the provider.
	 *
	 * @return string MDI icon name for the provider.
	 */
	public function getIcon(): string {
		return 'History';
	}//end getIcon()

	/**
	 * Group identifier for UI grouping (or null).
	 *
	 * @return string|null Group identifier for UI grouping.
	 */
	public function getGroup(): ?string {
		return 'core';
	}//end getGroup()

	/**
	 * Required NC app id (null = built-in).
	 *
	 * @return string|null Required app id (null = built-in).
	 */
	public function getRequiredApp(): ?string {
		return null;
	}//end getRequiredApp()

	/**
	 * Storage strategy hint for the registry.
	 *
	 * @return string Storage strategy hint for the registry.
	 */
	public function getStorageStrategy(): string {
		return 'query-time';
	}//end getStorageStrategy()

	/**
	 * True when the provider is available for use.
	 *
	 * @return bool True when the provider is available for use.
	 */
	public function isEnabled(): bool {
		return true;
	}//end isEnabled()

	/**
	 * List audit-trail entries for an OR object.
	 *
	 * Best-effort delegation to `AuditTrailMapper::findAllByObject` /
	 * `findAll` depending on the mapper's exposed API. Returns an
	 * empty list rather than 500 when the mapper signature doesn't
	 * match — the umbrella's controller refactor (tasks 18-22) will
	 * tighten this.
	 *
	 * @param string $register Register slug or numeric id.
	 * @param string $schema Schema slug or numeric id.
	 * @param string $objectId Object uuid.
	 * @param array<string,mixed> $filters Reserved.
	 *
	 * @return array<int,array<string,mixed>>
	 *
	 * @spec openspec/changes/pluggable-integration-registry/tasks.md#task-16
	 */
	public function list(string $register, string $schema, string $objectId, array $filters = []): array {
		try {
			if (method_exists($this->mapper, 'findAllByObject') === true) {
				$entries = $this->mapper->findAllByObject($objectId);
				return $this->normalize(entries: $entries);
			}

			if (method_exists($this->mapper, 'findAll') === true) {
				// The audit table's `object` column is INTEGER (numeric
				// object id) while `object_uuid` is the UUID string the
				// sub-resource controller actually receives. Try the
				// UUID-typed filter first and fall back to `object` for
				// older schemas.
				try {
					$entries = $this->mapper->findAll(filters: ['object_uuid' => $objectId]);
					if (count($entries) > 0) {
						return $this->normalize(entries: $entries);
					}
				} catch (\Throwable $e) {
					// Fall through to legacy filter.
					unset($e);
				}

				$entries = $this->mapper->findAll(filters: ['object' => $objectId]);
				return $this->normalize(entries: $entries);
			}
		} catch (\Throwable $e) {
			// AuditTrail history is a soft surface — never block the
			// detail page on a stale or missing audit row.
		}//end try

		return [];
	}//end list()

	/**
	 * Convert mapper output (Entity[] or array[]) into the shape
	 * IntegrationProvider::list() promises.
	 *
	 * @param mixed $entries Mapper output.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function normalize($entries): array {
		if (is_array($entries) === false) {
			return [];
		}

		$rows = [];
		foreach ($entries as $entry) {
			if (is_object($entry) === true && method_exists($entry, 'jsonSerialize') === true) {
				$serialised = $entry->jsonSerialize();
				$rowValue = ['value' => $serialised];
				if (is_array($serialised) === true) {
					$rowValue = $serialised;
				}

				$rows[] = $rowValue;
				continue;
			}

			if (is_array($entry) === true) {
				$rows[] = $entry;
			}
		}

		return $rows;
	}//end normalize()
}//end class
