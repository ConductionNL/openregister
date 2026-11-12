<?php

/**
 * OpenRegister report render request
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

namespace OCA\OpenRegister\Search\Controller\Support;

/**
 * One request to render a dashboard: the dashboard identifier from the route
 * and the requested format, lowercased.
 *
 * @spec openspec/changes/retrofit-2026-05-24-b-ctrl-graphql-rt-dash/tasks.md#task-9
 */
final class ReportRenderRequest {
	/**
	 * Constructor.
	 *
	 * @param string $identifier Dashboard identifier (numeric id, uuid, or slug).
	 * @param string $format     The requested format, lowercased.
	 */
	public function __construct(
		public readonly string $identifier,
		public readonly string $format,
	) {
	}//end __construct()
}//end class
