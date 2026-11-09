<?php

/**
 * OpenRegister service health probe
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Files\Controller\Support
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

namespace OCA\OpenRegister\Files\Controller\Support;

/**
 * One health check against an extraction service: which service, where, and with which headers.
 *
 * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-1/tasks.md#task-2
 */
final class ServiceHealthProbe {
	/**
	 * Constructor.
	 *
	 * @param string   $serviceName Human-readable service name for the answer.
	 * @param string   $url         The full URL to check (e.g. endpoint + '/health').
	 * @param string[] $headers     HTTP headers; empty sends Content-Type: application/json.
	 */
	public function __construct(
		public readonly string $serviceName,
		public readonly string $url,
		public readonly array $headers = [],
	) {
	}//end __construct()
}//end class
