<?php

/**
 * CredentialStore — the credential-broker secret-store abstraction (leaf seam).
 *
 * A `CredentialStore` holds the raw secret for a `credential` OR object, keyed by
 * that object's UUID. The metadata (name, provider, owner, allowedApps) lives in
 * the OR object; the SECRET never does — it lives only behind this interface
 * (design.md D1/D3). The first concrete leaf is {@see NextcloudVaultCredentialStore},
 * backed by Nextcloud's encrypted per-user vault. Future leaves (HashiCorp Vault,
 * AWS KMS) implement the same three methods and slot in behind DI without touching
 * the broker — the OR "leaf" pattern (cf. `integration-leaf-foundation`).
 *
 * The secret value passed to {@see put()} and returned by {@see get()} MUST NEVER
 * be logged, persisted to an OR object, exported, or returned in any API response.
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
 * Abstraction over the backing store that holds a credential's raw secret.
 */
interface CredentialStore
{
    /**
     * Store (or overwrite) the secret for a credential.
     *
     * The `scope` selects the vault owner the secret is stored under: `personal`
     * (the default) keeps the pre-existing per-user behaviour; `organisation`
     * stores under a reserved system identity so no user owns the shared secret
     * (credential-broker-organisation-scope design D2).
     *
     * @param string $uuid   The owning `credential` object's UUID (the vault key).
     * @param string $secret The raw secret to store. Never logged or persisted to an OR object.
     * @param string $scope  The credential scope (`personal`|`organisation`); selects the vault owner.
     *
     * @return void
     *
     * @spec openspec/changes/credential-broker-organisation-scope/specs/credential-broker/spec.md#organisation-secret-storage
     */
    public function put(string $uuid, string $secret, string $scope='personal'): void;

    /**
     * Retrieve the secret for a credential, or null when none is stored.
     *
     * @param string $uuid  The owning `credential` object's UUID (the vault key).
     * @param string $scope The credential scope (`personal`|`organisation`); selects the vault owner.
     *
     * @return string|null The raw secret, or null when absent. Never logged.
     *
     * @spec openspec/changes/credential-broker-organisation-scope/specs/credential-broker/spec.md#organisation-secret-storage
     */
    public function get(string $uuid, string $scope='personal'): ?string;

    /**
     * Delete the secret for a credential (idempotent — a no-op when absent).
     *
     * @param string $uuid  The owning `credential` object's UUID (the vault key).
     * @param string $scope The credential scope (`personal`|`organisation`); selects the vault owner.
     *
     * @return void
     *
     * @spec openspec/changes/credential-broker-organisation-scope/specs/credential-broker/spec.md#organisation-secret-storage
     */
    public function delete(string $uuid, string $scope='personal'): void;
}//end interface
