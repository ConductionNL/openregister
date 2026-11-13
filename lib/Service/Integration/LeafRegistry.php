<?php

/**
 * LeafRegistry — collects the leaves sibling apps contribute through
 * `RegisterLeafProvidersEvent` and exposes them for discovery.
 *
 * This is the umbrella "apps hook themselves into OpenRegister" catalogue. The
 * event is dispatched ONCE, LAZILY, when the catalogue is first read in a
 * request — mirroring `ToolRegistry`/the MCP registration event. A throwing
 * listener is logged and swallowed so one broken app costs its own leaf and
 * nothing else, never removing leaves from the instance.
 *
 * When a contributed leaf declares the `data-provider` kind, its accompanying
 * `IntegrationProvider` is added to the shared `IntegrationRegistry` so the
 * existing per-object routing (`ObjectIntegrationsController`) reaches it with no
 * change to that path. The provider runs in the CONTRIBUTING app's DI context.
 *
 * RENDER-AND-READ BOUNDARY (ADR-066): the catalogue exposes only descriptors and
 * (for data leaves) list/append routing. It carries no verb; cross-app commands
 * stay ADR-041 typed events.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Integration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/app-leaf-provider-registration/specs/leaf-provider-registration/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Integration;

use OCA\OpenRegister\Event\RegisterLeafProvidersEvent;
use OCP\App\IAppManager;
use OCP\EventDispatcher\IEventDispatcher;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Registry of all leaves contributed by sibling apps on this NC instance.
 *
 * Duplicate leaf id: first registration wins, second logs a warning (ADR-013).
 * Descriptor validation (non-empty kinds, valid kinds, kebab-case id, valid
 * renderMode, data-provider-requires-provider) rejects and skips a bad leaf
 * without breaking the catalogue.
 */
class LeafRegistry implements LeafRegistryInterface {

	/**
	 * Whether the catalogue has been collected this request.
	 *
	 * @var boolean
	 */
	private bool $loaded = false;

	/**
	 * Collected leaf descriptors, keyed by id (first-wins).
	 *
	 * @var array<string, LeafDescriptor>
	 */
	private array $descriptors = [];

	/**
	 * The one answer to whether an app's leaf can render.
	 *
	 * @var LeafBundle
	 */
	private readonly LeafBundle $leafBundle;

	/**
	 * Constructor.
	 *
	 * @param IEventDispatcher $eventDispatcher Dispatcher for the collect-event.
	 * @param IntegrationRegistry $integrationRegistry Shared provider registry (data leaves land here).
	 * @param IAppManager $appManager App manager (usability check).
	 * @param LoggerInterface $logger Logger for collision, validation, collection warnings.
	 *
	 * @return void
	 */
	public function __construct(
		private IEventDispatcher $eventDispatcher,
		private IntegrationRegistry $integrationRegistry,
		private IAppManager $appManager,
		private LoggerInterface $logger,
	) {
		// LeafBundle is stateless and only reads the app manager, so the one
		// built here from the app manager this class already holds is the one
		// answer to whether a leaf can render; no construction site passed
		// another.
		$this->leafBundle = new LeafBundle($appManager);
	}//end __construct()

	/**
	 * Whether a render surface has the conventional bundle, reporting when not.
	 *
	 * 🔴 THE FAILURE THIS ENDS IS THAT EVERYTHING REPORTS SUCCESS AND THE
	 * FEATURE IS ABSENT. A render-surface descriptor from an app that ships no
	 * leaf bundle reached capability discovery, `getLeaves()` returned it, the
	 * gate went green on both halves, and the surface rendered NOTHING on every
	 * consuming page. Nobody was told, because nothing had failed.
	 *
	 * Refusing at registration is the only point where it can be said out loud.
	 * After it, every reader downstream is entitled to believe the leaf renders.
	 *
	 * 🔑 THREE CASES ARE DELIBERATELY NOT REFUSED, and each would be a
	 * regression:
	 *
	 * - a leaf with NO render-surface kind: a data provider or agent runner has
	 *   no client half to load, so a bundle is not its contract;
	 * - a BUILT-IN leaf, whose `requiredApp` is null: those ride OpenRegister's
	 *   own bundle, which is already on the page;
	 * - an app whose PATH cannot be resolved: it is disabled or not installed,
	 *   and "no bundle" would be a confident wrong reason for an app that is
	 *   simply not there.
	 *
	 * The message names the file the app must build, because "no leaf bundle"
	 * sends somebody looking at their webpack config with nothing to search
	 * for. Measured on the development instance, one app had built its leaf
	 * under a name nothing looks for.
	 *
	 * @param LeafDescriptor $descriptor The contributed descriptor.
	 *
	 * @return bool Whether it may register.
	 *
	 * @spec openspec/changes/a-leaf-that-cannot-render-refuses-to-register/specs/leaf-provider-registration/spec.md
	 */
	private function renderSurfaceCanRender(LeafDescriptor $descriptor): bool {
		$providingApp = self::renderSurfaceApp(descriptor: $descriptor);
		if ($providingApp === null) {
			return true;
		}

		if ($this->bundleNeedsNoJudgement(providingApp: $providingApp) === true) {
			return true;
		}

		return $this->judgeMissingBundle(descriptor: $descriptor, providingApp: $providingApp);
	}//end renderSurfaceCanRender()

