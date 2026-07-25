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
final class LeafDescriptor
{

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
     * Constructor.
     *
     * @param string            $id                 Stable kebab-case id, unique across the registry and
     *                                              equal to the JS registration id.
     * @param string            $label              Human-readable label (already translated by the app).
     * @param string            $icon               Material Design Icons name (no `mdi-` prefix).
     * @param array<int,string> $kinds              Non-empty subset of VALID_KINDS.
     * @param string|null       $requiredApp        NC app id that must be installed/enabled, or null when
     *                                              always-available.
     * @param string|null       $group              Optional group used to cluster leaves in admin UI.
     * @param array<int,string> $surfaces           Render surfaces the leaf targets (subset of VALID_SURFACES);
     *                                              empty when the leaf offers no render surface.
     * @param string|null       $referenceType      Optional marker so a schema reference property can target
     *                                              this leaf's single-entity widget (ADR-019 / AD-18).
     * @param string|null       $requiresPermission Optional permission string gating visibility.
     *
     * @return void
     */
    public function __construct(
        private string $id,
        private string $label,
        private string $icon,
        private array $kinds,
        private ?string $requiredApp=null,
        private ?string $group=null,
        private array $surfaces=[],
        private ?string $referenceType=null,
        private ?string $requiresPermission=null,
    ) {
    }//end __construct()

    /**
     * Stable kebab-case identifier, equal to the JS registration id.
     *
     * @return string The leaf id.
     */
    public function getId(): string
    {
        return $this->id;

    }//end getId()

    /**
     * Human-readable label.
     *
     * @return string The label.
     */
    public function getLabel(): string
    {
        return $this->label;

    }//end getLabel()

    /**
     * Material Design Icons name (no `mdi-` prefix).
     *
     * @return string The icon name.
     */
    public function getIcon(): string
    {
        return $this->icon;

    }//end getIcon()

    /**
     * The kinds this leaf declares.
     *
     * @return array<int,string> A non-empty subset of VALID_KINDS.
     */
    public function getKinds(): array
    {
        return $this->kinds;

    }//end getKinds()

    /**
     * Whether the leaf declares the given kind.
     *
     * @param string $kind One of the KIND_* constants.
     *
     * @return bool True when the kind is present.
     */
    public function hasKind(string $kind): bool
    {
        return in_array($kind, $this->kinds, true);

    }//end hasKind()

    /**
     * NC app id the leaf requires, or null when always-available.
     *
     * @return string|null The required app id.
     */
    public function getRequiredApp(): ?string
    {
        return $this->requiredApp;

    }//end getRequiredApp()

    /**
     * Optional group used to cluster leaves in admin UI.
     *
     * @return string|null The group, or null.
     */
    public function getGroup(): ?string
    {
        return $this->group;

    }//end getGroup()

    /**
     * Render surfaces the leaf targets.
     *
     * @return array<int,string> A subset of VALID_SURFACES.
     */
    public function getSurfaces(): array
    {
        return $this->surfaces;

    }//end getSurfaces()

    /**
     * Optional reference-type marker for single-entity targeting.
     *
     * @return string|null The reference type, or null.
     */
    public function getReferenceType(): ?string
    {
        return $this->referenceType;

    }//end getReferenceType()

    /**
     * Optional permission string gating visibility.
     *
     * @return string|null The permission, or null.
     */
    public function requiresPermission(): ?string
    {
        return $this->requiresPermission;

    }//end requiresPermission()

    /**
     * Render the discovery-facing shape for the OCS capabilities surface.
     *
     * Carries only availability + capability metadata (no components, no verb):
     * id, label, requiredApp, surfaces, kinds. Usability is derived by
     * `LeafRegistry` from the installed state of `requiredApp`.
     *
     * @return array<string,mixed> The discovery descriptor.
     */
    public function toArray(): array
    {
        return [
            'id'          => $this->id,
            'label'       => $this->label,
            'icon'        => $this->icon,
            'requiredApp' => $this->requiredApp,
            'group'       => $this->group,
            'surfaces'    => $this->surfaces,
            'kinds'       => $this->kinds,
        ];

    }//end toArray()
}//end class
