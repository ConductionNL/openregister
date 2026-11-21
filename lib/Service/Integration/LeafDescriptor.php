<?php

/**
 * LeafDescriptor — the server-side declaration a sibling app contributes when
 * it registers a leaf on OpenRegister objects through
 * `RegisterLeafProvidersEvent`.
 *
 * A leaf has two faces: a render surface (a tab/widget mounted by the app's own
 * Vue bundle) and/or a data provider (read/append data served from the app's own
 * store). This descriptor carries the availability + capability metadata for
 * both faces — it deliberately carries NO Vue components: render components are
 * supplied on the JS layer under the same `id`, and the descriptor `id` MUST
 * equal the JS registration id so the two layers correlate (ADR-019 parity).
 *
 * The descriptor is render-and-read only by construction (ADR-066): it exposes
 * no verb, command, or handler. Cross-app commands stay ADR-041 typed events.
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

/**
 * Immutable value object describing one contributed leaf.
 *
 * The `kinds` set names which leaf kinds the app offers, each independently
 * declarable:
 *   - `render-surface` — the app mounts a tab + widget under the shared id.
 *   - `data-provider`  — the app serves read/append data via an
 *                        `IntegrationProvider` supplied on the same registration.
 *   - `agent-runner`   — reserved forward-reference (ADR-066 / hermiq change B);
 *                        this change assigns it no behaviour.
 */
final class LeafDescriptor {

	/**
	 * Kind: the leaf mounts a render surface (tab + widget) on the JS layer.
	 *
	 * @var string
	 */
	public const KIND_RENDER_SURFACE = 'render-surface';

	/**
	 * Kind: the leaf serves read/append data through an IntegrationProvider.
	 *
	 * @var string
	 */
	public const KIND_DATA_PROVIDER = 'data-provider';

	/**
	 * Kind: reserved forward-reference for an agent-runner leaf (ADR-066).
	 *
	 * @var string
	 */
	public const KIND_AGENT_RUNNER = 'agent-runner';

	/**
	 * Every kind a descriptor MAY declare.
	 *
	 * @var array<int,string>
	 */
	public const VALID_KINDS = [
		self::KIND_RENDER_SURFACE,
		self::KIND_DATA_PROVIDER,
		self::KIND_AGENT_RUNNER,
	];

	/**
	 * Every render surface a descriptor MAY target.
	 *
	 * @var array<int,string>
	 */
	public const VALID_SURFACES = [
		'user-dashboard',
		'app-dashboard',
		'detail-page',
		'single-entity',
	];

	/**
	 * Render mode: the render surface is a Single File Component (tab + widget)
	 * interpreted under the host's own Vue runtime. The default — same-major and
	 * built-in leaves keep the existing SFC contract unchanged.
	 *
	 * @var string
	 */
	public const RENDER_MODE_COMPONENT = 'component';

	/**
	 * Render mode: the render surface is a `mount(el, props)` / `unmount(el)`
	 * pair the host invokes against a bare, host-owned DOM element, so a leaf
	 * built against a different Vue major than the host still renders (the DOM
	 * element is the neutral hand-off boundary).
	 *
	 * @var string
	 */
	public const RENDER_MODE_MOUNT = 'mount';

	/**
	 * Every render mode a render-surface descriptor MAY declare.
	 *
	 * @var array<int,string>
	 */
	/**
	 * How a render surface's bundle reaches the page: the shared `leaves` entry.
	 *
	 * The app builds `js/<app>-leaves.js` and OpenRegister's `LeafScriptListener`
	 * puts it on the pages that need it. THIS IS THE ONLY CONVENTION THE
	 * PLATFORM CAN VERIFY, because it is the only one where the platform does
	 * the loading, so it is the only one whose absence is provable.
	 */
	public const LOADS_VIA_SHARED_ENTRY = 'shared-entry';

	/**
	 * How a render surface's bundle reaches the page: the app loads it itself.
	 *
	 * Typically `Util::addInitScript()` in the app's own `Application::boot()`,
	 * putting a small registration bundle on EVERY page so the leaf registers
	 * wherever another app renders the integration registry. hermiq and decidiq
	 * both do this, and openregister#3954 nearly refused both of them for
	 * shipping no `<app>-leaves.js`, which they do not need.
	 */
	public const LOADS_VIA_OWN_SCRIPT = 'own-script';

	/**
	 * How a render surface's bundle reaches the page: it is already there.
	 *
	 * A built-in leaf rides OpenRegister's own bundle. There is nothing to load
	 * and nothing to check.
	 */
	public const LOADS_ALREADY_PRESENT = 'already-present';

	/**
	 * The conventions are named, not inferred, and that is the whole point. 🔴
	 *
	 * Issue openregister#3954 tried to infer this from the filesystem and was wrong
	 * twice in one measurement: it read hermiq and decidiq as dark because they
	 * ship no `<app>-leaves.js`, when both load their own bundle on every page.
	 * Whether a bundle reaches the page is a fact about the PAGE; the registry
	 * sees only the filesystem.
	 *
	 * 🔑 IF A FOURTH CONVENTION APPEARS, ADD IT HERE RATHER THAN FOLDING IT
	 * INTO ONE OF THESE. `own-script` means "the app guarantees it"; a genuinely
	 * different mechanism that the platform could verify deserves its own name,
	 * because the whole value of this list is that `shared-entry` is checkable
	 * and the others are taken on the app's word.
	 *
	 * @var array<int, string>
	 */
	public const VALID_LOAD_STRATEGIES = [
		self::LOADS_VIA_SHARED_ENTRY,
		self::LOADS_VIA_OWN_SCRIPT,
		self::LOADS_ALREADY_PRESENT,
	];

