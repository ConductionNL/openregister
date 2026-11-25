<?php
/**
 * AccessLinkNamesInterface: the label of the access link a note author names.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts;

/**
 * The label of an access link, for an area that shows who acted through one.
 *
 * Core owns the links; Notifications asks it for the name of the link a note
 * was left through (the `note.author` placeholder).
 *
 * @spec openspec/changes/notifications-new-notes-and-referrers/specs/notificatie-engine/spec.md#requirement-a-rule-can-fire-when-a-note-is-added
 */
interface AccessLinkNamesInterface {

	/**
	 * The label of the link a principal id (`link:<uuid>`) names.
	 *
	 * @param string $principal The principal id, as an actor id of a link author.
	 *
	 * @return string|null The link's label; null when the id names no link or the link is unknown.
	 *
	 * @throws \Throwable When reading the link fails.
	 *
	 * @spec openspec/changes/notifications-new-notes-and-referrers/specs/notificatie-engine/spec.md#requirement-a-rule-can-fire-when-a-note-is-added
	 */
	public function linkName(string $principal): ?string;
}//end interface
