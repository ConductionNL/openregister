<?php

/**
 * The business data of one object and the schema its relations are scanned against.
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
 * What a relation scan reads: an object's business data and its schema.
 */
final readonly class RelationScan {

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $businessData The object's business data.
	 * @param Schema               $schema       The object's schema.
	 */
	public function __construct(
		public array $businessData,
		public Schema $schema,
	) {
	}//end __construct()
}//end class
