<?php

/**
 * CrossOrganisationMarkerChangeInterface: the cross-organisation marker comparison the schema save paths run.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rbac;

/**
 * The methods of CrossOrganisationMarkerChange that callers outside its component use.
 * Callers depend on this interface, not on the class.
 */
interface CrossOrganisationMarkerChangeInterface {

	/**
	 * Whether the new block holds a marked rule the old one did not.
	 *
	 * Rules are compared whole and by where they stand, with their keys in a
	 * fixed order, so a marked rule whose group or match changed, or that moved
	 * to another action, counts as new. Each marked rule counts as often as it
	 * occurs.
	 *
	 * @param mixed $old The authorization block before the change.
	 * @param mixed $new The authorization block after the change.
	 *
	 * @return bool True when the change adds a crossing.
	 *
	 * @spec openspec/changes/rbac-cross-organisation-is-explicit/specs/rbac-scopes/spec.md
	 */
	public function widens(mixed $old, mixed $new): bool;

	/**
	 * The authorization block a schema stores for a value as it was sent: the
	 * entity setter decodes a JSON text, so that text is judged as its decoded form.
	 *
	 * @param mixed $authorization The authorization value as sent.
	 *
	 * @return mixed The decoded block, or the value itself when it is no text.
	 *
	 * @spec openspec/changes/rbac-cross-organisation-is-explicit/specs/rbac-scopes/spec.md
	 */
	public function asStored(mixed $authorization): mixed;
}//end interface
