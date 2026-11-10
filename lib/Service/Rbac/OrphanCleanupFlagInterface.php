<?php

/**
 * OrphanCleanupFlagInterface: whether a schema save changes the orphan-cleanup flag.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rbac;

use OCA\OpenRegister\Db\Schema;

/**
 * The methods of OrphanCleanupFlag that callers outside its component use.
 * Callers depend on this interface, not on the class.
 */
interface OrphanCleanupFlagInterface {

	/**
	 * Whether the document sets the flag to another value than the schema holds.
	 *
	 * A document that leaves the key out drops the flag or keeps it, which gives
	 * nobody more.
	 *
	 * @param Schema               $schema   The schema as stored, or a new one.
	 * @param array<string, mixed> $document The incoming schema.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/referential-integrity/spec.md
	 */
	public function changedBy(Schema $schema, array $document): bool;
}//end interface
