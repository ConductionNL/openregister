<?php

/**
 * OpenRegister AppHost — Generic Initialize-Settings Repair Step
 *
 * Engine-owned generalisation of the per-app `InitializeSettings` repair step.
 * On install / post-migration it imports the leaf app's register JSON
 * (including `register.d/` fragments) through OpenRegister's
 * ConfigurationService — via {@see AppHostSettingsService::loadConfiguration()}.
 *
 * This is a REPAIR STEP, never a migration, preserving the fleet install-order
 * constraint (OpenRegister must be enabled before a leaf seeds its register).
 *
 * NC instantiates repair steps by the class name listed in the leaf app's
 * info.xml `<repair-steps>`, which must live in the leaf namespace. The leaf
 * therefore keeps a one-line subclass — `class InitializeSettings extends
 * GenericInitializeSettings {}` — and Bootstrap registers a factory binding it
 * to the app-scoped {@see AppHostSettingsService}. The subclass carries no
 * logic; this base owns all behaviour.
 *
 * Credential-broker onboarding (credential-doriath-leaf design D-G): when the
 * leaf's bundled `src/manifest.json` declares a non-empty `credentials[]`, the
 * step auto-registers the app with the credential broker
 * (`CredentialAppTokenService::registerApp`) — guarded by `isRegistered()` so
 * an auto-run NEVER rotates an existing signing secret (rotation stays an
 * explicit admin action). This initialisation path serves classic AppHost
 * leaves and generated virtual apps alike: both route through
 * {@see \OCA\OpenRegister\AppHost\Bootstrap::registerRepairSteps()}, so it IS
 * the manifest-registration point for credential consumers.
 *
 * Per-app Doriath identity (per-app-doriath-application design D-5): alongside
 * the OR app-key onboarding, the same hook also registers the consuming app as
 * its OWN identity-only Doriath `Application` via
 * {@see \OCA\OpenRegister\Service\Credential\DoriathApplicationRegistrar} —
 * manifest-driven, idempotent, `pending`, no CSR. This adds identity only;
 * brokered secret custody stays under OR's single self-registered application
 * vault (credential-doriath-leaf D-C/D-F), untouched.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Repair
 * @package  OCA\OpenRegister\AppHost\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\AppHost\Repair;

use OCA\OpenRegister\AppHost\Service\AppHostSettingsService;
use OCA\OpenRegister\Service\Credential\CredentialAppTokenService;
use OCA\OpenRegister\Service\Credential\DoriathApplicationRegistrar;
use OCP\App\IAppManager;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use OCP\Server;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Generic install/upgrade repair step that imports a leaf app's register JSON.
 *
 * @SuppressWarnings(PHPMD.StaticAccess) The credential-broker onboarding hook
 *   lazily resolves its collaborators via `OCP\Server::get` when the Bootstrap
 *   factory did not inject them, preserving the degrade-don't-throw posture.
 *
 * @spec openspec/changes/apphost-boilerplate-controllers/tasks.md#task-2.3
 */
class GenericInitializeSettings implements IRepairStep {
	/**
	 * Constructor.
	 *
	 * @param string $appId The leaf app id (display only).
	 * @param AppHostSettingsService $settingsService App-scoped settings service.
	 * @param LoggerInterface $logger PSR logger.
	 * @param IAppManager|null $appManager Locates the leaf's bundled manifest (lazily resolved when null).
	 * @param CredentialAppTokenService|null $tokenService Credential-broker app registry (lazily resolved when null).
	 * @param DoriathApplicationRegistrar|null $applicationRegistrar Per-app Doriath application registrar (lazily resolved when null).
	 */
	public function __construct(
		protected readonly string $appId,
		protected readonly AppHostSettingsService $settingsService,
		protected readonly LoggerInterface $logger,
		protected readonly ?IAppManager $appManager = null,
		protected readonly ?CredentialAppTokenService $tokenService = null,
		protected readonly ?DoriathApplicationRegistrar $applicationRegistrar = null,
	) {
	}//end __construct()

