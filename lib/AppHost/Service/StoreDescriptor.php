<?php

/**
 * OpenRegister AppHost — Store Descriptor
 *
 * Immutable value object naming the per-app parameters of the ADR-080 store
 * plane. Everything that differs between openbuild's application-template
 * store, openconnector's connector store and hermiq's agent-template store is
 * carried here; everything else lives once in GenericStoreService.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\AppHost\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/specs/apphost-store-plane/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\AppHost\Service;

/**
 * Per-app parameters for the generic store client (ADR-080 Decision 2).
 *
 * @spec openspec/specs/apphost-store-plane/spec.md
 */
final class StoreDescriptor {
	/**
	 * App whose IAppConfig holds the registry connection.
	 *
	 * @var string
	 */
	public readonly string $appId;

	/**
	 * Remote schema slug exposed by the registry.
	 *
	 * @var string
	 */
	public readonly string $schema;

	/**
	 * Remote register segment used when `registry_register` is unset/empty.
	 *
	 * @var string
	 */
	public readonly string $defaultRegister;

	/**
	 * Card field name => remote object property.
	 *
	 * @var array<string, string>
	 */
	public readonly array $cardFields;

	/**
	 * Shareable configuration type ids the calling app declared.
	 *
	 * @var array<int, string>
	 */
	public readonly array $types;

	/**
	 * Remote object properties a publish may send, next to the slug.
	 *
	 * @var array<int, string>
	 */
	public readonly array $publishFields;

	/**
	 * Nextcloud groups whose members may publish.
	 *
	 * @var array<int, string>
	 */
	public readonly array $publishGroups;

	/**
	 * Constructor.
	 *
	 * The descriptor is built from two cohesive parts: where the store reads
	 * from, and what it shares. Readers keep the flat properties above, so
	 * nothing that consumes a descriptor changes.
	 *
	 * @param StoreListing $listing Registry connection app, remote schema and register, card field map.
	 * @param StoreSharing $sharing Shareable types and the publish opt-in. Defaults to sharing nothing.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly StoreListing $listing,
		public readonly StoreSharing $sharing = new StoreSharing(),
	) {
		$this->appId           = $listing->appId;
		$this->schema          = $listing->schema;
		$this->defaultRegister = $listing->defaultRegister;
		$this->cardFields      = $listing->cardFields;
		$this->types           = $sharing->types;
		$this->publishFields   = $sharing->publishFields;
		$this->publishGroups   = $sharing->publishGroups;
	}//end __construct()

	/**
	 * Whether this descriptor opted in to publishing.
	 *
	 * Both lists must hold something: the fields say WHAT may leave this
	 * server, the groups say WHO the app decided may send it. A descriptor
	 * written before publishing existed has neither, and stays read-only.
	 *
	 * @return bool True when at least one field and one non-empty group are named.
	 *
	 * @spec openspec/specs/apphost-store-plane/spec.md#requirement-a-descriptor-must-opt-in-to-publishing-by-naming-its-fields-and-its-groups
	 */
	public function isPublishable(): bool {
		return $this->publishFields !== [] && $this->namedPublishGroups() !== [];
	}//end isPublishable()

	/**
	 * The publish groups with blank entries removed.
	 *
	 * A list holding only an empty string names nobody, and must not count as
	 * a decision the app made.
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/specs/apphost-store-plane/spec.md#requirement-only-a-user-the-apps-named-groups-admit-may-publish
	 */
	public function namedPublishGroups(): array {
		$named = [];
		foreach ($this->publishGroups as $group) {
			$group = trim((string)$group);
			if ($group !== '') {
				$named[] = $group;
			}
		}

		return array_values(array_unique($named));
	}//end namedPublishGroups()

	/**
	 * Whether this descriptor selects federated configuration discovery.
	 *
	 * @return bool True when the app declared at least one shareable type.
	 *
	 * @spec openspec/changes/store-over-federated-config/specs/apphost-store-plane/spec.md#requirement-a-store-descriptor-must-carry-every-per-app-parameter
	 */
	public function isFederated(): bool {
		return $this->types !== [];
	}//end isFederated()
}//end class
