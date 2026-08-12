<?php

/**
 * Listener that injects the global integration-registry bootstrap script.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Listener
 * @package  OCA\OpenRegister\Listener
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/universal-shared-integration-registry/tasks.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Listener;

use OCP\AppFramework\Http\Events\BeforeTemplateRenderedEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Util;

/**
 * Loads the `openregister-integration-global` bundle on EVERY full-page
 * render so the shared integration registry
 * (`window.OCA.OpenRegister.integrations`) is installed + populated with
 * the built-in integrations and generic leaves on every page — not just
 * OpenRegister's own SPA.
 *
 * This is what lets integration tabs/widgets (and any leaf app's Path-2
 * component) render inside ANY consuming app's object detail page (e.g.
 * an OpenCatalogi publication) WITHOUT that app bootstrapping the
 * registry itself. The bundle's `ensureIntegrationRegistry()` is
 * idempotent, so co-loading it with OpenRegister's own main bundle is
 * harmless.
 *
 * Unconditional by design: any page may host a CnDetailPage /
 * CnObjectSidebar that reads the registry, so the bootstrap must be
 * universally available. The script itself is tiny and short-circuits
 * after the first run.
 *
 * @template-implements IEventListener<Event>
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/universal-shared-integration-registry/tasks.md
 */
class IntegrationGlobalScriptListener implements IEventListener {
	/**
	 * Handle the template-rendered event by injecting the bootstrap script.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) \OCP\Util::addInitScript is a
	 *     Nextcloud framework static helper with no injectable DI equivalent;
	 *     the NC AppFramework does not expose addInitScript via any interface.
	 *
	 * @spec openspec/changes/universal-shared-integration-registry/tasks.md
	 */
	public function handle(Event $event): void {
		if (($event instanceof BeforeTemplateRenderedEvent) === false) {
			return;
		}

		Util::addInitScript('openregister', 'openregister-integration-global');

	}//end handle()
}//end class
