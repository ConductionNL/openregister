<?php

/**
 * DoriathApplicationRegistrar — per-app Doriath application registration (identity-only).
 *
 * Registers each consuming app that onboards to the credential broker as its
 * OWN Doriath `Application`, in addition to the existing OR app-key onboarding
 * ({@see CredentialAppTokenService::registerApp}). This is the identity-only
 * companion to {@see \OCA\OpenRegister\Repair\RegisterOpenRegisterWithDoriath}
 * (which self-registers OR's single custody vault): it reuses Doriath's
 * `ApplicationService::register` cross-app (resolved via `class_exists` +
 * `OCP\Server::get`, no compile-time dependency on Doriath) but supplies NO CSR
 * and provisions NO EncryptionSuite — an identity that never holds ciphertext
 * needs no suite. Brokered secret custody stays under OpenRegister's single
 * self-registered Doriath application vault (credential-doriath-leaf D-C/D-F),
 * unchanged.
 *
 * The application NAME equals the consuming appId (Doriath assigns the row
 * UUID), the description is drawn from the app's manifest (fallback to the
 * appId), type `internal`. Registration uses the non-admin path
 * (`isAdmin: false`) so Doriath creates a `pending` row an administrator must
 * approve. It is idempotent: the Doriath-assigned UUID is persisted in
 * `IAppConfig` under a per-app key (namespaced by appId, distinct from OR's own
 * `doriath_application_id`); when that UUID is set AND the Doriath row still
 * exists the step is a no-op — it never re-registers or rotates. When Doriath
 * is absent/disabled/unloadable the step degrades (warn, never throw).
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
use OCP\Server;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Registers a per-app, identity-only Doriath application, idempotently.
 *
 * @SuppressWarnings(PHPMD.StaticAccess) Doriath's ApplicationService is lazily
 *   resolved via `OCP\Server::get` behind a `class_exists` guard so OpenRegister
 *   carries no compile-time dependency on the optional app (design D-2).
 *
 * @spec openspec/changes/per-app-doriath-application/tasks.md#1-per-app-doriath-registration-seam-d-2-d-6
 */
class DoriathApplicationRegistrar
{
    /**
     * FQCN of Doriath's application service (registration seam).
     *
     * @var string
     */
    private const APPLICATION_SERVICE = 'OCA\\Doriath\\Service\\ApplicationService';

    /**
     * `IAppConfig` key prefix for a per-app Doriath application UUID.
     *
     * The per-app key is `self::APP_CONFIG_APPLICATION_ID_PREFIX . $appId`,
     * deliberately DISTINCT from
     * {@see DoriathCredentialStore::APP_CONFIG_APPLICATION_ID} (which identifies
     * OpenRegister's own single custody vault). No secret material is ever
     * stored under it — identity-only holds no private key.
     *
     * @var string
     */
    public const APP_CONFIG_APPLICATION_ID_PREFIX = 'doriath_application_id_';

    /**
     * The Doriath application `type` for same-fleet consumers (design D-6).
     *
     * @var string
     */
    private const APPLICATION_TYPE = 'internal';

