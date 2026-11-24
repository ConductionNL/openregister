<?php

/**
 * OpenRegister ApiClientName.
 *
 * The official client library a request names in its User-Agent, read with a
 * closed, anchored pattern so the caller record never stores a raw header.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ApiCaller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/api-client-libraries/specs/api-client-libraries/spec.md#requirement-the-caller-record-names-the-client-library
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ApiCaller;

/**
 * Reads the client library name and version from a User-Agent.
 *
 * Only `openregister-client-ts/<x.y.z>` and `openregister-client-python/<x.y.z>`
 * at the start of the header count. Anything else is the empty string: a raw
 * browser User-Agent would add a row per browser build and turn the caller
 * record into a fingerprint store (design D-4).
 */
final class ApiClientName {

	/**
	 * The column width; the pattern cannot produce a longer value.
	 */
	public const MAX_LENGTH = 48;

	/**
	 * The anchored pattern. Each version part is capped at six digits, so the
	 * longest match, the Python name with three six-digit parts, is 47 characters.
	 */
	private const PATTERN = '#^openregister-client-(ts|python)/(\d{1,6}\.\d{1,6}\.\d{1,6})(?!\d)#';

	/**
	 * The client name and version a User-Agent names, or the empty string.
	 *
	 * @param string $userAgent The User-Agent header.
	 *
	 * @return string `openregister-client-<language>/<version>`, or ''.
	 *
	 * @spec openspec/changes/api-client-libraries/specs/api-client-libraries/spec.md#requirement-the-caller-record-names-the-client-library
	 */
	public static function fromUserAgent(string $userAgent): string {
		if (preg_match(self::PATTERN, $userAgent, $match) !== 1) {
			return '';
		}

		return substr('openregister-client-'.$match[1].'/'.$match[2], 0, self::MAX_LENGTH);
	}//end fromUserAgent()
}//end class
