<?php

/**
 * OpenRegister EndpointExchange
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

/**
 * One endpoint call: the request that was sent and the result it produced.
 *
 * @spec openspec/specs/object-interactions/spec.md
 */
final class EndpointExchange {
	/**
	 * @param array<string, mixed> $request The request: method, path, data, headers.
	 * @param array<string, mixed> $result  The result: success, statusCode, response, optional error.
	 */
	public function __construct(
		public readonly array $request,
		public readonly array $result,
	) {
	}//end __construct()
}//end class
