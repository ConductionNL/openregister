<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\TalkLink
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\TalkLink;

/**
 * Which rooms the room picker lists: the viewer's rooms, optionally filtered by name.
 *
 * @spec openspec/specs/integration-talk/spec.md
 */
final class RoomPickerQuery {
	/**
	 * Build the query.
	 *
	 * @param TalkViewer  $viewer The user the rooms are listed for.
	 * @param string|null $search Optional case-insensitive name filter.
	 */
	public function __construct(
		public readonly TalkViewer $viewer,
		public readonly ?string $search,
	) {
	}//end __construct()
}//end class
