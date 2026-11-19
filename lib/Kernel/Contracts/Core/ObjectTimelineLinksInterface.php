<?php
/**
 * ObjectTimelineLinksInterface: the timeline links beside an object, as a declared destruction asks for them.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts\Core
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts\Core;

/**
 * The email, calendar-event, contact and deck-card links drawn beside an object.
 *
 * A declared destruction scope (Core) counts them for its preview and destroys them
 * with the object; Integrations keeps the links and binds its answer in its own wiring.
 *
 * @spec openspec/specs/deletion-audit-trail/spec.md
 */
interface ObjectTimelineLinksInterface {

	/**
	 * Count the timeline link rows of an object; a source that cannot be read counts nothing.
	 *
	 * @param string $objectUuid UUID of the object.
	 *
	 * @return int The number of timeline link rows.
	 *
	 * @spec openspec/specs/deletion-audit-trail/spec.md
	 */
	public function countTimelineLinks(string $objectUuid): int;

	/**
	 * Destroy the timeline link rows of an object.
	 *
	 * @param string $objectUuid UUID of the object.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/deletion-audit-trail/spec.md
	 */
	public function destroyTimelineLinks(string $objectUuid): void;
}//end interface
