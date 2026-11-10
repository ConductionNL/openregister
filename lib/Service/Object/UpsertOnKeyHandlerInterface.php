<?php

/**
 * UpsertOnKeyHandlerInterface: create-or-update on a declared unique key.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object
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

namespace OCA\OpenRegister\Service\Object;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;

/**
 * What callers outside lib/Service need of UpsertOnKeyHandler: one upsert.
 *
 * Bound to UpsertOnKeyHandler in ContractBindings, so it resolves to the same shared instance.
 */
interface UpsertOnKeyHandlerInterface {

	/**
	 * Create the record, or update the one the declared key already names.
	 *
	 * @param string                          $constraintName The declared unique constraint.
	 * @param array<string, mixed>            $object         The request body.
	 * @param Register                        $register       The register.
	 * @param Schema                          $schema         The schema.
	 * @param callable(?string): ObjectEntity $save           Saves the body; null creates, a uuid updates that record.
	 *
	 * @return array{created: bool, object: ObjectEntity} Whether a record was created, and the saved record.
	 *
	 * @throws UpsertOnKeyException When the upsert is refused; the exception carries the answer.
	 */
	public function upsert(string $constraintName, array $object, Register $register, Schema $schema, callable $save): array;
}//end interface
