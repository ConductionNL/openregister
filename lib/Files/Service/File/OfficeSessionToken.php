<?php

/**
 * OpenRegister office session token
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Files\Service\File
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Files\Service\File;

/**
 * The WOPI access token of an Office session, its expiry and whether it may save.
 *
 * @spec openspec/specs/file-actions/spec.md
 */
final class OfficeSessionToken {
	/**
	 * Bundle the token.
	 *
	 * @param string $token    The WOPI access token.
	 * @param int    $tokenTtl The token expiry, as a unix timestamp in milliseconds.
	 * @param bool   $readOnly Whether the token may not save.
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	public function __construct(
		public readonly string $token,
		public readonly int $tokenTtl,
		public readonly bool $readOnly,
	) {
	}//end __construct()
}//end class
