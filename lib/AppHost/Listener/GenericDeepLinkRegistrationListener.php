<?php

/**
 * OpenRegister AppHost — Generic Deep-Link Registration Listener
 *
 * Engine-owned generalisation of the per-app `DeepLinkRegistrationListener`.
 * Instead of hardcoding deep-link patterns in PHP, it reads them declaratively
 * from the leaf app's `src/manifest.json` `deepLinks` block and registers each
 * with OpenRegister's unified-search provider on the
 * {@see DeepLinkRegistrationEvent}.
 *
 * Expected manifest shape (all entries optional; missing block = no-op):
 *   "deepLinks": [
 *     {
 *       "registerSlug": "petstore",
 *       "schemaSlug":   "pet",
 *       "urlTemplate":  "/apps/petstore/#/pets/{uuid}",
 *       "icon":         "",            // optional
 *       "displayName":  "Pet"          // optional
 *     }
 *   ]
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Listener
 * @package  OCA\OpenRegister\AppHost\Listener
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

namespace OCA\OpenRegister\AppHost\Listener;

use OCA\OpenRegister\Event\DeepLinkRegistrationEvent;
use OCP\App\IAppManager;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Registers a leaf app's manifest-declared deep links with OpenRegister.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/changes/apphost-boilerplate-controllers/tasks.md#task-2.5
 */
class GenericDeepLinkRegistrationListener implements IEventListener {
	/**
	 * Constructor.
	 *
	 * @param string $appId The leaf app id whose manifest is read.
	 * @param IAppManager $appManager App path resolution.
	 * @param LoggerInterface $logger PSR logger.
	 */
	public function __construct(
		protected readonly string $appId,
		protected readonly IAppManager $appManager,
		protected readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Handle the deep-link registration event.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/apphost-boilerplate-controllers/tasks.md#task-2.5
	 */
	public function handle(Event $event): void {
		if ($event instanceof DeepLinkRegistrationEvent === false) {
			return;
		}

		foreach ($this->loadDeepLinks() as $link) {
			$registerSlug = ($link['registerSlug'] ?? '');
			$schemaSlug = ($link['schemaSlug'] ?? '');
			$urlTemplate = ($link['urlTemplate'] ?? '');

			if ($registerSlug === '' || $schemaSlug === '' || $urlTemplate === '') {
				continue;
			}

			$displayName = ($link['displayName'] ?? null);
			if (is_string($displayName) === false) {
				$displayName = null;
			}

			$event->register(
				appId: $this->appId,
				registerSlug: (string)$registerSlug,
				schemaSlug: (string)$schemaSlug,
				urlTemplate: (string)$urlTemplate,
				icon: (string)($link['icon'] ?? ''),
				displayName: $displayName
			);
		}//end foreach
	}//end handle()

	/**
	 * Load the `deepLinks` array from the leaf app's manifest. Overridable hook.
	 *
	 * Always returns an array; a missing/unreadable manifest or block yields
	 * an empty list (no deep links registered), never a fatal. A missing
	 * manifest is logged as a warning.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 *
	 * @spec openspec/changes/notification-links-in-releases-and-case-insensitive-order/specs/deep-link-registry/spec.md#requirement-the-registry-must-fill-itself-in-a-process-that-never-booted-openregister
	 */
	protected function loadDeepLinks(): array {
		try {
			$appPath = $this->appManager->getAppPath(appId: $this->appId);
		} catch (Throwable $e) {
			return [];
		}

		$manifestPath = $appPath . '/src/manifest.json';
		if (is_readable($manifestPath) === false) {
			// Said out loud: a release package without src/ registered nothing
			// here, and every notification of the app silently fell back to
			// OpenRegister (cloud check, 9 October 2026).
			$this->logger->warning(
				sprintf('[AppHost:%s] no src/manifest.json in the app package; no deep links registered', $this->appId)
			);
			return [];
		}

		$raw = file_get_contents($manifestPath);
		if ($raw === false) {
			return [];
		}

		$decoded = json_decode($raw, associative: true);
		if (json_last_error() !== JSON_ERROR_NONE || is_array($decoded) === false) {
			$this->logger->warning(sprintf('[AppHost:%s] manifest.json is invalid JSON; no deep links registered', $this->appId));
			return [];
		}

		$links = ($decoded['deepLinks'] ?? []);
		if (is_array($links) === false) {
			return [];
		}

		// Keep only array entries.
		$entries = [];
		foreach ($links as $link) {
			if (is_array($link) === true) {
				$entries[] = $link;
			}
		}//end foreach

		return $entries;
	}//end loadDeepLinks()
}//end class
