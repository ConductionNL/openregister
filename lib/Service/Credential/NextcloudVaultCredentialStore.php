<?php

/**
 * NextcloudVaultCredentialStore — the first {@see CredentialStore} leaf.
 *
 * Backs the credential-broker secret store with Nextcloud's native encrypted
 * credential vault (`OCP\Security\ICredentialsManager`), which encrypts at rest
 * and is scoped per user. Secrets are stored under the current user's id with the
 * key `openregister/credential/<uuid>`, so only the owning user can ever read or
 * delete their own credential's secret (design.md D1/D3, ADR-005: no custom secret
 * storage). The raw secret is only ever in memory here; it is never logged.
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

use OCP\IUserSession;
use OCP\Security\ICredentialsManager;

/**
 * Per-user encrypted-vault implementation of the credential secret store.
 */
class NextcloudVaultCredentialStore implements CredentialStore
{
    /**
     * Vault key prefix for a credential secret (namespaced under the app).
     *
     * @var string
     */
    private const KEY_PREFIX = 'openregister/credential/';

    /**
     * Constructor.
     *
     * @param ICredentialsManager $credentialsManager The NC encrypted per-user vault.
     * @param IUserSession        $userSession        The current user session (scopes the vault key).
     *
     * @return void
     */
    public function __construct(
        private readonly ICredentialsManager $credentialsManager,
        private readonly IUserSession $userSession,
    ) {
    }//end __construct()

    /**
     * Store (or overwrite) the secret for a credential in the current user's vault.
     *
     * @param string $uuid   The owning `credential` object's UUID.
     * @param string $secret The raw secret to store.
     *
     * @return void
     *
     * @spec openspec/changes/credential-broker/specs/credential-broker/spec.md#credential-metadata-schema
     */
    public function put(string $uuid, string $secret): void
    {
        $this->credentialsManager->store(
            $this->currentUserId(),
            self::KEY_PREFIX.$uuid,
            $secret
        );
    }//end put()

    /**
     * Retrieve the secret for a credential from the current user's vault.
     *
     * @param string $uuid The owning `credential` object's UUID.
     *
     * @return string|null The raw secret, or null when absent / not a string.
     *
     * @spec openspec/changes/credential-broker/specs/credential-broker/spec.md#credential-metadata-schema
     */
    public function get(string $uuid): ?string
    {
        $value = $this->credentialsManager->retrieve(
            $this->currentUserId(),
            self::KEY_PREFIX.$uuid
        );

        if (is_string($value) === true) {
            return $value;
        }

        return null;
    }//end get()

    /**
     * Delete the secret for a credential from the current user's vault.
     *
     * @param string $uuid The owning `credential` object's UUID.
     *
     * @return void
     *
     * @spec openspec/changes/credential-broker/specs/credential-broker/spec.md#credential-metadata-schema
     */
    public function delete(string $uuid): void
    {
        $this->credentialsManager->delete(
            $this->currentUserId(),
            self::KEY_PREFIX.$uuid
        );
    }//end delete()

    /**
     * Resolve the current user's id for per-user vault scoping.
     *
     * @return string The current user's UID, or an empty string when unauthenticated.
     *
     * @spec openspec/changes/credential-broker/specs/credential-broker/spec.md#credential-metadata-schema
     */
    private function currentUserId(): string
    {
        return ($this->userSession->getUser()?->getUID() ?? '');
    }//end currentUserId()
}//end class
