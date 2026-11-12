<?php

/**
 * OpenRegister TrimmedRequestParam
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller\Support
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

namespace OCA\OpenRegister\Controller\Support;

use OCP\IRequest;

/**
 * Reads an optional text parameter: trimmed, and null when absent, not text or blank.
 *
 * Shared by the link controllers (access links, calendar feeds), which each
 * carried their own copy.
 *
 * @spec openspec/changes/access-by-link-not-by-account/specs/public-access-links/spec.md
 */
final class TrimmedRequestParam {
	/**
	 * Read a request parameter as trimmed text.
	 *
	 * @param IRequest $request The request
	 * @param string   $name    The parameter name
	 *
	 * @return string|null The trimmed value, or null when absent, not a string or blank
	 *
	 * @spec openspec/changes/access-by-link-not-by-account/specs/public-access-links/spec.md
	 */
	public static function read(IRequest $request, string $name): ?string {
		$value = $request->getParam($name, null);

		if (is_string($value) === false) {
			return null;
		}

		$trimmed = trim($value);
		if ($trimmed === '') {
			return null;
		}

		return $trimmed;
	}//end read()
}//end class
