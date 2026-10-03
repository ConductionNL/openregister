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
class LeafRegistry {

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
	 * @param LeafBundle|null $leafBundleService Answers whether an app's leaf can render. Nullable and last so
	 *                                           no construction site shifts; absent, one is built from the app
	 *                                           manager this class already holds.
	 *
	 * @return void
	 */
	public function __construct(
		private IEventDispatcher $eventDispatcher,
		private IntegrationRegistry $integrationRegistry,
		private IAppManager $appManager,
		private LoggerInterface $logger,
		// LAST AND NULLABLE so every existing construction keeps working; the
		// container always supplies it. Built from the app manager this class
		// already holds when it is absent, so the answer is never a second one.
		private ?LeafBundle $leafBundleService = null,
	) {
		$this->leafBundle = ($leafBundleService ?? new LeafBundle($appManager));
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
		if ($descriptor->hasKind(LeafDescriptor::KIND_RENDER_SURFACE) === false) {
			return true;
		}

		$providingApp = $descriptor->getRequiredApp();
		if ($providingApp === null || $providingApp === '') {
			return true;
		}

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
		try {
			if ($this->appManager->isEnabledForUser($providingApp) === false) {
				return true;
			}
		} catch (Throwable) {
			return true;
		}

		if ($this->leafBundle->existsFor(appId: $providingApp) === true) {
			return true;
		}

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
		if ($descriptor->claimsSharedEntry() === true) {
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

			return false;
		}

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

		return true;
	}//end renderSurfaceCanRender()

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

		try {
			$this->eventDispatcher->dispatchTyped($event);
		} catch (\Throwable $e) {
			// A broken listener costs its own leaf, not everyone else's: the
			// alternative is one bad app removing leaves from the instance.
			// Whatever was registered on the event BEFORE the throw is still
			// collected below, since the event is mutated in place.
			$this->logger->warning(
				'[LeafRegistry] a leaf listener threw during dispatch: {message}',
				['message' => $e->getMessage(), 'exception' => $e]
			);
		}

		// Collect every leaf that reached the event, guarding each one so a
		// single bad descriptor cannot break the rest of the catalogue.
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

	}//end ensureLoaded()

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

	}//end collectLeaf()

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
		$id = $descriptor->getId();

		if (preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $id) === 0) {
			return sprintf('[LeafRegistry] leaf id "%s" is not kebab-case — skipping', $id);
		}

		$kinds = $descriptor->getKinds();
		if ($kinds === []) {
			return sprintf('[LeafRegistry] leaf "%s" declares no kinds — skipping', $id);
		}

		$unknown = array_diff($kinds, LeafDescriptor::VALID_KINDS);
		if ($unknown !== []) {
			return sprintf(
				'[LeafRegistry] leaf "%s" declares unknown kind(s) "%s" — skipping',
				$id,
				implode(', ', $unknown)
			);
		}

		$renderMode = $descriptor->getRenderMode();
		if (in_array($renderMode, LeafDescriptor::VALID_RENDER_MODES, true) === false) {
			return sprintf(
				'[LeafRegistry] leaf "%s" declares unknown renderMode "%s" — skipping',
				$id,
				$renderMode
			);
		}

		if ($descriptor->hasKind(LeafDescriptor::KIND_DATA_PROVIDER) === true && $provider === null) {
			return sprintf(
				'[LeafRegistry] leaf "%s" declares the data-provider kind but supplied no provider — skipping',
				$id
			);
		}

		return null;
	}//end refusalForLeaf()

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
