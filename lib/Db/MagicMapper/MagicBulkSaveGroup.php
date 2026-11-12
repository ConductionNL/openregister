<?php

/**
 * MagicBulkSaveGroup: the objects of one bulk save and the table they go to.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db\MagicMapper
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db\MagicMapper;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;

/**
 * The objects one bulk save writes, with the register and schema of their
 * table once known.
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
final class MagicBulkSaveGroup {

	/**
	 * The register of the table, or null until resolved.
	 *
	 * @var Register|null
	 */
	public ?Register $register = null;

	/**
	 * The schema of the table, or null until resolved.
	 *
	 * @var Schema|null
	 */
	public ?Schema $schema = null;

	/**
	 * A group of objects to save.
	 *
	 * @param array $insertObjects       The objects to insert or upsert.
	 * @param bool  $needsPreUpdateState Whether anything downstream reads the pre-update rows.
	 */
	public function __construct(
		public readonly array $insertObjects,
		public readonly bool $needsPreUpdateState = true,
	) {
	}//end __construct()
}//end class