	/**
	 * The app that provides a render surface's bundle, or null when there is nothing to judge.
	 *
	 * @param LeafDescriptor $descriptor The leaf.
	 *
	 * @return string|null The providing app, or null for a non-render leaf or one without an app.
	 */
	private static function renderSurfaceApp(LeafDescriptor $descriptor): ?string {
		if ($descriptor->hasKind(LeafDescriptor::KIND_RENDER_SURFACE) === false) {
			return null;
		}

		$providingApp = $descriptor->getRequiredApp();
		if ($providingApp === null || $providingApp === '') {
			return null;
		}

		return $providingApp;
	}//end renderSurfaceApp()

	/**
	 * Whether the providing app's bundle needs no judgement: no app path, a disabled app, or a present bundle.
	 *
	 * @param string $providingApp The providing app.
	 *
	 * @return bool
	 */
	private function bundleNeedsNoJudgement(string $providingApp): bool {
		if ($this->leafBundle->pathFor(appId: $providingApp) === null) {
			return true;
		}

		// 🔑 A DISABLED APP IS NOT A DARK LEAF, AND THIS REGISTRY ALREADY SAYS
		// SO ITS OWN WAY. `describeForCapabilities()` reports such a leaf as
		// `usable: false`, which is right: enabling the app fixes it, so the
		// descriptor belongs in the catalogue meanwhile. A MISSING BUNDLE never
		// fixes itself without a rebuild, which is why that one is refused and
		// this one is left to the existing mechanism. Overriding it here would
		// be a second answer to "can this leaf be used".
		if ($this->isDisabledOrUnknown(providingApp: $providingApp) === true) {
			return true;
		}

		return $this->leafBundle->existsFor(appId: $providingApp) === true;
	}//end bundleNeedsNoJudgement()

	/**
	 * Whether the providing app is disabled for the user, or its state cannot be read.
	 *
	 * @param string $providingApp The providing app.
	 *
	 * @return bool
	 */
	private function isDisabledOrUnknown(string $providingApp): bool {
		try {
			return $this->appManager->isEnabledForUser($providingApp) === false;
		} catch (Throwable) {
			return true;
		}
	}//end isDisabledOrUnknown()

	/**
	 * Judge a render surface whose app ships no shared bundle: refuse a claim, report only silence.
	 *
	 * @param LeafDescriptor $descriptor   The leaf.
	 * @param string         $providingApp The providing app.
	 *
	 * @return bool False when the leaf claims the shared entry; true otherwise, whether it declared
	 *              another strategy or stayed silent.
	 */
	private function judgeMissingBundle(LeafDescriptor $descriptor, string $providingApp): bool {
		// 🔴 NOW THE REFUSAL IS SOUND, BECAUSE IT ONLY JUDGES A CLAIM.
		//
		// openregister#3954 skipped on filesystem evidence alone and was wrong
		// twice in one measurement: hermiq and decidiq ship no
		// `<app>-leaves.js` and are not dark, because both load their own
		// bundle on every page. #3955 downgraded that to a report. This is the
		// version that can refuse without guessing.
		//
		// A leaf that DECLARES the shared entry has made a checkable claim: the
		// platform does that loading, so the platform can see the file is
		// missing and knows the surface cannot render. That is refused.
		//
		// 🔑 SILENCE IS NOT A CLAIM. A descriptor that says nothing is reported
		// and registered, exactly as #3955 left it, because "has not said" is
		// not "says shared entry". Refusing silence would re-create the #3954
		// failure for every descriptor written before this declaration existed.
		//
		// A leaf that DID say how it loads, `own-script` or `already-present`,
		// is not silent: it has stated the strategy the platform cannot check,
		// so there is nothing to refuse and nothing to report. Only a leaf
		// whose load strategy is null gets the "has not said how" error.
		if ($descriptor->claimsSharedEntry() === true) {
			$this->logRefusedSharedEntry(descriptor: $descriptor, providingApp: $providingApp);

			return false;
		}

		if ($descriptor->getLoadStrategy() === null) {
			$this->logUndeclaredLoadStrategy(descriptor: $descriptor, providingApp: $providingApp);
		}

		return true;
	}//end judgeMissingBundle()

