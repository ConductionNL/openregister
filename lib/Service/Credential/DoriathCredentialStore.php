<?php

/**
 * DoriathCredentialStore — the Doriath-backed {@see CredentialStore} leaf.
 *
 * Keeps custody of brokered secrets as APPLICATION-OWNED Doriath secrets in
 * OpenRegister's single Doriath application vault (credential-doriath-leaf
 * design D-C/D-F): the Doriath secret's name equals the credential OR object's
 * UUID and it lives in the root folder. `put()` encrypts the secret against
 * OpenRegister's OWN public key PEM using Doriath's `rsa-oaep-sha256-chunked-v1`
 * scheme — invoking Doriath's stateless `EncryptService` cross-app so the scheme
 * can never drift — and upserts by name via `SecretService::createByApplication`
 * / `updateByApplication`. `get()` reads the ciphertext in-process by name and
 * decrypts via Doriath's `DecryptService::rsaDecrypt` with OpenRegister's
 * private key from the SYSTEM-scoped `ICredentialsManager`. `delete()` removes
 * the application-owned row via `SecretService::deleteByApplication` — keyed by
 * the Doriath ROW id from the fetched entity, never the name — (cross-repo
 * seam: Doriath change `application-secret-delete`) and clears any residual
 * Nextcloud-vault row.
 *
 * The store COMPOSES the {@see NextcloudVaultCredentialStore} for LAZY
 * read-time migration (design D-A): a Doriath miss that hits in the per-user
 * vault re-puts the secret into Doriath, deletes the vault row, and returns
 * it. The vault is session-scoped, so migration only fires in a user-session
 * context; a sessionless read of an un-migrated secret fails CLOSED.
 *
 * Doriath is accessed through service-level seams ONLY (never its `Db`
 * mappers), lazily resolved by FQCN (`class_exists` + `OCP\Server::get`) so
 * OpenRegister carries no compile-time dependency on the optional app. The
 * plaintext secret exists only in process memory here — it is NEVER logged,
 * persisted to an OR object, or returned in any API response. DORIATH owns
 * the ambiguity policy (design D-C): `getByNameForApplication` returns the
 * single own-vault match as a `Secret` entity (ciphertext intact) or null for
 * zero/cross-vault/AMBIGUOUS matches (>1 logs a warning Doriath-side, never
 * guessed) — so an ambiguous name is indistinguishable from absence here and
 * a read of it fails closed exactly as required.
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

use OCP\IAppConfig;
use OCP\IUserSession;
use OCP\Security\ICredentialsManager;
use OCP\Server;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Doriath application-vault implementation of the credential secret store.
 *
 * @SuppressWarnings(PHPMD.StaticAccess) Doriath services are lazily resolved via
 *   `OCP\Server::get` behind `class_exists` guards so OpenRegister carries no
 *   compile-time dependency on the optional app (design D-A, Deck-leaf idiom).
 */
class DoriathCredentialStore implements CredentialStore
{
    /**
     * IAppConfig key (app `openregister`) holding the Doriath-assigned application UUID.
     *
     * @var string
     */
    public const APP_CONFIG_APPLICATION_ID = 'doriath_application_id';

    /**
     * IAppConfig key (app `openregister`) holding OpenRegister's own public key PEM.
     *
     * Public material only — needed by {@see put()} for envelope encryption
     * without a Doriath round-trip. The private counterpart NEVER lands here.
     *
     * @var string
     */
    public const APP_CONFIG_PUBLIC_KEY_PEM = 'doriath_public_key_pem';

    /**
     * SYSTEM-scoped `ICredentialsManager` key holding OpenRegister's private key PEM.
     *
     * Same system-scope idiom (`userId = ''`) as the per-app broker signing
     * secrets in {@see CredentialAppTokenService}. The private key never leaves
     * `ICredentialsManager`, is never logged, and never lands in `IAppConfig`.
     *
     * @var string
     */
    public const PRIVATE_KEY_ID = 'openregister/doriath/private-key';

    /**
     * FQCN of Doriath's stateless encrypt service (scheme `rsa-oaep-sha256-chunked-v1`).
     *
     * @var string
     */
    private const ENCRYPT_SERVICE = 'OCA\\Doriath\\Service\\EncryptService';

    /**
     * FQCN of Doriath's stateless decrypt service.
     *
     * @var string
     */
    private const DECRYPT_SERVICE = 'OCA\\Doriath\\Service\\DecryptService';

