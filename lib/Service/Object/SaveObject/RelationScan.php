<?php

/**
 * OpenRegister relation scan
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object\SaveObject
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

namespace OCA\OpenRegister\Service\Object\SaveObject;

use OCA\OpenRegister\Db\Schema;

/**
 * One level of object data scanned for relations: the data, the path it sits at, and the schema whose `$ref` declarations count.
 *
 * @spec openspec/specs/linked-entity-types/spec.md
 */
final class RelationScan {
	/**
	 * Constructor.
	 *
	 * @param array       $data   The data array to scan.
	 * @param string      $prefix The current property path prefix; '' at the top level.
	 * @param Schema|null $schema The schema to read `$ref` declarations from, or null.
	 */
	public function __construct(
		public readonly array $data,
		public readonly string $prefix = '',
		public readonly Schema|null $schema = null,
	) {
	}//end __construct()
}//end class
