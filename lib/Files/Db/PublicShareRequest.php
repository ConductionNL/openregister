<?php

/**
 * A request to publish one file as a read-only public link share.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Db
 * @package  OCA\OpenRegister\Files\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Files\Db;

use DateTime;

/**
 * The file, who shares it, who owns it, and when the link stops working
 * ({@see FileMapper::publishFile()}).
 *
 * @spec openspec/changes/file-publication-window/specs/file-publication-window/spec.md#requirement-a-depublication-date-expires-the-public-share-req-fpw-102
 */
final class PublicShareRequest {
	/**
	 * Constructor.
	 *
	 * @param int           $fileId      The Nextcloud file id.
	 * @param string        $sharedBy    The user who shares the file.
	 * @param string        $shareOwner  The owner of the file.
	 * @param DateTime|null $depublished When the share stops working, or null for no end date.
	 */
	public function __construct(
		public readonly int $fileId,
		public readonly string $sharedBy,
		public readonly string $shareOwner,
		public readonly ?DateTime $depublished = null,
	) {
	}//end __construct()
}//end class