    /**
     * FQCN of Doriath's secret service (application-scoped seam).
     *
     * @var string
     */
    private const SECRET_SERVICE = 'OCA\\Doriath\\Service\\SecretService';

    /**
     * Constructor.
     *
     * @param NextcloudVaultCredentialStore $vaultStore         Composed vault leaf (lazy-migration fallback).
     * @param ICredentialsManager           $credentialsManager NC vault holding OR's private key (system-scoped).
     * @param IAppConfig                    $appConfig          Holds the Doriath application UUID + public PEM.
     * @param IUserSession                  $userSession        Session probe (migration only runs in-session).
     * @param LoggerInterface               $logger             Secret-free diagnostics.
     *
     * @return void
     */
    public function __construct(
        private readonly NextcloudVaultCredentialStore $vaultStore,
        private readonly ICredentialsManager $credentialsManager,
        private readonly IAppConfig $appConfig,
        private readonly IUserSession $userSession,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Store (or rotate) the secret as an application-owned Doriath ciphertext row.
     *
     * Encrypts against OpenRegister's own public key PEM via Doriath's stateless
     * `EncryptService::rsaEncrypt` (scheme `rsa-oaep-sha256-chunked-v1`), then
     * upserts by name (name = credential UUID, root folder): a Doriath miss
     * (null — including a Doriath-side ambiguity, which Doriath reports as
     * absence, never guessing) creates via `SecretService::createByApplication`;
     * a single match rotates via `updateByApplication` keyed by the entity's
     * ROW id (keeping the row, bumping Doriath's rotation metadata). A write
     * failure throws — a dropped secret must be loud.
     *
     * The Doriath row is application-owned and keyed by the credential UUID, so it
     * is inherently system-scoped — `$scope` does not change where the ciphertext
     * lands here; it is threaded through only to the composed per-user vault leaf
     * used for lazy migration (design D2 / D-A).
     *
     * @param string $uuid   The owning `credential` object's UUID (the secret name).
     * @param string $secret The raw secret to store. Never logged.
     * @param string $scope  The credential scope (`personal`|`organisation`); threaded to the migration vault leaf.
     *
     * @return void
     *
     * @throws RuntimeException When the store is unprovisioned or Doriath rejects the write.
     *
     * @spec openspec/specs/credential-broker/spec.md
     */
    public function put(string $uuid, string $secret, string $scope='personal'): void
    {
        $applicationId = $this->requireApplicationId();

        $publicPem = $this->appConfig->getValueString('openregister', self::APP_CONFIG_PUBLIC_KEY_PEM, '');
        if ($publicPem === '') {
            throw new RuntimeException('Credential store is not provisioned');
        }

        $encryptService = $this->requireDoriathService(className: self::ENCRYPT_SERVICE);
        $ciphertext     = (string) $encryptService->rsaEncrypt($secret, $publicPem);

        $row = $this->lookupRow(uuid: $uuid, applicationId: $applicationId);

        $secretService = $this->requireDoriathService(className: self::SECRET_SERVICE);
        if ($row === null) {
            $secretService->createByApplication(['name' => $uuid, 'key' => $ciphertext], $applicationId);
            return;
        }

        $secretService->updateByApplication((string) $row->getId(), ['key' => $ciphertext], $applicationId);
    }//end put()

    /**
     * Retrieve and decrypt the secret, lazily migrating a legacy vault row.
     *
     * Reads the application-owned ciphertext in-process by name (Doriath returns
     * the single own-vault `Secret` entity or null — zero matches, cross-vault,
     * and ambiguity are all null, with ambiguity logged Doriath-side) and
     * decrypts via Doriath's `DecryptService::rsaDecrypt` with OR's
     * system-scoped private key. Every failure path returns null — the broker
     * then denies with its static 403 ("no secret stored"), failing closed. A
     * Doriath miss falls back to the composed per-user vault (design D-A): on a
     * vault hit the secret is re-put into Doriath and the vault row deleted
     * (only after a successful re-put), then returned. Without a user session
     * the vault cannot be consulted, so a sessionless read of an un-migrated
     * secret fails closed.
     *
     * @param string $uuid  The owning `credential` object's UUID.
     * @param string $scope The credential scope (`personal`|`organisation`); threaded to the migration vault leaf.
     *
     * @return string|null The raw secret, or null when absent/undecryptable. Never logged.
     *
     * @spec openspec/specs/credential-broker/spec.md
     */
    public function get(string $uuid, string $scope='personal'): ?string
    {
        $applicationId = $this->applicationId();
        if ($applicationId === '') {
            return null;
        }

        try {
            $row = $this->lookupRow(uuid: $uuid, applicationId: $applicationId);
        } catch (Throwable $e) {
            // A failed lookup is NOT a miss: do not fall through to migration,
            // which could redundantly re-encrypt. Fail closed instead.
            $this->logger->warning(
                '[DoriathCredentialStore] secret lookup failed',
                ['credential' => $uuid, 'error' => $e->getMessage()]
            );
            return null;
        }

        if ($row !== null) {
            return $this->decryptRow(row: $row, uuid: $uuid);
        }

        return $this->migrateFromVault(uuid: $uuid, scope: $scope);
    }//end get()

    /**
     * Delete the secret from Doriath and clear any residual vault row (idempotent).
     *
     * Resolves the application-owned row by name and removes it via
     * `SecretService::deleteByApplication` — keyed by the Doriath ROW id from
     * the fetched entity (`getId()`), never the credential-UUID name; the seam
     * loads by row id (`SecretMapper::findById`) and is itself an idempotent
     * silent no-op on missing/cross-vault ids. A null resolution (absent — or
     * ambiguous, which Doriath reports as absence) is a no-op here too. The
     * composed vault leaf is always cleared, so a later fallback can never
     * resurrect a deleted secret.
     *
     * @param string $uuid  The owning `credential` object's UUID.
     * @param string $scope The credential scope (`personal`|`organisation`); threaded to the residual vault leaf.
     *
     * @return void
     *
     * @throws RuntimeException When the Doriath seam is unreachable (caller must not treat the secret as gone).
     *
     * @spec openspec/specs/credential-broker/spec.md
     */
    public function delete(string $uuid, string $scope='personal'): void
    {
        // Residual-vault cleanup (design D-A): harmless no-op when absent.
        $this->vaultStore->delete($uuid, $scope);

        $applicationId = $this->applicationId();
        if ($applicationId === '') {
            return;
        }

        $secretService = $this->requireDoriathService(className: self::SECRET_SERVICE);

        $row = $this->lookupRow(uuid: $uuid, applicationId: $applicationId);
        if ($row === null) {
            return;
        }

        $secretService->deleteByApplication((string) $row->getId(), $applicationId);
    }//end delete()

    /**
     * Decrypt one Doriath row's ciphertext with OR's system-scoped private key.
     *
     * @param object $row  The Doriath secret entity (exposes `getKey()`).
     * @param string $uuid The credential UUID (secret-free logging only).
     *
     * @return string|null The plaintext secret, or null on any failure (fail closed).
     *
     * @spec openspec/specs/credential-broker/spec.md
     */
    private function decryptRow(object $row, string $uuid): ?string
    {
        $privatePem = $this->credentialsManager->retrieve('', self::PRIVATE_KEY_ID);
        if (is_string($privatePem) === false || $privatePem === '') {
            $this->logger->warning(
                '[DoriathCredentialStore] private key unavailable — failing closed',
                ['credential' => $uuid]
            );
            return null;
        }

        try {
            $decryptService = $this->requireDoriathService(className: self::DECRYPT_SERVICE);
            return (string) $decryptService->rsaDecrypt((string) $row->getKey(), $privatePem);
        } catch (Throwable $e) {
            $this->logger->warning(
                '[DoriathCredentialStore] decryption failed — failing closed',
                ['credential' => $uuid, 'error' => $e->getMessage()]
            );
            return null;
        }
    }//end decryptRow()

    /**
     * Lazily migrate a legacy per-user vault secret into Doriath (design D-A).
     *
     * Session-only: the vault is scoped to the session user, so without a user
     * session this returns null (sessionless reads of un-migrated secrets fail
     * closed). The vault row is deleted ONLY after a successful re-put, so a
     * failed migration leaves the secret retrievable on the next attempt.
     *
     * @param string $uuid  The owning `credential` object's UUID.
     * @param string $scope The credential scope (`personal`|`organisation`); selects the vault owner.
     *
     * @return string|null The migrated secret, or null when there is nothing to migrate.
     *
     * @spec openspec/specs/credential-broker/spec.md
     */
    private function migrateFromVault(string $uuid, string $scope='personal'): ?string
    {
        if ($this->userSession->getUser() === null) {
            return null;
        }

        $legacy = $this->vaultStore->get($uuid, $scope);
        if ($legacy === null) {
            return null;
        }

        try {
            $this->put(uuid: $uuid, secret: $legacy, scope: $scope);
        } catch (Throwable $e) {
            // Migration failed — keep the vault row so the next session read
            // can retry; the caller still gets the secret it owns.
            $this->logger->warning(
                '[DoriathCredentialStore] lazy migration failed — vault row kept',
                ['credential' => $uuid, 'error' => $e->getMessage()]
            );
            return $legacy;
        }

        $this->vaultStore->delete($uuid, $scope);

        return $legacy;
    }//end migrateFromVault()

    /**
     * Look up the application-owned Doriath row named by a credential UUID.
     *
     * Uses the service-level application-scoped read-by-name seam
     * `SecretService::getByNameForApplication(name, applicationId): ?Secret`,
     * which lands with Doriath's `application-secret-delete` change — the
     * resolver's `method_exists` probe guarantees it exists before this store
     * is selected. Doriath owns the resolution policy: the single own-vault
     * match is returned as a `Secret` entity (ciphertext intact in `getKey()`),
     * while zero matches, cross-vault names, and AMBIGUOUS names (>1 — logged
     * Doriath-side, never guessed) all yield null. The `is_object` check is
     * kept as a defensive shape guard since the call is duck-typed.
     *
     * @param string $uuid          The credential UUID (the Doriath secret name).
     * @param string $applicationId OR's Doriath application UUID.
     *
     * @return object|null The matching secret entity, or null when absent/ambiguous.
     *
     * @throws RuntimeException When the Doriath seam is unreachable.
     *
     * @spec openspec/specs/credential-broker/spec.md
     */
    private function lookupRow(string $uuid, string $applicationId): ?object
    {
        $secretService = $this->requireDoriathService(className: self::SECRET_SERVICE);

        $row = $secretService->getByNameForApplication($uuid, $applicationId);
        if (is_object($row) === false) {
            return null;
        }

        return $row;
    }//end lookupRow()

    /**
     * Read OR's Doriath application UUID from IAppConfig ('' when unregistered).
     *
     * @return string The Doriath-assigned application UUID, or ''.
     *
     * @spec openspec/specs/credential-broker/spec.md
     */
    private function applicationId(): string
    {
        return $this->appConfig->getValueString('openregister', self::APP_CONFIG_APPLICATION_ID, '');
    }//end applicationId()

    /**
     * Read OR's Doriath application UUID, throwing when unregistered.
     *
     * @return string The Doriath-assigned application UUID.
     *
     * @throws RuntimeException When OpenRegister is not registered with Doriath.
     *
     * @spec openspec/specs/credential-broker/spec.md
     */
    private function requireApplicationId(): string
    {
        $applicationId = $this->applicationId();
        if ($applicationId === '') {
            throw new RuntimeException('Credential store is not provisioned');
        }

        return $applicationId;
    }//end requireApplicationId()

    /**
     * Resolve a Doriath service by FQCN, or null when unavailable.
     *
     * `class_exists` + `OCP\Server::get` (Deck-leaf / AnonymisationBackendService
     * idiom) so OR carries no compile-time dependency on Doriath. Protected so
     * unit tests can substitute contract fakes without the Doriath app present.
     *
     * @param string $className The Doriath service FQCN.
     *
     * @return object|null The resolved service, or null when unavailable.
     *
     * @spec openspec/specs/credential-broker/spec.md
     */
    protected function resolveDoriathService(string $className): ?object
    {
        if (class_exists($className) === false) {
            return null;
        }

        try {
            return Server::get($className);
        } catch (Throwable $e) {
            $this->logger->warning(
                '[DoriathCredentialStore] failed to resolve Doriath service',
                ['service' => $className, 'error' => $e->getMessage()]
            );
            return null;
        }
    }//end resolveDoriathService()

    /**
     * Resolve a Doriath service by FQCN, throwing when unavailable.
     *
     * @param string $className The Doriath service FQCN.
     *
     * @return object The resolved service.
     *
     * @throws RuntimeException When the service cannot be resolved.
     *
     * @spec openspec/specs/credential-broker/spec.md
     */
    private function requireDoriathService(string $className): object
    {
        $service = $this->resolveDoriathService(className: $className);
        if ($service === null) {
            throw new RuntimeException('Credential store backend unavailable');
        }

        return $service;
    }//end requireDoriathService()
}//end class
