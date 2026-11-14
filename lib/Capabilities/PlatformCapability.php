<?php

/**
 * Platform capability: what this instance serves, before a client tries it.
 *
 * Publishes, through the Nextcloud capabilities API (`OCS /cloud/capabilities`):
 *
 *   1. The app version, the API versions served with their status, and the
 *      limits an integrator needs: read from the same source as
 *      `GET /api/capabilities` (ApiCapabilitiesServiceInterface), so the two
 *      answers cannot disagree.
 *   2. The registered deep link patterns.
 *   3. Which platform integrations are on.
 *   4. A block per claiming app, so a client of that app finds it by its own
 *      name without knowing OpenRegister is underneath.
 *
 * Capabilities are readable by anyone who can reach the instance, so the
 * block carries no register name, no schema name and no secret: a deep link
 * pattern that spells out a register or schema slug as a path segment is
 * left out.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Capabilities
 * @package  OCA\OpenRegister\Capabilities
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/platform-capability/specs/deep-link-registry/spec.md#requirement-the-instance-publishes-a-capability-block-with-one-block-per-claiming-app-req-pca-001
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Capabilities;

use OCA\OpenRegister\Dto\DeepLinkRegistration;
use OCA\OpenRegister\Service\ApiVersion\ApiCapabilitiesServiceInterface;
use OCA\OpenRegister\Service\DeepLinkRegistryServiceInterface;
use OCP\App\IAppManager;
use OCP\Capabilities\IPublicCapability;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The platform capability block, with one nested block per claiming app.
 *
 * @spec openspec/changes/platform-capability/specs/deep-link-registry/spec.md#requirement-the-instance-publishes-a-capability-block-with-one-block-per-claiming-app-req-pca-001
 */
class PlatformCapability implements IPublicCapability {

	/**
	 * The app id.
	 *
	 * @var string
	 */
	private const APP_ID = 'openregister';

	/**
	 * Constructor.
	 *
	 * @param ApiCapabilitiesServiceInterface  $apiCapabilities The versions and limits, shared with the API endpoint.
	 * @param DeepLinkRegistryServiceInterface $deepLinks       The apps' deep link registrations.
	 * @param IAppManager                      $appManager      The installed app version.
	 * @param LoggerInterface                  $logger          Logs a registry that cannot be read.
	 */
	public function __construct(
		private readonly ApiCapabilitiesServiceInterface $apiCapabilities,
		private readonly DeepLinkRegistryServiceInterface $deepLinks,
		private readonly IAppManager $appManager,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The capability block.
	 *
	 * @return array<string, array<string, array<string, mixed>>>
	 *
	 * @spec openspec/changes/platform-capability/specs/deep-link-registry/spec.md#requirement-the-instance-publishes-a-capability-block-with-one-block-per-claiming-app-req-pca-001
	 * @spec openspec/changes/platform-capability/specs/deep-link-registry/spec.md#requirement-the-capability-block-names-no-register-and-no-secret-and-agrees-with-the-api-answer-req-pca-002
	 */
	public function getCapabilities(): array {
		$api  = $this->apiCapabilities->publicCapabilities();
		$apps = $this->appBlocks(registrations: $this->registrations());

		$patterns = [];
		foreach ($apps as $app) {
			$patterns = array_merge($patterns, $app['deepLinks']);
		}

		return [
			self::APP_ID => [
				'platform' => [
					'version' => $this->appManager->getAppVersion(self::APP_ID),
					'currentVersion' => $api['currentVersion'],
					'versionHeader' => $api['versionHeader'],
					'apiVersions' => $api['apiVersions'],
					'limits' => $api['limits'],
					'deepLinks' => array_values(array_unique($patterns)),
					'integrations' => PlatformIntegrations::switches(),
					'apps' => $apps,
				],
			],
		];
	}//end getCapabilities()

	/**
	 * The deep link registrations; none when the registry cannot be read.
	 *
	 * A capability that throws takes the whole OCS capabilities answer down
	 * with it, for every app, so a broken registry costs only the app blocks.
	 *
	 * @return array<string, DeepLinkRegistration>
	 */
	private function registrations(): array {
		try {
			return $this->deepLinks->registrations();
		} catch (Throwable $e) {
			$this->logger->warning(
				message: '[PlatformCapability] The deep link registry could not be read: ' . $e->getMessage()
			);
			return [];
		}
	}//end registrations()

	/**
	 * One block per claiming app, keyed and ordered by app id.
	 *
	 * @param array<string, DeepLinkRegistration> $registrations The registrations.
	 *
	 * @return array<string, array{id: string, displayName: string, deepLinks: list<string>, integrations: list<string>}>
	 *
	 * @spec openspec/changes/platform-capability/specs/deep-link-registry/spec.md#requirement-the-instance-publishes-a-capability-block-with-one-block-per-claiming-app-req-pca-001
	 */
	private function appBlocks(array $registrations): array {
		$apps = [];
		foreach ($registrations as $registration) {
			$appId = $registration->appId;
			if ($appId === '') {
				continue;
			}

			$apps[$appId] ??= [
				'id' => $appId,
				'displayName' => $appId,
				'deepLinks' => [],
				'integrations' => PlatformIntegrations::enabled(),
			];

			if ($registration->displayName !== null && $registration->displayName !== '' && $apps[$appId]['displayName'] === $appId) {
				$apps[$appId]['displayName'] = $registration->displayName;
			}

			if ($this->namesTheDataModel(registration: $registration) === false
				&& in_array($registration->urlTemplate, $apps[$appId]['deepLinks'], true) === false
			) {
				$apps[$appId]['deepLinks'][] = $registration->urlTemplate;
			}
		}//end foreach

		ksort($apps, SORT_STRING);

		return $apps;
	}//end appBlocks()

	/**
	 * Whether a pattern is empty or spells out its register or schema slug as a segment.
	 *
	 * Placeholders such as `{uuid}` are not names; a literal segment equal to
	 * the slug is, and publishing it would enumerate the data model.
	 *
	 * @param DeepLinkRegistration $registration The registration.
	 *
	 * @return bool True when the pattern must stay out of the block.
	 *
	 * @spec openspec/changes/platform-capability/specs/deep-link-registry/spec.md#requirement-the-capability-block-names-no-register-and-no-secret-and-agrees-with-the-api-answer-req-pca-002
	 */
	private function namesTheDataModel(DeepLinkRegistration $registration): bool {
		$template = trim($registration->urlTemplate);
		if ($template === '') {
			return true;
		}

		$slugs = array_filter(
			[strtolower($registration->registerSlug), strtolower($registration->schemaSlug)],
			static fn (string $slug): bool => $slug !== ''
		);

		$segments = preg_split('#[/?&=\#.:]+#', strtolower($template));
		if ($segments === false) {
			return true;
		}

		return array_intersect($segments, $slugs) !== [];
	}//end namesTheDataModel()
}//end class
