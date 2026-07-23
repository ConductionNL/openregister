<?php

/**
 * CredentialStoreResolver — runtime backend selection for the credential store.
 *
 * Single source of truth for "which {@see CredentialStore} leaf is active"
 * (credential-doriath-leaf design D-A), mirroring the backend-selection role of
 * `AnonymisationBackendService` combined with the Deck-leaf availability idiom
 * of `DeckLinkService`. Doriath is ELIGIBLE only when ALL of the following
 * hold, evaluated in order and failing closed to the Nextcloud-vault leaf:
 *
 *   1. `IAppManager::isEnabledForUser('doriath')` — the app is enabled;
 *   2. `class_exists` succeeds for every Doriath service class the leaf calls
 *      (no compile-time dependency on the optional app);
 *   3. `method_exists` succeeds for the application-scoped seam methods
 *      (`getByNameForApplication`, `deleteByApplication`) — these land via
 *      Doriath's `application-secret-delete` change, so an OLDER Doriath
 *      yields the vault leaf, not a broken one (cross-repo merge order free);
 *   4. OpenRegister's self-registration state exists in `IAppConfig` (the
 *      Doriath-assigned application UUID + OR's public key PEM, written by the
 *      {@see \OCA\OpenRegister\Repair\RegisterOpenRegisterWithDoriath} step).
 *
 * Consumers (broker, controller) keep depending only on the `CredentialStore`
 * interface; the DI factory in `lib/AppInfo/Application.php` asks this
 * resolver per request — zero call-site changes.
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

use OCP\App\IAppManager;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Resolves the active credential-store leaf (Doriath preferred, vault fallback).
 */
class CredentialStoreResolver
{
    /**
     * The Doriath app id probed for eligibility.
     *
     * @var string
     */
    public const DORIATH_APP_ID = 'doriath';

    /**
     * Doriath service classes the leaf calls — ALL must exist for eligibility.
     *
     * @var array<int, string>
     */
    private const DORIATH_SERVICE_CLASSES = [
        'OCA\\Doriath\\Service\\ApplicationService',
        'OCA\\Doriath\\Service\\SecretService',
        'OCA\\Doriath\\Service\\EncryptService',
        'OCA\\Doriath\\Service\\DecryptService',
    ];

    /**
     * The Doriath service class carrying the application-scoped seam methods.
     *
     * @var string
     */
    private const SECRET_SERVICE_CLASS = 'OCA\\Doriath\\Service\\SecretService';

    /**
     * Application-scoped seam methods that must exist on the secret service.
     *
     * These land via Doriath's `application-secret-delete` change; probing for
     * them keeps the cross-repo rollout order free (design D-A).
     *
     * @var array<int, string>
     */
    private const REQUIRED_SEAM_METHODS = [
        'getByNameForApplication',
        'deleteByApplication',
    ];

    /**
     * Constructor.
     *
     * @param IAppManager                   $appManager   Probes whether the doriath app is enabled.
     * @param IAppConfig                    $appConfig    Holds OR's Doriath self-registration state.
     * @param DoriathCredentialStore        $doriathStore The Doriath-backed leaf (selected when eligible).
     * @param NextcloudVaultCredentialStore $vaultStore   The NC-vault leaf (fallback).
     * @param LoggerInterface               $logger       Secret-free diagnostics.
     *
     * @return void
     */
    public function __construct(
        private readonly IAppManager $appManager,
        private readonly IAppConfig $appConfig,
        private readonly DoriathCredentialStore $doriathStore,
        private readonly NextcloudVaultCredentialStore $vaultStore,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Return the active credential-store leaf.
     *
     * @return CredentialStore The Doriath leaf when eligible, else the NC-vault leaf.
     *
     * @spec openspec/specs/credential-broker/spec.md
     */
    public function resolve(): CredentialStore
    {
        if ($this->isDoriathEligible() === true) {
            return $this->doriathStore;
        }

        return $this->vaultStore;
    }//end resolve()

    /**
     * Evaluate the full Doriath eligibility chain (fails closed on any miss).
     *
     * @return bool True when the Doriath leaf may be used.
     *
     * @spec openspec/specs/credential-broker/spec.md
     */
    public function isDoriathEligible(): bool
    {
        if ($this->appManager->isEnabledForUser(self::DORIATH_APP_ID) === false) {
            return false;
        }

        foreach ($this->doriathServiceClasses() as $className) {
            if (class_exists($className) === false) {
                $this->logger->debug(
                    '[CredentialStoreResolver] Doriath ineligible: service class missing',
                    ['service' => $className]
                );
                return false;
            }
        }

        $secretServiceClass = $this->secretServiceClass();
        foreach (self::REQUIRED_SEAM_METHODS as $method) {
            if (method_exists($secretServiceClass, $method) === false) {
                $this->logger->debug(
                    '[CredentialStoreResolver] Doriath ineligible: application-scoped seam method missing',
                    ['method' => $method]
                );
                return false;
            }
        }

        return $this->isSelfRegistered();
    }//end isDoriathEligible()

    /**
     * Whether OR's Doriath self-registration state exists (design D-B output).
     *
     * @return bool True when the application UUID and public key PEM are persisted.
     *
     * @spec openspec/specs/credential-broker/spec.md
     */
    private function isSelfRegistered(): bool
    {
        $applicationId = $this->appConfig->getValueString(
            'openregister',
            DoriathCredentialStore::APP_CONFIG_APPLICATION_ID,
            ''
        );
        $publicPem     = $this->appConfig->getValueString(
            'openregister',
            DoriathCredentialStore::APP_CONFIG_PUBLIC_KEY_PEM,
            ''
        );

        return ($applicationId !== '' && $publicPem !== '');
    }//end isSelfRegistered()

    /**
     * The Doriath service classes probed via `class_exists`.
     *
     * Protected so unit tests can point the probes at contract fixtures
     * without the Doriath app installed.
     *
     * @return array<int, string> The probed FQCNs.
     *
     * @spec openspec/specs/credential-broker/spec.md
     */
    protected function doriathServiceClasses(): array
    {
        return self::DORIATH_SERVICE_CLASSES;
    }//end doriathServiceClasses()

    /**
     * The secret-service class probed for the application-scoped seam methods.
     *
     * Protected for the same test-fixture reason as {@see doriathServiceClasses()}.
     *
     * @return string The probed FQCN.
     *
     * @spec openspec/specs/credential-broker/spec.md
     */
    protected function secretServiceClass(): string
    {
        return self::SECRET_SERVICE_CLASS;
    }//end secretServiceClass()
}//end class
