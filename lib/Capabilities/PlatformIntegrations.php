<?php

/**
 * The platform integrations OpenRegister offers Nextcloud, and which are on.
 *
 * One programme, ten Nextcloud interfaces, one change per interface. Each
 * entry names the interface's change and the OpenRegister class that
 * implements it; an integration whose change has not been built yet has no
 * class and is reported off. A change that builds one adds its class here.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Capabilities
 * @package  OCA\OpenRegister\Capabilities
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/platform-capability/specs/deep-link-registry/spec.md#requirement-the-instance-publishes-a-capability-block-with-one-block-per-claiming-app-req-pca-001
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Capabilities;

/**
 * The platform integration catalogue.
 *
 * @spec openspec/changes/platform-capability/specs/deep-link-registry/spec.md#requirement-the-instance-publishes-a-capability-block-with-one-block-per-claiming-app-req-pca-001
 */
final class PlatformIntegrations {

	/**
	 * Integration name => the OpenRegister class implementing it, or null when not built.
	 *
	 * @var array<string, class-string|null>
	 */
	public const IMPLEMENTATIONS = [
		'caldav-backend' => \OCA\OpenRegister\Calendar\RegisterCalendarProvider::class,
		'capability' => PlatformCapability::class,
		'cloud-federation-provider' => \OCA\OpenRegister\Federation\OpenRegisterCloudFederationProvider::class,
		'collaboration-resources' => null,
		'comments-entity' => null,
		'reference-provider' => \OCA\OpenRegister\Reference\ObjectReferenceProvider::class,
		'search-provider' => \OCA\OpenRegister\Search\ObjectsProvider::class,
		'share-provider' => null,
		'team-resource-provider' => null,
		'user-migrator' => null,
	];

	/**
	 * Every integration with whether it is on.
	 *
	 * @return array<string, bool> Integration name => on.
	 *
	 * @spec openspec/changes/platform-capability/specs/deep-link-registry/spec.md#requirement-the-instance-publishes-a-capability-block-with-one-block-per-claiming-app-req-pca-001
	 */
	public static function switches(): array {
		return array_map(static fn (?string $implementation): bool => $implementation !== null, self::IMPLEMENTATIONS);
	}//end switches()

	/**
	 * The names of the integrations that are on.
	 *
	 * @return list<string> The names, in catalogue order.
	 *
	 * @spec openspec/changes/platform-capability/specs/deep-link-registry/spec.md#requirement-the-instance-publishes-a-capability-block-with-one-block-per-claiming-app-req-pca-001
	 */
	public static function enabled(): array {
		return array_keys(array_filter(self::switches()));
	}//end enabled()
}//end class