    /**
     * Constructor.
     *
     * @param IAppConfig      $appConfig  Persists/probes the per-app Doriath application UUID (non-secret).
     * @param IAppManager     $appManager Probes whether the doriath app is enabled.
     * @param LoggerInterface $logger     Secret-free diagnostics.
     *
     * @return void
     */
    public function __construct(
        private readonly IAppConfig $appConfig,
        private readonly IAppManager $appManager,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Register the given consuming app as its own identity-only Doriath application.
     *
     * Never throws: Doriath absent/disabled/unloadable, or any failure, warns
     * and returns — the caller's OR app-key onboarding and the broker continue
     * unchanged. Idempotent: a live persisted UUID makes this a no-op.
     *
     * @param string      $appId       The consuming app id (becomes the Doriath application name).
     * @param string|null $description The manifest description (falls back to the appId).
     *
     * @return void
     *
     * @spec openspec/specs/credential-broker/spec.md
     */
    public function registerApplication(string $appId, ?string $description=null): void
    {
        try {
            if ($this->isDoriathAvailable() === false) {
                $this->logger->debug(
                    sprintf('[DoriathApplicationRegistrar:%s] Doriath unavailable — skipping per-app registration', $appId)
                );
                return;
            }

            if ($this->isRegistrationLive(appId: $appId) === true) {
                $this->logger->debug(
                    sprintf('[DoriathApplicationRegistrar:%s] already registered with a live Doriath row — not re-registering', $appId)
                );
                return;
            }

            $this->register(appId: $appId, description: $description);
        } catch (Throwable $e) {
            $this->logger->warning(
                sprintf('[DoriathApplicationRegistrar:%s] per-app Doriath registration skipped', $appId),
                ['exception' => $e->getMessage()]
            );
        }//end try
    }//end registerApplication()

    /**
     * The per-app `IAppConfig` key holding this app's Doriath application UUID.
     *
     * @param string $appId The consuming app id.
     *
     * @return string The namespaced config key.
     *
     * @spec openspec/specs/credential-broker/spec.md
     */
    public static function appConfigKey(string $appId): string
    {
        return self::APP_CONFIG_APPLICATION_ID_PREFIX.$appId;
    }//end appConfigKey()

    /**
     * Whether the doriath app is enabled and its registration seam is loadable.
     *
     * @return bool True when per-app registration can be attempted.
     *
     * @spec openspec/specs/credential-broker/spec.md
     */
    private function isDoriathAvailable(): bool
    {
        if ($this->appManager->isEnabledForUser('doriath') === false) {
            return false;
        }

        return ($this->resolveApplicationService() !== null);
    }//end isDoriathAvailable()

    /**
     * Whether a previous per-app registration is still live in Doriath (skip-fast).
     *
     * Mirrors {@see RegisterOpenRegisterWithDoriath::isRegistrationLive()}: true
     * only when the per-app `IAppConfig` UUID is set AND Doriath still holds that
     * row. A stale UUID (Doriath reinstalled/row removed) yields false, so the
     * step re-registers exactly once.
     *
     * @param string $appId The consuming app id.
     *
     * @return bool True when already registered with a live row.
     *
     * @spec openspec/specs/credential-broker/spec.md
     */
    private function isRegistrationLive(string $appId): bool
    {
        $applicationId = $this->appConfig->getValueString('openregister', self::appConfigKey(appId: $appId), '');
        if ($applicationId === '') {
            return false;
        }

        $applicationService = $this->resolveApplicationService();
        if ($applicationService === null) {
            return false;
        }

        try {
            // Admin-scoped in-process read; throws when the row no longer exists.
            $applicationService->get($applicationId, '', true);
            return true;
        } catch (Throwable $e) {
            $this->logger->info(
                sprintf('[DoriathApplicationRegistrar:%s] persisted application UUID is stale — re-registering', $appId),
                ['error' => $e->getMessage()]
            );
            return false;
        }
    }//end isRegistrationLive()

    /**
     * Register the per-app application (identity-only, no CSR, pending) and persist its UUID.
     *
     * @param string      $appId       The consuming app id (Doriath application name).
     * @param string|null $description The manifest description (falls back to the appId).
     *
     * @return void
     *
     * @spec openspec/specs/credential-broker/spec.md
     */
    private function register(string $appId, ?string $description): void
    {
        $applicationService = $this->resolveApplicationService();
        if ($applicationService === null) {
            return;
        }

        $displayDescription = $appId;
        if ($description !== null && $description !== '') {
            $displayDescription = $description;
        }

        // Identity-only: csr = null (no EncryptionSuite provisioned); userId =
        // null (repair runs without a session); isAdmin = false → Doriath
        // creates a PENDING row and dispatches an admin-approval notification.
        $application = $applicationService->register(
            $appId,
            $displayDescription,
            self::APPLICATION_TYPE,
            null,
            null,
            false
        );

        // Doriath generates the row UUID — persist the NON-secret UUID only.
        $applicationId = (string) $application->getId();
        $this->appConfig->setValueString('openregister', self::appConfigKey(appId: $appId), $applicationId);

        $this->logger->info(
            sprintf('[DoriathApplicationRegistrar:%s] registered per-app Doriath application (pending)', $appId),
            ['applicationId' => $applicationId]
        );
    }//end register()

    /**
     * Resolve Doriath's ApplicationService, or null when unavailable.
     *
     * `class_exists` + `OCP\Server::get` (no compile-time Doriath dependency).
     * Protected so unit tests can substitute a contract fake.
     *
     * @return object|null The resolved service, or null.
     *
     * @spec openspec/specs/credential-broker/spec.md
     */
    protected function resolveApplicationService(): ?object
    {
        if (class_exists(self::APPLICATION_SERVICE) === false) {
            return null;
        }

        try {
            return Server::get(self::APPLICATION_SERVICE);
        } catch (Throwable $e) {
            $this->logger->warning(
                '[DoriathApplicationRegistrar] failed to resolve Doriath ApplicationService',
                ['error' => $e->getMessage()]
            );
            return null;
        }
    }//end resolveApplicationService()
}//end class
