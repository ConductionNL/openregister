<?php

/**
 * The `_extend` keys of a register listing, and the counts it may attach.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Serializer
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Serializer;

/**
 * What {@see RegisterSerializer::serializePage()} applies to every register of a page:
 * the `_extend` keys (`schemas`, `@self.stats`) and the per-register schema counts.
 *
 * @spec openspec/specs/register-service-extensions/spec.md
 */
final class RegisterListExtension {

	/**
	 * Constructor.
	 *
	 * @param array                                       $extend                  Extension keys to apply.
	 * @param array<int,array<int,array{total:int}>>|null $schemaStatsByRegisterId Pre-computed per-register stats
	 *                                                                             (registerId → schemaId → ['total' => int]).
	 */
	public function __construct(
		public readonly array $extend = [],
		public readonly ?array $schemaStatsByRegisterId = null,
	) {
	}//end __construct()
}//end class
