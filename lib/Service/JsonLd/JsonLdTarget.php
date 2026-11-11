<?php

/**
 * OpenRegister JSON-LD target
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\JsonLd
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\JsonLd;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;

/**
 * The register and schema the nodes of one JSON-LD document belong to. Every
 * node, graph and `@id` built by {@see JsonLdSerializer} reads both, so they
 * travel as one.
 *
 * @spec openspec/specs/json-ld-output/spec.md
 */
final readonly class JsonLdTarget {

	/**
	 * Bundle the pair.
	 *
	 * @param Register $register The objects' register.
	 * @param Schema   $schema   The objects' schema.
	 */
	public function __construct(
		public Register $register,
		public Schema $schema,
	) {
	}//end __construct()
}//end class