	/**
	 * Repair-step name.
	 *
	 * @return string
	 */
	public function getName(): string {
		return sprintf('Initialize %s register and schemas via ConfigurationService', $this->appId);
	}//end getName()

	/**
	 * Import the app's register configuration. Degrades (does not throw) when
	 * OpenRegister is unavailable, preserving the fleet's disabled-OR posture.
	 *
	 * After the import attempt the credential-broker onboarding hook runs
	 * (design D-G): a leaf manifest declaring `credentials[]` is registered
	 * with the broker exactly once — never rotating an existing secret.
	 *
	 * @param IOutput $output Repair output channel.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/apphost-boilerplate/spec.md — Requirement: Install Plumbing via Repair Steps
	 * @spec openspec/specs/credential-broker/spec.md
	 */
	public function run(IOutput $output): void {
		$output->info(sprintf('Initializing %s configuration...', $this->appId));

		if ($this->settingsService->isOpenRegisterAvailable() === false) {
			$output->warning('OpenRegister is not installed or enabled. Skipping auto-configuration.');
			$this->logger->warning(sprintf('[AppHost:%s] OpenRegister not available, skipping register initialization', $this->appId));
			return;
		}

		$this->importConfiguration(output: $output);
		$this->registerCredentialConsumer(output: $output);
	}//end run()

	/**
	 * Run the register-JSON import, reporting (never throwing) any issue.
	 *
	 * @param IOutput $output Repair output channel.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/apphost-boilerplate/spec.md — Requirement: Install Plumbing via Repair Steps
	 */
	private function importConfiguration(IOutput $output): void {
		try {
			$result = $this->settingsService->loadConfiguration(force: false);

			if (($result['success'] ?? false) === true) {
				$version = ($result['version'] ?? 'unknown');
				$output->info(sprintf('%s configuration imported successfully (version: %s)', $this->appId, $version));
				return;
			}

			$message = ($result['message'] ?? 'unknown error');
			$output->warning(sprintf('%s configuration import issue: %s', $this->appId, $message));
		} catch (Throwable $e) {
			$output->warning(sprintf('Could not auto-configure %s: %s', $this->appId, $e->getMessage()));
			$this->logger->error(sprintf('[AppHost:%s] initialization failed', $this->appId), ['exception' => $e->getMessage()]);
		}//end try
	}//end importConfiguration()

	/**
	 * Onboarding hook for a `credentials[]`-declaring leaf.
	 *
	 * When the leaf's bundled `src/manifest.json` declares a non-empty
	 * `credentials` array it runs two INDEPENDENT, idempotent onboarding paths:
	 * the OR credential-broker app-key registration (design D-G,
	 * {@see registerBrokerAppKey()}) and the per-app Doriath IDENTITY
	 * registration (per-app-doriath-application D-5,
	 * {@see registerDoriathApplication()}). Both degrade (warn, never throw) on
	 * their own; the Doriath-identity path runs even when the app already holds
	 * a broker signing secret.
	 *
	 * @param IOutput $output Repair output channel.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/credential-broker/spec.md
	 * @spec openspec/specs/credential-broker/spec.md
	 */
	private function registerCredentialConsumer(IOutput $output): void {
		if ($this->manifestDeclaresCredentials() === false) {
			return;
		}

		// OR app-key onboarding (credential-doriath-leaf D-G) and per-app Doriath
		// IDENTITY registration (per-app-doriath-application D-5) are INDEPENDENT
		// and both idempotent: the Doriath-identity path runs even when the app
		// already holds a signing secret (its own live-row probe collapses re-runs
		// to a no-op). Both degrade (warn, never throw) on their own.
		$this->registerBrokerAppKey(output: $output);
		$this->registerDoriathApplication();
	}//end registerCredentialConsumer()

