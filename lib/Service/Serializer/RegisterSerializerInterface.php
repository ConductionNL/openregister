<?php

/**
 * RegisterSerializerInterface: the part of RegisterSerializer that the registers controller uses.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Serializer
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/register-service-extensions/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Serializer;

use OCA\OpenRegister\Db\Register;

/**
 * The methods of RegisterSerializer that callers outside its component use.
 * Callers depend on this interface, not on the class.
 */
interface RegisterSerializerInterface {

	/**
	 * Serialize a collection of Register entities.
	 *
	 * @param Register[] $registers Registers to serialize.
	 * @param array $extend Extension keys to apply.
	 * @param array<int,array<int,array{total:int}>>|null $schemaStatsByRegisterId Pre-computed per-register stats
	 *                                                                             (registerId → schemaId →
	 *                                                                             ['total' => int]).
	 *
	 * @return array<int,array> Serialized payload for each register.
	 *
	 * @spec openspec/specs/register-service-extensions/spec.md
	 */
	public function serializeMany(
		array $registers,
		array $extend = [],
		?array $schemaStatsByRegisterId = null,
	): array;
}//end interface
