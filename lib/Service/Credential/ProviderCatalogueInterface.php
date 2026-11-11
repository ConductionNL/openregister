<?php

/**
 * ProviderCatalogueInterface: what callers outside lib/Service use of ProviderCatalogue.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Credential
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

namespace OCA\OpenRegister\Service\Credential;

/**
 * The part of ProviderCatalogue that code outside lib/Service uses.
 */
interface ProviderCatalogueInterface {

	/**
	 * Return one catalogue entry by provider identifier, or null when unknown.
	 *
	 * @param string $providerId The catalogue provider identifier (e.g. `github`).
	 *
	 * @return array<string, mixed>|null The provider entry, or null.
	 *
	 * @spec openspec/specs/credential-broker/spec.md
	 */
	public function get(string $providerId): ?array;

	/**
	 * Return the whole provider map (`identifier => entry`).
	 *
	 * Values are EXPECTED to be entry arrays, but load() only validates the
	 * top-level `providers` map — not each entry — so consumers must guard
	 * per-entry shape themselves (the file is read unvalidated from disk).
	 *
	 * @return array<string, mixed> The parsed provider catalogue.
	 *
	 * @spec openspec/specs/credential-broker/spec.md
	 */
	public function all(): array;
}//end interface
