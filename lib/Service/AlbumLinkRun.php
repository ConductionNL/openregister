<?php

/**
 * Album Link Run
 *
 * One linking or refresh of an NC Photos album by PhotoLinkService: the user
 * acting, the album, and the album info once it has been read.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Service
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://www.OpenRegister.app
 *
 * @spec exclude ADR-011 run object private to PhotoLinkService; the album-link contract is owned by the integration-photos capability.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

/**
 * The user and album one PhotoLinkService operation works on; never leaves that class.
 *
 * @spec exclude ADR-011 run object private to PhotoLinkService.
 */
final class AlbumLinkRun {
	/**
	 * Album info `{ name, coverPhotoUrl, photoCount, lastEdited }`, once read or composed.
	 *
	 * @var array<string,mixed>|null
	 */
	public ?array $info = null;

	/**
	 * Constructor.
	 *
	 * @param string $uid     The user linking or reading the album.
	 * @param int    $albumId NC Photos album id.
	 */
	public function __construct(
		public readonly string $uid,
		public readonly int $albumId,
	) {
	}//end __construct()
}//end class
