<?php
/**
 * SearchTrailRecorderInterface: where the object search records an executed search.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts\Core
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts\Core;

use OCA\OpenRegister\Service\SearchMeasurement;

/**
 * The search trail behind the object search.
 *
 * The object search (Core) buffers its executed searches and hands each one here
 * after the response; Compliance keeps the trail and binds its answer in its own
 * service bindings.
 *
 * @spec openspec/specs/search-trail-recording/spec.md
 */
interface SearchTrailRecorderInterface {

	/**
	 * Record one executed search as a search trail.
	 *
	 * @param array<string, mixed> $query    The search query parameters.
	 * @param SearchMeasurement    $measured The result counts, response time and execution type.
	 *
	 * @return object The recorded trail.
	 *
	 * @throws \Exception If the trail cannot be recorded.
	 *
	 * @spec openspec/specs/search-trail-recording/spec.md
	 */
	public function createSearchTrail(array $query, SearchMeasurement $measured): object;
}//end interface
