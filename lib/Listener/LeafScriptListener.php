<?php

/**
 * OpenRegister LeafScriptListener
 *
 * Loads the CLIENT half of every registered render-surface leaf onto the pages
 * of apps that consume OpenRegister.
 *
 * WHY THIS EXISTS — THE HALF THAT WAS NEVER WIRED
 * -----------------------------------------------
 * ADR-066 decision 1 splits a cross-app leaf in two: a server-side
 * `LeafDescriptor` contributed to `RegisterLeafProvidersEvent`, and a JS
 * `registerIntegration()` call supplying the render surface, "correlated to the
 * descriptor by a shared id". gate-24 enforces that both halves exist and agree.
 *
 * Nothing loaded the second half. A leaf's components live in the PROVIDING
 * app's bundle, Nextcloud serves only the current app's scripts, and — measured
 * across this repository, @conduction/nextcloud-vue and the providing apps
 * themselves on 2026-09-04 — no code path enqueued them:
 *
 *   - every `Util::addScript()` / `addEntryScripts()` call here names
 *     'openregister' itself,
 *   - nc-vue's dist contains no dynamic `<script>` injection at all,
 *   - humaniq and planninq register no `BeforeTemplateRenderedEvent` listener.
 *
 * So a descriptor reached OCS capability discovery and every server-side
 * consumer, `getLeaves()` returned it, gate-24 went green on both halves — and
 * the surface rendered NOTHING, on every consuming page, for as long as leaves
 * have shipped. `humaniq-hours` has been dark on dossiq case pages the whole
 * time. That is the failure shape ADR-113 is about: everything reports success
 * and the feature is absent.
 *
 * WHAT IT LOADS, AND WHERE
 * ------------------------
 * A dedicated `leaves` webpack entry per providing app, NOT the app's main
 * bundle. A main bundle is megabytes and carries a whole SPA; putting one on
 * another app's page would be a performance regression traded for a feature.
 * An app that ships no `<app>-leaves` build artifact is skipped, exactly as
 * FilesSidebarListener skips its own missing bundle — so this can never enqueue
 * a 404.
 *
 * Scope is deliberately narrow on both axes:
 *
 *   - ONLY on pages of an app that itself ships an OpenRegister register
 *     descriptor. That is the same signal gate-55 uses to decide "is this
 *     register mine", it costs one filesystem check, and it keeps leaf bundles
 *     off Files, Photos, Mail and Settings, which host no OpenRegister objects.
 *   - NEVER the current app's own leaf: its bundle is already on its own page,
 *     and loading a second copy would register the id twice, which the AD-13
 *     collision policy warns about in production and throws on in development.
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
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Listener;

use OCA\OpenRegister\AppInfo\Application;
use OCA\OpenRegister\Service\Integration\LeafDescriptor;
use OCA\OpenRegister\Service\Integration\LeafRegistry;
use OCA\OpenRegister\Service\ScriptManifestLoader;
use OCP\App\IAppManager;
use OCP\AppFramework\Http\Events\BeforeTemplateRenderedEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Enqueues each providing app's leaf bundle on consuming apps' pages.
 *
 * @template-implements IEventListener<Event>
 */
class LeafScriptListener implements IEventListener {

	/**
	 * The webpack entry name a providing app must expose.
	 *
	 * @var string
	 */
	public const LEAF_ENTRY = 'leaves';

	/**
	 * Constructor.
	 *
	 * @param LeafRegistry    $leaves     The collected leaf catalogue.
	 * @param IAppManager     $appManager App installation + path lookups.
	 * @param IRequest        $request    The current request, for the app id.
	 * @param LoggerInterface $logger     PSR-3 logger.
	 */
	public function __construct(
		private readonly LeafRegistry $leaves,
		private readonly IAppManager $appManager,
		private readonly IRequest $request,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Enqueue the leaf bundles this page needs.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) ScriptManifestLoader is static for
	 *     the same reason \OCP\Util::addScript it wraps is: there is no
	 *     injectable DI equivalent in the AppFramework.
	 *
	 * @spec openspec/specs/integration-registry/spec.md#requirement-a-leafs-client-half-must-be-loaded-on-consuming-pages
	 */
	public function handle(Event $event): void {
		if ($event instanceof BeforeTemplateRenderedEvent === false) {
			return;
		}

		// A public page has no OpenRegister objects to hang a leaf on, and
		// loading another app's bundle there would widen the anonymous surface.
		if ($event->isLoggedIn() === false) {
			return;
		}

		try {
			$currentApp = $this->currentAppId();
			if ($currentApp === null || $currentApp === Application::APP_ID) {
				return;
			}

			foreach ($this->leafAppsFor(currentApp: $currentApp) as $providingApp) {
				ScriptManifestLoader::addEntryScripts(
					appId: $providingApp,
					entry: self::LEAF_ENTRY,
					fallbackScript: $providingApp . '-' . self::LEAF_ENTRY
				);
			}
		} catch (Throwable $e) {
			// A page must render even when the catalogue cannot be read. The
			// leaf is then absent, which is the pre-existing behaviour.
			$this->logger->warning(
				'OpenRegister could not enqueue leaf scripts: ' . $e->getMessage(),
				['exception' => $e]
			);
		}//end try

	}//end handle()

