<?php

/**
 * The values a single-schema bulk save stamps on every object it prepares.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/openregister
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object\SaveObjects;

use OCA\OpenRegister\Db\Schema;

/**
 * Register, schema, owner and organisation shared by every object of one bulk save.
 */
final readonly class BulkObjectDefaults {

	/**
	 * Constructor.
	 *
	 * @param int|string  $registerId   The register id.
	 * @param int|string  $schemaId     The schema id.
	 * @param Schema      $schema       The schema used for metadata hydration and relation scanning.
	 * @param string|null $owner        The owner uid taken from the session.
	 * @param string|null $organisation The active organisation.
	 */
	public function __construct(
		public int|string $registerId,
		public int|string $schemaId,
		public Schema $schema,
		public ?string $owner,
		public ?string $organisation,
	) {
	}//end __construct()
}//end class