	/**
	 * Log the refusal of a leaf that declares the shared entry its app does not ship.
	 *
	 * @param LeafDescriptor $descriptor   The leaf.
	 * @param string         $providingApp The app that provides it.
	 *
	 * @return void
	 */
	private function logRefusedSharedEntry(LeafDescriptor $descriptor, string $providingApp): void {
		$this->logger->error(
			sprintf(
				'[LeafRegistry] leaf "%s" declares it loads through the shared "%s" entry, but app '
				. '"%s" ships no "%s", so the surface cannot render. Refused. Build that entry, or '
				. 'declare the strategy the app actually uses.',
				$descriptor->getId(),
				LeafBundle::ENTRY,
				$providingApp,
				$this->leafBundle->expectedFileName(appId: $providingApp)
			)
		);
	}//end logRefusedSharedEntry()

	/**
	 * Report a leaf whose app ships no shared bundle and has not said how it loads.
	 *
	 * Called only for a leaf whose load strategy is null: one that declared
	 * `own-script` or `already-present` has said how it loads.
	 *
	 * @param LeafDescriptor $descriptor   The leaf.
	 * @param string         $providingApp The app that provides it.
	 *
	 * @return void
	 */
	private function logUndeclaredLoadStrategy(LeafDescriptor $descriptor, string $providingApp): void {
		$this->logger->error(
			sprintf(
				'[LeafRegistry] leaf "%s" declares a render surface but app "%s" ships no "%s" and has '
				. 'not said how it loads. If that app does not load its own bundle, this surface renders '
				. 'nothing while every other check reports success. Declare a load strategy.',
				$descriptor->getId(),
				$providingApp,
				$this->leafBundle->expectedFileName(appId: $providingApp)
			)
		);
	}//end logUndeclaredLoadStrategy()

	/**
	 * Dispatch the collect-event once and collect the announced leaves.
	 *
	 * Lazy: only the first read in a request pays the dispatch. The `$loaded`
	 * flag is set BEFORE the dispatch so a re-entrant read (e.g. a data leaf
	 * whose registration triggers an integration-registry read) does not
	 * re-dispatch. The whole collection is guarded — discovery must never take
	 * the container down.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/app-leaf-provider-registration/specs/leaf-provider-registration/spec.md
	 */
	private function ensureLoaded(): void {
		if ($this->loaded === true) {
			return;
		}

		$this->loaded = true;

		$event = new RegisterLeafProvidersEvent();
		$this->dispatchLeafEvent(event: $event);
		$this->collectLeaves(event: $event);
	}//end ensureLoaded()

