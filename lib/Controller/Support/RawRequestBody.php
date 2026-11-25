<?php

/**
 * Reads a request's raw body, bounded.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A signature is computed over the exact bytes sent, so the body must be read
 * raw rather than through the parsed request parameters.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller\Support
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-calendar-and-webhook-triggers/tasks.md#2-incoming-webhook-trigger
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller\Support;

/**
 * The raw body of the current request.
 *
 * @spec openspec/changes/flow-calendar-and-webhook-triggers/tasks.md#2-incoming-webhook-trigger
 */
class RawRequestBody {

	/**
	 * The stream read.
	 *
	 * @param string $stream The stream, `php://input` for the current request.
	 */
	public function __construct(
		private readonly string $stream='php://input',
	) {
	}//end __construct()

	/**
	 * At most this many bytes of the body.
	 *
	 * @param int $max The most bytes read.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/flow-calendar-and-webhook-triggers/tasks.md#2-incoming-webhook-trigger
	 */
	public function read(int $max): string {
		$read = file_get_contents($this->stream, false, null, 0, $max);
		if ($read === false) {
			return '';
		}

		return $read;
	}//end read()
}//end class
