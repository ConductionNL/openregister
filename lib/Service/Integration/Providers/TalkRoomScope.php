<?php

/**
 * TalkRoomScope: whose rooms a Talk marker scan reads, and the marker it looks for.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Integration\Providers
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Integration\Providers;

/**
 * The user whose Talk rooms are scanned and the `[or:{uuid}]` marker of the object.
 */
final class TalkRoomScope {

	/**
	 * The marker a linked room's name carries.
	 *
	 * @var string
	 */
	public readonly string $marker;

	/**
	 * Constructor.
	 *
	 * @param string $userId   The user whose rooms are scanned.
	 * @param string $objectId The object uuid the marker names.
	 */
	public function __construct(public readonly string $userId, string $objectId) {
		$this->marker = '[or:' . $objectId . ']';
	}//end __construct()
}//end class