	/**
	 * Register the leaf's OR credential-broker app-key once (never rotating).
	 *
	 * The `isRegistered()` guard is essential: `registerApp()` ROTATES the
	 * signing secret on every call, and an unguarded auto-run on each upgrade
	 * would invalidate the app's held copy — auto-onboarding only ever registers
	 * ABSENT apps (design D-G). The freshly generated secret is deliberately
	 * discarded here: in-process consumers call the broker without an HMAC token
	 * (same-instance PHP is trusted), and cross-runtime consumers obtain a
	 * secret through the explicit admin registration endpoint instead. Never
	 * throws — any failure warns and leaves the instance healthy.
	 *
	 * @param IOutput $output Repair output channel.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/credential-broker/spec.md
	 */
	private function registerBrokerAppKey(IOutput $output): void {
		try {
			$tokenService = ($this->tokenService ?? Server::get(CredentialAppTokenService::class));

			if ($tokenService->isRegistered($this->appId) === true) {
				// Never rotate an existing signing secret from an auto-run.
				$this->logger->debug(
					sprintf('[AppHost:%s] already registered with the credential broker — not rotating', $this->appId)
				);
				return;
			}

			// The returned secret is intentionally unused (see docblock).
			$tokenService->registerApp($this->appId);
			$output->info(sprintf('%s registered with the credential broker (manifest declares credentials).', $this->appId));
		} catch (Throwable $e) {
			$output->warning(sprintf('Credential-broker registration for %s skipped: %s', $this->appId, $e->getMessage()));
			$this->logger->warning(
				sprintf('[AppHost:%s] credential-broker auto-registration failed', $this->appId),
				['exception' => $e->getMessage()]
			);
		}//end try
	}//end registerBrokerAppKey()

	/**
	 * Register the consuming app as its own identity-only Doriath `Application`.
	 *
	 * Delegates to {@see DoriathApplicationRegistrar}: name = appId, description
	 * = the manifest description (fallback appId), type `internal`, no CSR,
	 * `pending`. Idempotent and never-throw are owned by the registrar; this
	 * method only supplies the description read from the leaf manifest. Custody
	 * (OR's single `openregister` vault, credential-doriath-leaf D-C/D-F) is NOT
	 * touched — this adds identity, not custody.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/credential-broker/spec.md
	 */
	private function registerDoriathApplication(): void {
		$registrar = ($this->applicationRegistrar ?? Server::get(DoriathApplicationRegistrar::class));
		$registrar->registerApplication($this->appId, $this->manifestDescription());
	}//end registerDoriathApplication()

	/**
	 * The leaf's bundled `src/manifest.json` `description`, or null when absent.
	 *
	 * @return string|null The manifest description, or null.
	 *
	 * @spec openspec/specs/credential-broker/spec.md
	 */
	protected function manifestDescription(): ?string {
		try {
			$appManager = ($this->appManager ?? Server::get(IAppManager::class));
			$manifestPath = $appManager->getAppPath($this->appId) . '/src/manifest.json';
		} catch (Throwable $e) {
			return null;
		}

		if (is_readable($manifestPath) === false) {
			return null;
		}

		$manifest = json_decode((string)file_get_contents($manifestPath), true);
		if (is_array($manifest) === false) {
			return null;
		}

		$description = ($manifest['description'] ?? null);
		if (is_string($description) === true && $description !== '') {
			return $description;
		}

		return null;
	}//end manifestDescription()

	/**
	 * Whether the leaf's bundled `src/manifest.json` declares `credentials[]`.
	 *
	 * @return bool True when the manifest declares a non-empty credentials array.
	 *
	 * @spec openspec/specs/credential-broker/spec.md
	 */
	protected function manifestDeclaresCredentials(): bool {
		try {
			$appManager = ($this->appManager ?? Server::get(IAppManager::class));
			$manifestPath = $appManager->getAppPath($this->appId) . '/src/manifest.json';
		} catch (Throwable $e) {
			return false;
		}

		if (is_readable($manifestPath) === false) {
			return false;
		}

		$manifest = json_decode((string)file_get_contents($manifestPath), true);
		if (is_array($manifest) === false) {
			return false;
		}

		$credentials = ($manifest['credentials'] ?? null);

		return (is_array($credentials) === true && $credentials !== []);
	}//end manifestDeclaresCredentials()
}//end class
