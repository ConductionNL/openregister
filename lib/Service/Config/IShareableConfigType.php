<?php

/**
 * A kind of configuration an app can share over GitHub.
 *
 * This is the seam that makes federated configuration sharing universal. Rather
 * than OpenRegister knowing how to package every app's configuration, each app
 * contributes a type that knows how to package its own — a flow, a case type, a
 * payroll pack, an NL Design theme. OpenRegister owns the engine (bundle,
 * publish, discover, install, pin); a type owns only the two things that are
 * specific to it: turning its configuration into portable files, and turning
 * those files back into applied configuration.
 *
 * The contract is deliberately storage-agnostic. A type is NOT required to store
 * its configuration as OpenRegister objects — it may live anywhere the app keeps
 * it (an app's `IConfig`, a file, a database) — because (de)serialisation is the
 * type's responsibility. That is what lets an NL Design theme (config in
 * `IConfig`) share through the same mechanism as a flow (config in an OR object).
 *
 * Contributed through {@see RegisterShareableConfigTypesEvent}, the same
 * registration idiom as flow nodes and MCP tool providers.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Config
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/federated-config-sharing/specs/federated-config-sharing/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Config;

/**
 * The contract every shareable configuration type implements.
 */
interface IShareableConfigType
{
    /**
     * The stable type id (e.g. `openregister.flows`).
     *
     * @return string The id.
     */
    public function getId(): string;

    /**
     * The human name shown when sharing or browsing.
     *
     * @return string The display name.
     */
    public function getDisplayName(): string;

    /**
     * The GitHub topic a published config of this type is tagged with, and by
     * which it is discovered (e.g. `openregister-flow`).
     *
     * @return string The discovery topic.
     */
    public function getTopic(): string;

    /**
     * Package a selection of this type's configuration into a portable bundle.
     *
     * The bundle is a plain, instance-independent structure: no ids, uuids,
     * owners or organisations, and — critically — no secret values (a bundle may
     * carry a credential *requirement*, never a credential). What `$selection`
     * means is the type's own affair (a list of flow uuids, a theme name, …).
     *
     * @param array $selection What to share, in the type's own terms.
     *
     * @return array The portable bundle: `{type, version, ...content}`.
     *
     * @spec openspec/changes/federated-config-sharing/specs/federated-config-sharing/spec.md
     */
    public function serialise(array $selection): array;

    /**
     * Apply a bundle of this type to this instance.
     *
     * The inverse of {@see self::serialise()}: read the portable content and
     * create/update the app's configuration from it. Returns a result the engine
     * surfaces (what was installed), so a preview and an install share a shape.
     *
     * @param array $bundle A bundle previously produced by a type of this id.
     *
     * @return array The install result (e.g. `{installed: [...]}`).
     *
     * @spec openspec/changes/federated-config-sharing/specs/federated-config-sharing/spec.md
     */
    public function deserialise(array $bundle): array;
}//end interface
