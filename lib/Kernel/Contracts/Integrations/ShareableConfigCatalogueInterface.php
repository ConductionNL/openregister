<?php
/**
 * ShareableConfigCatalogueInterface: published configuration, as the store surface asks for it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts\Integrations
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts\Integrations;

use OCA\OpenRegister\AppHost\Store\DeclaredShareableType;
use OCA\OpenRegister\Kernel\Contracts\Value\BundleObjectStamp;

/**
 * The shareable configuration behind a store that exchanges configuration rather than rows.
 *
 * The store surface (Integrations) asks; Configuration owns the shareable types,
 * discovery, trust and install, and binds its answer in its own bindings.
 *
 * @spec openspec/changes/store-over-federated-config/specs/apphost-store-plane/spec.md#requirement-a-store-must-be-able-to-offer-configuration-not-only-objects
 */
interface ShareableConfigCatalogueInterface {

	/**
	 * The shareable type an app registered under a declared type id.
	 *
	 * @param string $typeId The declared type id.
	 *
	 * @return DeclaredShareableType|null The type, or null when no app owns the id.
	 *
	 * @spec openspec/changes/store-over-federated-config/specs/apphost-store-plane/spec.md#requirement-a-store-must-be-able-to-offer-configuration-not-only-objects
	 */
	public function declaredType(string $typeId): ?DeclaredShareableType;

	/**
	 * The repositories published under a discovery topic.
	 *
	 * @param string $topic The topic of a shareable type.
	 *
	 * @return array<int, array<string, mixed>> The published repositories.
	 *
	 * @spec openspec/changes/store-over-federated-config/specs/apphost-store-plane/spec.md#requirement-a-store-must-be-able-to-offer-configuration-not-only-objects
	 */
	public function discover(string $topic): array;

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
	public function fetchBundle(string $repo, string $path): array;

	/**
	 * Whether this organisation trusts a source to install from.
	 *
	 * @param string $source The source of a bundle.
	 *
	 * @return bool True when it may be installed from.
	 *
	 * @spec openspec/changes/store-over-federated-config/specs/apphost-store-plane/spec.md#requirement-a-store-must-be-able-to-offer-configuration-not-only-objects
	 */
	public function isSourceAllowed(string $source): bool;

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
	public function install(string $typeId, array $bundle, string $source, ?BundleObjectStamp $stamp = null): array;
}//end interface
