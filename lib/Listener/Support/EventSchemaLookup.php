<?php

/**
 * OpenRegister event schema lookup
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Listener
 * @package  OCA\OpenRegister\Listener\Support
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

namespace OCA\OpenRegister\Listener\Support;

use OCA\OpenRegister\Db\SchemaCatalogInterface;
use Psr\Log\LoggerInterface;

/**
 * Looks up the schema, register and table of the object an event carries, and
 * logs what goes wrong without failing the event.
 *
 * @spec exclude collaborator aggregate under ADR-011 Rule 4, no behaviour of its own
 */
final class EventSchemaLookup {
	/**
	 * Bundle the services.
	 *
	 * @param SchemaCatalogInterface $catalog The register, schema and object-table mappers.
	 * @param LoggerInterface        $logger  Where a lookup or handling failure is reported.
	 */
	public function __construct(
		public readonly SchemaCatalogInterface $catalog,
		public readonly LoggerInterface $logger,
	) {
	}//end __construct()
}//end class
