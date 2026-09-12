<?php

/**
 * OpenRegister RegistryUpdateTargetGuard
 *
 * Resolves one `RegistrySubscription` row's schema and decides whether an
 * inbound update's properties may be applied to it. Extracted out of
 * {@see RegistrySubscriptionService} to keep that service's coupling under
 * this repo's PHPMD threshold: bundling "fetch the schema" and "check the
 * properties against it" behind one collaborator removes two direct
 * dependencies (`SchemaMapper`, `RegistryOwnedPropertyGuard`) for one.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Registry
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Registry;

use OCA\OpenRegister\Db\RegistrySubscription;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use Throwable;

/**
 * Decides whether an inbound update's properties may be applied to one
 * subscribed object.
 *
 * @spec openspec/changes/registry-subscriptions/specs/registry-subscriptions/spec.md#requirement-an-inbound-registry-update-writes-owned-properties-only
 */
class RegistryUpdateTargetGuard {

	/**
	 * Constructor.
	 *
	 * @param SchemaMapper $schemaMapper Resolves the row's schema.
	 * @param RegistryOwnedPropertyGuard $ownedPropertyGuard The pure owned-vs-supplied decision.
	 */
	public function __construct(
		private readonly SchemaMapper $schemaMapper,
		private readonly RegistryOwnedPropertyGuard $ownedPropertyGuard,
	) {
	}//end __construct()

	/**
	 * Evaluate one subscription row against a supplied property set.
	 *
	 * @param RegistrySubscription $row The subscription row to check.
	 * @param array<string, mixed> $properties The inbound update's supplied properties.
	 *
	 * @return array{allowed: bool, rejected: array<int, string>}
	 */
	public function evaluate(RegistrySubscription $row, array $properties): array {
		try {
			$schema = $this->schemaMapper->find(id: (string)$row->getSchema());
		} catch (Throwable) {
			// The row's schema no longer resolves (deleted since the
			// subscription was requested). Refuse rather than apply an
			// update against a schema nobody can confirm still owns these
			// properties.
			return ['allowed' => false, 'rejected' => array_keys($properties)];
		}

		$owned = $this->ownedPropertiesOf(schema: $schema);
		if ($owned === null) {
			// The `x-openregister-registry` annotation was removed after the
			// subscription was requested. Same reasoning as above: refuse.
			return ['allowed' => false, 'rejected' => array_keys($properties)];
		}

		return $this->ownedPropertyGuard->check(owned: $owned, supplied: $properties);
	}//end evaluate()

	/**
	 * Read the `owned` list off a schema's `x-openregister-registry`
	 * annotation, or null when the schema does not declare one.
	 *
	 * Deliberately duplicates the small array-read
	 * {@see RegistrySubscriptionService::annotationFor()} also performs,
	 * rather than sharing it through a new dependency — the whole point of
	 * this class existing is keeping that service's coupling count down.
	 *
	 * @param Schema $schema The schema to read.
	 *
	 * @return array<int, string>|null
	 */
	private function ownedPropertiesOf(Schema $schema): ?array {
		$configuration = ($schema->getConfiguration() ?? []);
		$annotation = ($configuration['x-openregister-registry'] ?? null);
		if (is_array($annotation) === false) {
			return null;
		}

		$owned = $annotation['owned'] ?? [];
		if (is_array($owned) === false) {
			return [];
		}

		return array_values(array_map('strval', $owned));
	}//end ownedPropertiesOf()
}//end class
