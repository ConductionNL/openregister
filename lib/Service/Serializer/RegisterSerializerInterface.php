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
	 * Serialize a collection of Register entities with one extension for the page.
	 *
	 * @param Register[]            $registers Registers to serialize.
	 * @param RegisterListExtension $extension The extension keys and the per-register schema counts.
	 *
	 * @return array<int,array> Serialized payload for each register.
	 *
	 * @spec openspec/specs/register-service-extensions/spec.md
	 */
	public function serializePage(array $registers, RegisterListExtension $extension=new RegisterListExtension()): array;
}//end interface
