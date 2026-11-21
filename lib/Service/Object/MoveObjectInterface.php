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


/**
 * What callers outside lib/Service need of MoveObject: perform one move.
 *
 * Bound to MoveObject in ContractBindings, so it resolves to the same shared instance.
 */
interface MoveObjectInterface {

	/**
	 * Perform a move, keeping the object's uuid and everything keyed on it.
	 *
	 * @param ObjectMove $move The object, where it is, where it is going and who asked.
	 *
	 * @return array{moved: bool, uuid: string, from: array<string, mixed>, to: array<string, mixed>, errors: array<int, string>}
	 */
	public function perform(ObjectMove $move): array;
}//end interface
