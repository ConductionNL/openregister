<?php

/**
 * FlowTriggerSlugsInterface: the FlowTriggerSlugs as the object trigger listener reaches it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

/**
 * Register and schema slugs for a trigger subject, typed for the trigger listener.
 *
 * Declares only what callers outside the Service component use; the rest of the
 * class stays its own. Bound in ContractBindings to the same shared instance.
 */
interface FlowTriggerSlugsInterface {

	/**
	 * The slug of the register the given identifier names.
	 *
	 * Loads with RBAC and multitenancy OFF: this runs on the trigger path,
	 * inside the dispatch of whatever fired the event, where there may be no
	 * session — and a register's SLUG is its public name, not its data.
	 *
	 * @param string $identifier The register's id, uuid or slug.
	 *
	 * @return string The slug, or the trimmed identifier when it does not resolve.
	 *
	 * @spec openspec/specs/flow-engine/spec.md
	 */
	public function registerSlug(string $identifier): string;

	/**
	 * The slug of the schema the given identifier names.
	 *
	 * A bare schema slug is only unique within a register, but that is no
	 * hazard here: resolving a slug globally can only ever answer that same
	 * slug back, and ids and uuids are globally unique. Nothing is LOADED
	 * through the answer — it is a name, used for an equality comparison.
	 *
	 * @param string $identifier The schema's id, uuid or slug.
	 *
	 * @return string The slug, or the trimmed identifier when it does not resolve.
	 *
	 * @spec openspec/specs/flow-engine/spec.md
	 */
	public function schemaSlug(string $identifier): string;
}//end interface
