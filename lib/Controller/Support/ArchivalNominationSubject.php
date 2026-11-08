<?php

/**
 * OpenRegister archival nomination subject
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

namespace OCA\OpenRegister\Controller\Support;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;

/**
 * The record a nomination is recomputed for, with the schema it is read under.
 *
 * @spec openspec/changes/archiving-as-a-process-with-sign-off/specs/retention-management/spec.md
 */
final readonly class ArchivalNominationSubject {
	/**
	 * Bundle a record with its id and its schema.
	 *
	 * @param string       $id     The record uuid, as the route named it.
	 * @param ObjectEntity $object The record.
	 * @param Schema       $schema Its schema.
	 *
	 * @spec openspec/changes/archiving-as-a-process-with-sign-off/specs/retention-management/spec.md
	 */
	public function __construct(
		public string $id,
		public ObjectEntity $object,
		public Schema $schema,
	) {
	}//end __construct()
}//end class
