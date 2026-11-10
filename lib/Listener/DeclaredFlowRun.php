<?php

/**
 * OpenRegister DeclaredFlowRun
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
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-engine-unification/specs/flow-storage/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Listener;

/**
 * One `x-openregister-flows` declaration being imported: the identity
 * (app, name, trigger schema) its stored flow is matched and created by.
 *
 * Built by {@see SchemaFlowImportListener} per declaration; it never leaves
 * that class (ADR-011 Rule 3, modelled on CascadeRun).
 *
 * @spec openspec/changes/flow-engine-unification/specs/flow-storage/spec.md
 */
final class DeclaredFlowRun {

	/**
	 * The declaring app.
	 *
	 * @var string
	 */
	public string $app = '';

	/**
	 * The trimmed flow name.
	 *
	 * @var string
	 */
	public string $name = '';

	/**
	 * The declaring schema's slug, the flow's trigger schema.
	 *
	 * @var string
	 */
	public string $schemaSlug = '';
}//end class
