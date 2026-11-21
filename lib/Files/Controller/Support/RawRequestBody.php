<?php

/**
 * The raw body of the current request, bounded.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Files\Controller\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/large-file-handling/tasks.md#task-2-2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Files\Controller\Support;

/**
 * Reads the request body a part upload sends as raw bytes.
 *
 * IRequest exposes form and JSON parameters, not the raw body of a PUT with
 * binary content, so this reads `php://input`, never more than the bound.
 *
 * @spec openspec/changes/large-file-handling/tasks.md#task-2-2
 */
class RawRequestBody {

	/**
	 * The body, at most $maxBytes bytes; a longer body is cut at $maxBytes + 1 so its length still shows it is too long.
	 *
	 * @param int $maxBytes The most bytes a valid body has.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/large-file-handling/tasks.md#task-2-2
	 */
	public function read(int $maxBytes): string {
		$input = fopen('php://input', 'rb');
		if ($input === false) {
			return '';
		}

		$body = (string) stream_get_contents($input, $maxBytes + 1);
		fclose($input);

		return $body;
	}//end read()
}//end class
