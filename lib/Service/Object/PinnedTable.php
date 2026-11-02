<?php

/**
 * OpenRegister PinnedTable
 *
 * The register and schema a relation lookup was pinned to, and why loading them stopped.
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
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/linked-entity-types/spec.md
 */

namespace OCA\OpenRegister\Service\Object;

use Exception;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;

/**
 * The register and schema a relation lookup reads the object through.
 *
 * Either may be null: nothing was pinned, or loading stopped at the first failure,
 * which is kept so the caller can log it.
 *
 * @spec openspec/specs/linked-entity-types/spec.md
 */
final class PinnedTable {

	/**
	 * The pinned register, or null.
	 *
	 * @var Register|null
	 */
	public ?Register $register = null;

	/**
	 * The pinned schema, or null.
	 *
	 * @var Schema|null
	 */
	public ?Schema $schema = null;

	/**
	 * The exception that stopped loading, or null.
	 *
	 * @var Exception|null
	 */
	public ?Exception $failure = null;
}//end class