	/**
	 * The providing apps whose leaf bundles belong on this page.
	 *
	 * This is the whole rule, deliberately separated from the enqueuing so it
	 * can be tested: `ScriptManifestLoader` is static over `\OCP\Util`, and a
	 * listener that decides and acts in one method can only be verified by
	 * booting Nextcloud.
	 *
	 * @param string $currentApp The app whose page is rendering.
	 *
	 * @return string[] App ids to load, in catalogue order, without duplicates.
	 *
	 * @spec openspec/specs/integration-registry/spec.md#requirement-a-leafs-client-half-must-be-loaded-on-consuming-pages
	 */
	public function leafAppsFor(string $currentApp): array {
		if ($currentApp === '' || $currentApp === Application::APP_ID) {
			return [];
		}

		// A page with no OpenRegister objects has nothing for a leaf to attach
		// to. This keeps sibling bundles off Files, Photos, Mail and Settings.
		if ($this->shipsRegisterDescriptor(appId: $currentApp) === false) {
			return [];
		}

		$apps = [];
		foreach ($this->leaves->getDescriptors() as $descriptor) {
			if (in_array(LeafDescriptor::KIND_RENDER_SURFACE, $descriptor->getKinds(), true) === false) {
				// A data-provider leaf has no client half to load.
				continue;
			}

			$providingApp = $descriptor->getRequiredApp();
			if ($providingApp === null || $providingApp === $currentApp) {
				// Built-in leaves ride on OpenRegister's own bundle, and an
				// app's own leaf is already loaded by its own page. Loading a
				// second copy would register the id twice, which the AD-13
				// collision policy warns about in production and throws on in
				// development.
				continue;
			}

			if (in_array($providingApp, $apps, true) === true) {
				// One app may contribute several leaves; its bundle carries all
				// of them and must be enqueued once.
				continue;
			}

			if ($this->appManager->isEnabledForUser($providingApp) === false) {
				continue;
			}

			if ($this->hasLeafBundle(appId: $providingApp) === false) {
				// Enqueuing a script that does not exist is a 404 in the page,
				// so an app that ships no leaf entry is simply skipped.
				continue;
			}

			$apps[] = $providingApp;
		}

		return $apps;
	}//end leafAppsFor()

	/**
	 * The app whose page is being rendered, from the request path.
	 *
	 * Nextcloud serves an app under BOTH `/apps/<id>/…` and
	 * `/index.php/apps/<id>/…`; the pattern below accepts either, because a
	 * visitor arriving on the other form is a real case and matching only one
	 * would silently drop the leaf for them.
	 *
	 * @return string|null The app id, or null when this is not an app page.
	 */
	private function currentAppId(): ?string {
		$path = (string)$this->request->getPathInfo();
		if (preg_match('#(?:^|/)apps/([a-z0-9_.-]+)(?:/|$)#', $path, $m) !== 1) {
			return null;
		}
		return $m[1];
	}//end currentAppId()

	/**
	 * Whether an app ships an OpenRegister register descriptor of its own.
	 *
	 * The same signal gate-55 uses to decide which registers an app owns:
	 * a `lib/Settings/*register*.json`. An app with one consumes OpenRegister
	 * and may render objects; an app without one (Files, Mail, Settings) has
	 * nothing for a leaf to attach to.
	 *
	 * @param string $appId The app to test.
	 *
	 * @return boolean Whether it ships a register descriptor.
	 */
	private function shipsRegisterDescriptor(string $appId): bool {
		$path = $this->appPath(appId: $appId);
		if ($path === null) {
			return false;
		}
		return glob($path . '/lib/Settings/*register*.json') !== [];
	}//end shipsRegisterDescriptor()

	/**
	 * Whether an app ships a built leaf bundle.
	 *
	 * @param string $appId The providing app.
	 *
	 * @return boolean Whether `js/<app>-leaves.js` exists.
	 */
	private function hasLeafBundle(string $appId): bool {
		$path = $this->appPath(appId: $appId);
		if ($path === null) {
			return false;
		}
		return file_exists($path . '/js/' . $appId . '-' . self::LEAF_ENTRY . '.js');
	}//end hasLeafBundle()

	/**
	 * An app's filesystem path, or null when it cannot be resolved.
	 *
	 * @param string $appId The app.
	 *
	 * @return string|null The path.
	 */
	private function appPath(string $appId): ?string {
		try {
			return $this->appManager->getAppPath($appId);
		} catch (Throwable) {
			return null;
		}
	}//end appPath()
}//end class
