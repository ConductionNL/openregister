<?php

/**
 * MoveObjectInterface: moving an object to another register and schema.
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
 * What callers outside lib/Service need of MoveObject: perform one move.
 *
 * Bound to MoveObject in ContractBindings, so it resolves to the same shared instance.
 */
interface MoveObjectInterface {

	/**
	 * Move one object, keeping its uuid and everything keyed on it.
	 *
	 * @param ObjectEntity $object         The object.
	 * @param Register     $sourceRegister Where it is.
	 * @param Schema       $sourceSchema   Where it is.
	 * @param Register     $targetRegister Where it is going.
	 * @param Schema       $targetSchema   Where it is going.
	 * @param string       $actor          Who asked.
	 *
	 * @return array{moved: bool, uuid: string, from: array<string, mixed>, to: array<string, mixed>, errors: array<int, string>}
	 */
	public function move(
		ObjectEntity $object,
		Register $sourceRegister,
		Schema $sourceSchema,
		Register $targetRegister,
		Schema $targetSchema,
		string $actor,
	): array;
}//end interface
