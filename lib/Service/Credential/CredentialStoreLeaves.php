<?php

/**
 * CredentialStoreLeaves — the two credential-store leaves the resolver picks between.
 *
 * A plain constructor bundle wired by DI autowiring: it holds the Doriath
 * application-vault leaf and the Nextcloud per-user vault leaf so
 * {@see CredentialStoreResolver} receives them as one collaborator. It makes
 * no selection itself — eligibility stays in the resolver.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Credential
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Credential;

/**
 * Bundle of the Doriath leaf (preferred) and the NC-vault leaf (fallback).
 *
 * @spec openspec/specs/credential-broker/spec.md#doriath-backed-secret-custody
 */
final class CredentialStoreLeaves {
	/**
	 * Bundle the leaves.
	 *
	 * @param DoriathCredentialStore        $doriathStore The Doriath-backed leaf (selected when eligible).
	 * @param NextcloudVaultCredentialStore $vaultStore   The NC-vault leaf (fallback).
	 *
	 * @spec openspec/specs/credential-broker/spec.md
	 */
	public function __construct(
		public readonly DoriathCredentialStore $doriathStore,
		public readonly NextcloudVaultCredentialStore $vaultStore,
	) {
	}//end __construct()
}//end class
