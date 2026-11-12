<?php

/**
 * OpenRegister RowRedaction
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
 * @spec openspec/specs/row-field-level-security/spec.md
 */

namespace OCA\OpenRegister\Service\Object;

use OCA\OpenRegister\Db\Schema;

/**
 * One RenderObject::redactWriteOnlyFromRows() pass, and what applies to the row it is on.
 *
 * @spec openspec/specs/row-field-level-security/spec.md
 */
final class RowRedaction {

	/**
	 * Whether the gated strips (authorization.read and decryption) apply: a user-facing read outside a system operation.
	 *
	 * @var boolean
	 */
	public bool $applyGatedStrips = true;

	/**
	 * The schema of the row being redacted.
	 *
	 * @var Schema|null
	 */
	public ?Schema $schema = null;

	/**
	 * Whether the schema has a writeOnly rule to strip.
	 *
	 * @var boolean
	 */
	public bool $doWriteOnly = false;

	/**
	 * Whether the gated read strip applies to this row.
	 *
	 * @var boolean
	 */
	public bool $doGated = false;
}//end class
