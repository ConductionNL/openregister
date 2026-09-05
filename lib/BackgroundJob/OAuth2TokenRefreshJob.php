<?php

/**
 * OAuth2TokenRefreshJob — refreshes brokered OAuth2 connections before they lapse.
 *
 * The read path already refreshes a token set whose expiry is inside the margin, so
 * a connection something calls every day never needs this job. A connection nothing
 * has called for a month does: its access token expired long ago, and for several
 * providers the refresh token expires too if it is never spent. Without a sweep, the
 * failure surfaces the first time a person actually tries to publish, which is the
 * worst possible moment to discover it.
 *
 * The sweep takes the SAME per-credential lock as the read path, so a live call and
 * a sweep never both exchange, and it treats `invalid_grant` exactly as the read
 * path does: the connection moves to `relink_needed` once and its owner is told,
 * rather than the job retrying a grant that is gone every day forever.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category BackgroundJob
 * @package  OCA\OpenRegister\BackgroundJob
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/credential-oauth2-token-set/specs/credential-oauth2-token-set/spec.md#requirement-a-daily-job-refreshes-active-token-sets-before-they-expire
 */

declare(strict_types=1);

namespace OCA\OpenRegister\BackgroundJob;

use OCA\OpenRegister\Service\Credential\CredentialBrokerService;
use OCA\OpenRegister\Service\Credential\CredentialRelinkRequiredException;
use OCA\OpenRegister\Service\Credential\OAuth2RefreshService;
use OCA\OpenRegister\Service\Credential\ProviderCatalogue;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Daily sweep that rotates active OAuth2 token sets ahead of their expiry.
 */
class OAuth2TokenRefreshJob extends TimedJob {

	/**
	 * How often the sweep runs, in seconds.
	 *
	 * Once a day. The window the sweep refreshes within is two days wide, so a
	 * missed tick, on an instance whose cron was down, still leaves a full day of
	 * slack before anything lapses.
	 *
	 * @var integer
	 */
	private const INTERVAL_SECONDS = 86400;

	/**
	 * The credential kind this job is about.
	 *
	 * @var string
	 */
	private const KIND = 'oauth2-token-set';

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory $time Time factory for TimedJob.
	 * @param ObjectService $objectService Lists the brokered credentials.
	 * @param ProviderCatalogue $catalogue Resolves each credential's provider entry.
	 * @param OAuth2RefreshService $refresh Performs the rotation, under the shared lock.
	 * @param LoggerInterface $logger Reports what was swept and what failed.
	 *
	 * @return void
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly ObjectService $objectService,
		private readonly ProviderCatalogue $catalogue,
		private readonly OAuth2RefreshService $refresh,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $time);
		$this->setInterval(seconds: self::INTERVAL_SECONDS);

	}//end __construct()

	/**
	 * Refresh every active token set whose expiry is inside the sweep window.
	 *
	 * @param mixed $argument Job argument (unused).
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 *
	 * @spec openspec/changes/credential-oauth2-token-set/specs/credential-oauth2-token-set/spec.md#requirement-a-daily-job-refreshes-active-token-sets-before-they-expire
	 */
	protected function run($argument): void {
		try {
			$credentials = $this->activeTokenSetCredentials();
		} catch (Throwable $listFailure) {
			$this->logger->warning('[OAuth2TokenRefreshJob] could not list credentials: ' . $listFailure->getMessage());
			return;
		}

		$refreshed = 0;
		$failed = 0;
		foreach ($credentials as $credentialId => $data) {
			try {
				$provider = $this->catalogue->get((string)($data['provider'] ?? ''));
				if ($provider === null || (string)($provider['kind'] ?? '') !== self::KIND) {
					continue;
				}

				$didRefresh = $this->refresh->sweepCredential(
					credential: $data,
					provider: $provider,
					credentialId: (string)$credentialId,
					scope: (string)($data['scope'] ?? 'personal')
				);

				if ($didRefresh === true) {
					$refreshed++;
				}
			} catch (CredentialRelinkRequiredException $relink) {
				// Already recorded on the credential and already notified to its
				// owner by the refresh service. Counting it as a failure here would
				// make a healthy sweep of a dead grant look like a broken sweep.
				$this->logger->info('[OAuth2TokenRefreshJob] credential ' . $credentialId . ' needs re-authorisation');
			} catch (Throwable $failure) {
				// One credential's provider being down must not stop the others.
				$failed++;
				$this->logger->warning(
					'[OAuth2TokenRefreshJob] refresh failed for ' . $credentialId . ': ' . $failure->getMessage()
				);
			}//end try
		}//end foreach

		$this->logger->info(
			'[OAuth2TokenRefreshJob] swept ' . count($credentials) . ' connection(s), refreshed ' . $refreshed
			. ', failed ' . $failed
		);
	}//end run()

	/**
	 * List the credentials this sweep is allowed to touch, keyed by UUID.
	 *
	 * Runs without RBAC because there is no session: the sweep acts for the instance,
	 * and the authorization that matters (who may USE a credential) is enforced at
	 * call time by the broker's guard chain, not here. A credential that is not an
	 * active token set is dropped before anything is read from the custody leaf.
	 *
	 * @return array<string, array<string, mixed>> The candidate credentials.
	 *
	 * @spec openspec/changes/credential-oauth2-token-set/specs/credential-oauth2-token-set/spec.md#requirement-a-daily-job-refreshes-active-token-sets-before-they-expire
	 */
	private function activeTokenSetCredentials(): array {
		$objects = $this->objectService
			->setRegister(CredentialBrokerService::REGISTER)
			->setSchema(CredentialBrokerService::SCHEMA)
			->findAll(config: [], _rbac: false, _multitenancy: false);

		$candidates = [];
		foreach ($objects as $object) {
			$data = $object->jsonSerialize();
			if ((string)($data['kind'] ?? '') !== self::KIND || (string)($data['status'] ?? '') !== 'active') {
				continue;
			}

			$uuid = (string)$object->getUuid();
			if ($uuid !== '') {
				$candidates[$uuid] = $data;
			}
		}

		return $candidates;
	}//end activeTokenSetCredentials()
}//end class
