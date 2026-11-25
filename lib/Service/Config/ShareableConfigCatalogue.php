<?php

/**
 * OpenRegister Shareable Config Catalogue
 *
 * The shareable configuration a store surface browses and installs.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Config
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/changes/store-over-federated-config/specs/apphost-store-plane/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Config;

use OCA\OpenRegister\AppHost\Store\DeclaredShareableType;
use OCA\OpenRegister\Kernel\Contracts\Integrations\ShareableConfigCatalogueInterface;
use OCA\OpenRegister\Kernel\Contracts\Value\BundleObjectStamp;

/**
 * Answers the store surface from the shareable type registry and the federated engine.
 *
 * Discovery, signature checking, trust and install stay in FederatedConfigService;
 * this class only hands the store what it asks, in the store's terms.
 *
 * @spec openspec/changes/store-over-federated-config/specs/apphost-store-plane/spec.md#requirement-a-store-must-be-able-to-offer-configuration-not-only-objects
 */
final class ShareableConfigCatalogue implements ShareableConfigCatalogueInterface {

	/**
	 * Constructor.
	 *
	 * @param ShareableConfigTypeRegistryInterface $registry  Resolves a declared type id to its type.
	 * @param FederatedConfigServiceInterface      $federated Discovery, fetch, trust and install.
	 */
	public function __construct(
		private readonly ShareableConfigTypeRegistryInterface $registry,
		private readonly FederatedConfigServiceInterface $federated,
	) {
	}//end __construct()

	/**
	 * The shareable type an app registered under a declared type id.
	 *
	 * @param string $typeId The declared type id.
	 *
	 * @return DeclaredShareableType|null The type, or null when no app owns the id.
	 *
	 * @spec openspec/changes/store-over-federated-config/specs/apphost-store-plane/spec.md#requirement-a-store-must-be-able-to-offer-configuration-not-only-objects
	 */
	public function declaredType(string $typeId): ?DeclaredShareableType {
		$type = $this->registry->get(id: $typeId);
		if ($type === null) {
			return null;
		}

		return new DeclaredShareableType(typeId: $typeId, typeName: $type->getDisplayName(), topic: $type->getTopic());
	}//end declaredType()

	/**
	 * The repositories published under a discovery topic.
	 *
	 * @param string $topic The topic of a shareable type.
	 *
	 * @return array<int, array<string, mixed>> The published repositories.
	 *
	 * @spec openspec/changes/store-over-federated-config/specs/apphost-store-plane/spec.md#requirement-a-store-must-be-able-to-offer-configuration-not-only-objects
	 */
	public function discover(string $topic): array {
		return $this->federated->discover(topic: $topic);
	}//end discover()

	/**
	 * The bundle a published repository carries at a path.
	 *
	 * @param string $repo The repository, as `owner/name`.
	 * @param string $path The path of the bundle in it.
	 *
	 * @return array<string, mixed> The bundle.
	 *
	 * @spec openspec/changes/store-over-federated-config/specs/apphost-store-plane/spec.md#requirement-a-store-must-be-able-to-offer-configuration-not-only-objects
	 */
	public function fetchBundle(string $repo, string $path): array {
		return $this->federated->fetchBundle(repo: $repo, path: $path);
	}//end fetchBundle()

	/**
	 * Whether this organisation trusts a source to install from.
	 *
	 * @param string $source The source of a bundle.
	 *
	 * @return bool True when it may be installed from.
	 *
	 * @spec openspec/changes/store-over-federated-config/specs/apphost-store-plane/spec.md#requirement-a-store-must-be-able-to-offer-configuration-not-only-objects
	 */
	public function isSourceAllowed(string $source): bool {
		return $this->federated->isSourceAllowed(source: $source);
	}//end isSourceAllowed()

	/**
	 * Install a bundle through the shareable type that owns it.
	 *
	 * @param string               $typeId The declared type id.
	 * @param array<string, mixed> $bundle The bundle.
	 * @param string               $source Where the bundle came from.
	 * @param BundleObjectStamp|null $stamp The install target's stamp, applied after the signature check.
	 *
	 * @return array<string, mixed> The install report.
	 *
	 * @spec openspec/changes/store-over-federated-config/specs/apphost-store-plane/spec.md#requirement-a-configuration-install-must-run-through-its-owning-type
	 * @spec openspec/changes/store-install-target/specs/apphost-store-plane/spec.md#requirement-an-install-can-go-into-a-target-the-app-declares
	 */
	public function install(string $typeId, array $bundle, string $source, ?BundleObjectStamp $stamp = null): array {
		$prepare = null;
		if ($stamp !== null) {
			$prepare = $stamp->applyTo(...);
		}

		return $this->federated->install(typeId: $typeId, bundle: $bundle, source: $source, prepare: $prepare);
	}//end install()
}//end class
