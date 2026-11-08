<?php

/**
 * ObjectActionDefinitionInterface: what callers outside the component use of ObjectActionDefinition.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ObjectAction
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ObjectAction;

use OCA\OpenRegister\Service\Flow\FlowNextHint;

/**
 * Declares only what callers outside the component use of ObjectActionDefinition; the rest
 * of the class stays its own.
 */
interface ObjectActionDefinitionInterface {

	/**
	 * Whether this action can be run, rather than only granted.
	 *
	 * @return bool True when it carries a body.
	 *
	 * @spec openspec/changes/object-actions-declared-and-run/specs/declared-actions/spec.md#requirement-a-declared-action-may-carry-inputs-a-precondition-surfaces-and-one-body
	 */
	public function isRunnable(): bool;

	/**
	 * Whether a host may offer this action on a selection.
	 *
	 * @return bool True when `bulk` is among its surfaces.
	 *
	 * @spec openspec/changes/object-actions-declared-and-run/specs/object-actions/spec.md#requirement-selections-run-per-object-and-large-selections-become-a-bulk-job
	 */
	public function offersBulk(): bool;

	/**
	 * Where the host goes after a selection run: the declared `next`, or the list.
	 *
	 * @return string One of the FlowNextHint hints.
	 *
	 * @spec openspec/changes/object-actions-declared-and-run/specs/object-actions/spec.md#requirement-selections-run-per-object-and-large-selections-become-a-bulk-job
	 */
	public function nextAfterSelection(): string;
}//end interface
