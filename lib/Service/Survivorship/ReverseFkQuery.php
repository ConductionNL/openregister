<?php

/**
 * One reverse-FK source lookup: which master, in which source schema and field.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Survivorship
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Survivorship;

/**
 * The master whose source records are looked up, and the source schema,
 * reference field and register they are looked up in. Building the filter,
 * running the query and re-filtering its result all read the same four, so
 * {@see SourceRecordResolver} passes them as one.
 *
 * @spec openspec/changes/archive/2026-10-05-mdm-reverse-fk-source-resolution/tasks.md#2.1
 */
final readonly class ReverseFkQuery {

	/**
	 * Bundle the lookup.
	 *
	 * @param string $masterUuid     Master object uuid.
	 * @param string $sourceSchema   Slug/id of the source schema.
	 * @param string $referenceField Field on a source object holding the master uuid.
	 * @param string $sourceRegister Register slug/id; empty means unset.
	 *
	 * @spec openspec/changes/archive/2026-10-05-mdm-reverse-fk-source-resolution/tasks.md#2.1
	 */
	public function __construct(
		public string $masterUuid,
		public string $sourceSchema,
		public string $referenceField,
		public string $sourceRegister,
	) {
	}//end __construct()
}//end class
