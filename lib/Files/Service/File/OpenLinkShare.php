<?php

/**
 * OpenLinkShare
 *
 * This file is part of the OpenRegister app for Nextcloud.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/openregister
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Files\Service\File;

use DateTimeImmutable;
use OCP\Constants;
use OCP\Share\IShare;

/**
 * Whether a public link share makes its file public without anything more.
 *
 * Only such a share lets an anonymous caller fetch the file through the
 * object routes. A share that asks for a password, that has passed its
 * expiration date, that hides downloads or that does not grant read is
 * honoured by Nextcloud's own share page only.
 *
 * @spec openspec/specs/file-actions/spec.md
 */
final class OpenLinkShare {

	/**
	 * Whether the share opens its file to anyone holding the object route.
	 *
	 * @param IShare $share The share.
	 *
	 * @return bool True for a readable link share without password, passed expiry or hidden download.
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	public static function admits(IShare $share): bool {
		if ($share->getShareType() !== IShare::TYPE_LINK) {
			return false;
		}

		if ($share->getPassword() !== null && $share->getPassword() !== '') {
			return false;
		}

		if ($share->getHideDownload() === true) {
			return false;
		}

		if (($share->getPermissions() & Constants::PERMISSION_READ) === 0) {
			return false;
		}

		$expiration = $share->getExpirationDate();
		return $expiration === null || $expiration > new DateTimeImmutable();
	}//end admits()
}//end class