	public const VALID_RENDER_MODES = [
		self::RENDER_MODE_COMPONENT,
		self::RENDER_MODE_MOUNT,
	];

	/**
	 * The leaf's availability; always available when none was given.
	 *
	 * @var LeafAvailability
	 */
	private readonly LeafAvailability $availability;

	/**
	 * The leaf's render-surface face; the defaults when none was given.
	 *
	 * @var LeafRendering
	 */
	private readonly LeafRendering $rendering;

	/**
	 * Constructor.
	 *
	 * @param LeafIdentity          $identity     The id, label, icon and group of the leaf.
	 * @param array<int,string>     $kinds        Non-empty subset of VALID_KINDS.
	 * @param LeafAvailability|null $availability The app and permission the leaf depends on, or null
	 *                                            when always available.
	 * @param LeafRendering|null    $rendering    Surfaces, reference type, render mode and load strategy of
	 *                                            the render surface, or null for the defaults.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly LeafIdentity $identity,
		private readonly array $kinds,
		?LeafAvailability $availability = null,
		?LeafRendering $rendering = null,
	) {
		$this->availability = $availability ?? new LeafAvailability();
		$this->rendering = $rendering ?? new LeafRendering();
	}//end __construct()

	/**
	 * How this leaf's render bundle reaches the page, if it has said.
	 *
	 * @return string|null One of VALID_LOAD_STRATEGIES, or null when unstated.
	 */
	public function getLoadStrategy(): ?string {
		return $this->rendering->loadStrategy;
	}//end getLoadStrategy()

	/**
	 * Whether this leaf claims the one convention the platform can verify.
	 *
	 * @return bool Whether it declares the shared entry.
	 *
	 * @spec openspec/changes/app-leaf-provider-registration/specs/leaf-provider-registration/spec.md
	 */
	public function claimsSharedEntry(): bool {
		return ($this->rendering->loadStrategy === self::LOADS_VIA_SHARED_ENTRY);
	}//end claimsSharedEntry()

	/**
	 * Stable kebab-case identifier, equal to the JS registration id.
	 *
	 * @return string The leaf id.
	 */
	public function getId(): string {
		return $this->identity->id;
	}//end getId()

	/**
	 * Human-readable label.
	 *
	 * @return string The label.
	 */
	public function getLabel(): string {
		return $this->identity->label;
	}//end getLabel()

	/**
	 * Material Design Icons name (no `mdi-` prefix).
	 *
	 * @return string The icon name.
	 */
	public function getIcon(): string {
		return $this->identity->icon;
	}//end getIcon()

	/**
	 * The kinds this leaf declares.
	 *
	 * @return array<int,string> A non-empty subset of VALID_KINDS.
	 */
	public function getKinds(): array {
		return $this->kinds;
	}//end getKinds()

	/**
	 * Whether the leaf declares the given kind.
	 *
	 * @param string $kind One of the KIND_* constants.
	 *
	 * @return bool True when the kind is present.
	 */
	public function hasKind(string $kind): bool {
		return in_array($kind, $this->kinds, true);
	}//end hasKind()

	/**
	 * NC app id the leaf requires, or null when always-available.
	 *
	 * @return string|null The required app id.
	 */
	public function getRequiredApp(): ?string {
		return $this->availability->requiredApp;
	}//end getRequiredApp()

	/**
	 * Optional group used to cluster leaves in admin UI.
	 *
	 * @return string|null The group, or null.
	 */
	public function getGroup(): ?string {
		return $this->identity->group;
	}//end getGroup()

	/**
	 * Render surfaces the leaf targets.
	 *
	 * @return array<int,string> A subset of VALID_SURFACES.
	 */
	public function getSurfaces(): array {
		return $this->rendering->surfaces;
	}//end getSurfaces()

	/**
	 * Optional reference-type marker for single-entity targeting.
	 *
	 * @return string|null The reference type, or null.
	 */
	public function getReferenceType(): ?string {
		return $this->rendering->referenceType;
	}//end getReferenceType()

	/**
	 * Optional permission string gating visibility.
	 *
	 * @return string|null The permission, or null.
	 */
	public function requiresPermission(): ?string {
		return $this->availability->requiresPermission;
	}//end requiresPermission()

	/**
	 * How a render-surface leaf renders: `component` (default) or `mount`.
	 *
	 * A `component` leaf ships an SFC tab/widget the host interprets under its
	 * own Vue runtime; a `mount` leaf ships a `mount`/`unmount` pair the host
	 * invokes against a bare DOM element so the leaf renders with its own Vue
	 * major. The value is validated against VALID_RENDER_MODES at registration.
	 *
	 * @return string One of the RENDER_MODE_* constants.
	 */
	public function getRenderMode(): string {
		return $this->rendering->renderMode;
	}//end getRenderMode()

	/**
	 * Render the discovery-facing shape for the OCS capabilities surface.
	 *
	 * Carries only availability + capability metadata (no components, no verb):
	 * id, label, requiredApp, surfaces, kinds, renderMode. Usability is derived
	 * by `LeafRegistry` from the installed state of `requiredApp`. `renderMode`
	 * lets a manifest app or admin UI learn HOW a render-surface leaf renders
	 * (SFC component vs mount hand-off) without loading its JS bundle.
	 *
	 * @return array<string,mixed> The discovery descriptor.
	 */
	public function toArray(): array {
		return [
			'id' => $this->identity->id,
			'label' => $this->identity->label,
			'icon' => $this->identity->icon,
			'requiredApp' => $this->availability->requiredApp,
			'group' => $this->identity->group,
			'surfaces' => $this->rendering->surfaces,
			'kinds' => $this->kinds,
			'renderMode' => $this->rendering->renderMode,
		];

	}//end toArray()
}//end class