	/**
	 * Ask every app for its leaves.
	 *
	 * A broken listener costs its own leaf, not everyone else's: the
	 * alternative is one bad app removing leaves from the instance.
	 * Whatever was registered on the event BEFORE the throw is still
	 * collected, since the event is mutated in place.
	 *
	 * @param RegisterLeafProvidersEvent $event The event the apps register on.
	 *
	 * @return void
	 */
	private function dispatchLeafEvent(RegisterLeafProvidersEvent $event): void {
		try {
			$this->eventDispatcher->dispatchTyped($event);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'[LeafRegistry] a leaf listener threw during dispatch: {message}',
				['message' => $e->getMessage(), 'exception' => $e]
			);
		}
	}//end dispatchLeafEvent()

	/**
	 * Collect every leaf that reached the event, guarding each one so a
	 * single bad descriptor cannot break the rest of the catalogue.
	 *
	 * @param RegisterLeafProvidersEvent $event The event the apps registered on.
	 *
	 * @return void
	 */
	private function collectLeaves(RegisterLeafProvidersEvent $event): void {
		foreach ($event->getLeaves() as $leaf) {
			try {
				$this->collectLeaf(descriptor: $leaf['descriptor'], provider: $leaf['provider']);
			} catch (\Throwable $e) {
				$this->logger->warning(
					'[LeafRegistry] collecting a leaf failed: {message}',
					['message' => $e->getMessage(), 'exception' => $e]
				);
			}
		}
	}//end collectLeaves()

	/**
	 * Validate one contributed leaf and, if it passes, index it (and register
	 * its data provider on the shared registry).
	 *
	 * Validation rules (a failing leaf is skipped, never fatal):
	 *   - id MUST be non-empty kebab-case;
	 *   - kinds MUST be a non-empty subset of `LeafDescriptor::VALID_KINDS`;
	 *   - renderMode MUST be one of `LeafDescriptor::VALID_RENDER_MODES`
	 *     (`component` — the default — or `mount`);
	 *   - a `data-provider` kind MUST carry an accompanying provider;
	 *   - duplicate id: first registration wins (ADR-013).
	 *
	 * @param LeafDescriptor $descriptor The contributed descriptor.
	 * @param IntegrationProvider|null $provider The accompanying provider, or null.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/app-leaf-provider-registration/specs/leaf-provider-registration/spec.md
	 */
	private function collectLeaf(LeafDescriptor $descriptor, ?IntegrationProvider $provider): void {
		$id = $descriptor->getId();

		$refusal = $this->refusalForLeaf(descriptor: $descriptor, provider: $provider);
		if ($refusal !== null) {
			$this->logger->warning($refusal);
			return;
		}

		if ($this->renderSurfaceCanRender(descriptor: $descriptor) === false) {
			return;
		}

		$this->registerLeaf(descriptor: $descriptor, provider: $provider);
	}//end collectLeaf()

	/**
	 * Register an accepted leaf, first registration wins, and hand its provider to the integration registry.
	 *
	 * @param LeafDescriptor           $descriptor The accepted leaf.
	 * @param IntegrationProvider|null $provider   Its data provider, when it has one.
	 *
	 * @return void
	 */
	private function registerLeaf(LeafDescriptor $descriptor, ?IntegrationProvider $provider): void {
		$id = $descriptor->getId();
		if (isset($this->descriptors[$id]) === true) {
			$this->logger->warning(
				sprintf('[LeafRegistry] duplicate leaf id "%s" — keeping first registration', $id)
			);
			return;
		}

		$this->descriptors[$id] = $descriptor;

		// Data leaves route through the existing per-object provider registry;
		// adding the provider here means ObjectIntegrationsController reaches it
		// unchanged. addProvider() applies its own first-wins on the provider id.
		if ($provider !== null) {
			$this->integrationRegistry->addProvider(provider: $provider);
		}

	}//end registerLeaf()

	/**
	 * Why a contributed leaf is skipped, or null when it is kept.
	 *
	 * Every refusal names the leaf and says what is wrong with it. A leaf that
	 * vanished without a line in the log looks exactly like an app that never
	 * contributed one.
	 *
	 * @param LeafDescriptor           $descriptor The contributed descriptor.
	 * @param IntegrationProvider|null $provider   The accompanying provider, or null.
	 *
	 * @return string|null The warning to log, or null when the leaf is sound.
	 *
	 * @spec openspec/changes/app-leaf-provider-registration/specs/leaf-provider-registration/spec.md
	 */
	private function refusalForLeaf(LeafDescriptor $descriptor, ?IntegrationProvider $provider): ?string {
		return self::identityRefusal(descriptor: $descriptor)
			?? self::vocabularyRefusal(descriptor: $descriptor)
			?? self::providerRefusal(descriptor: $descriptor, provider: $provider);
	}//end refusalForLeaf()

	/**
	 * The refusal for a leaf whose id is not kebab-case or that declares no kinds.
	 *
	 * @param LeafDescriptor $descriptor The announced leaf.
	 *
	 * @return string|null The refusal message, or null.
	 */
	private static function identityRefusal(LeafDescriptor $descriptor): ?string {
		$id = $descriptor->getId();

		if (preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $id) === 0) {
			return sprintf('[LeafRegistry] leaf id "%s" is not kebab-case — skipping', $id);
		}

		if ($descriptor->getKinds() === []) {
			return sprintf('[LeafRegistry] leaf "%s" declares no kinds — skipping', $id);
		}

		return null;
	}//end identityRefusal()

	/**
	 * The refusal for a leaf that declares an unknown kind or render mode.
	 *
	 * @param LeafDescriptor $descriptor The announced leaf.
	 *
	 * @return string|null The refusal message, or null.
	 */
	private static function vocabularyRefusal(LeafDescriptor $descriptor): ?string {
		return self::unknownKindsRefusal(descriptor: $descriptor)
			?? self::unknownRenderModeRefusal(descriptor: $descriptor);
	}//end vocabularyRefusal()

	/**
	 * Refuse a leaf that declares a kind outside the vocabulary.
	 *
	 * @param LeafDescriptor $descriptor The leaf.
	 *
	 * @return string|null The refusal, or null when every kind is known.
	 */
	private static function unknownKindsRefusal(LeafDescriptor $descriptor): ?string {
		$unknown = array_diff($descriptor->getKinds(), LeafDescriptor::VALID_KINDS);
		if ($unknown === []) {
			return null;
		}

		return sprintf(
			'[LeafRegistry] leaf "%s" declares unknown kind(s) "%s" — skipping',
			$descriptor->getId(),
			implode(', ', $unknown)
		);
	}//end unknownKindsRefusal()

	/**
	 * Refuse a leaf that declares a render mode outside the vocabulary.
	 *
	 * @param LeafDescriptor $descriptor The leaf.
	 *
	 * @return string|null The refusal, or null when the render mode is known.
	 */
	private static function unknownRenderModeRefusal(LeafDescriptor $descriptor): ?string {
		$renderMode = $descriptor->getRenderMode();
		if (in_array($renderMode, LeafDescriptor::VALID_RENDER_MODES, true) === true) {
			return null;
		}

		return sprintf(
			'[LeafRegistry] leaf "%s" declares unknown renderMode "%s" — skipping',
			$descriptor->getId(),
			$renderMode
		);
	}//end unknownRenderModeRefusal()

	/**
	 * The refusal for a data-provider leaf that supplied no provider.
	 *
	 * @param LeafDescriptor           $descriptor The announced leaf.
	 * @param IntegrationProvider|null $provider   The supplied provider.
	 *
	 * @return string|null The refusal message, or null.
	 */
	private static function providerRefusal(LeafDescriptor $descriptor, ?IntegrationProvider $provider): ?string {
		if ($descriptor->hasKind(LeafDescriptor::KIND_DATA_PROVIDER) === true && $provider === null) {
			return sprintf(
				'[LeafRegistry] leaf "%s" declares the data-provider kind but supplied no provider — skipping',
				$descriptor->getId()
			);
		}

		return null;
	}//end providerRefusal()

	/**
	 * Every collected leaf descriptor.
	 *
	 * @return array<int, LeafDescriptor> The descriptors.
	 *
	 * @spec openspec/changes/app-leaf-provider-registration/specs/leaf-provider-registration/spec.md
	 */
	public function getDescriptors(): array {
		$this->ensureLoaded();
		return array_values($this->descriptors);
	}//end getDescriptors()

	/**
	 * Look up a collected leaf descriptor by id.
	 *
	 * @param string $id The leaf id.
	 *
	 * @return LeafDescriptor|null The descriptor, or null when unknown.
	 *
	 * @spec openspec/changes/app-leaf-provider-registration/specs/leaf-provider-registration/spec.md
	 */
	public function getDescriptor(string $id): ?LeafDescriptor {
		$this->ensureLoaded();
		return $this->descriptors[$id] ?? null;
	}//end getDescriptor()

	/**
	 * Render the discovery rows for the OCS capabilities surface.
	 *
	 * Each row carries the leaf's id, label, requiredApp, surfaces, kinds,
	 * renderMode and current usability — everything a manifest app or admin UI
	 * needs to discover the leaf (including HOW a render-surface leaf renders)
	 * WITHOUT loading its JS bundle. Usability is derived from
	 * the installed/enabled state of the required app: a leaf whose required app
	 * is disabled is reported present but not currently usable.
	 *
	 * @return array<int, array<string,mixed>> The discovery rows.
	 *
	 * @spec openspec/changes/app-leaf-provider-registration/specs/leaf-discovery-parity/spec.md
	 */
	public function describeForCapabilities(): array {
		$rows = [];
		foreach ($this->getDescriptors() as $descriptor) {
			$row = $descriptor->toArray();
			$row['usable'] = $this->isUsable(descriptor: $descriptor);
			$rows[] = $row;
		}

		return $rows;
	}//end describeForCapabilities()

	/**
	 * Whether the app backing a leaf is installed and enabled.
	 *
	 * A leaf with no required app (null) rides on OpenRegister itself and is
	 * always usable.
	 *
	 * @param LeafDescriptor $descriptor The descriptor to test.
	 *
	 * @return bool True when the leaf is currently usable.
	 */
	private function isUsable(LeafDescriptor $descriptor): bool {
		$appId = $descriptor->getRequiredApp();
		if ($appId === null || $appId === '') {
			return true;
		}

		return $this->appManager->isEnabledForUser($appId);
	}//end isUsable()
}//end class
