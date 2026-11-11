<?php

/**
 * OpenRegister get object sources: where a virtual schema's objects come from, and where a missing source is reported
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object
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

namespace OCA\OpenRegister\Service\Object;

use OCA\OpenRegister\Service\ObjectSource\ObjectSourceRegistry;
use Psr\Log\LoggerInterface;

/**
 * Get object sources: where a virtual schema's objects come from, and where a missing source is reported.
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
final class GetObjectSources {
	/**
	 * Bundle the services.
	 *
	 * @param ObjectSourceRegistry $objectSourceRegistry Registry of object-source providers (virtual schemas).
	 * @param LoggerInterface      $logger               Logger for object-source delegation warnings.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function __construct(
		public readonly ObjectSourceRegistry $objectSourceRegistry,
		public readonly LoggerInterface $logger,
	) {
	}//end __construct()
}//end class
