<?php

/**
 * FacetBlockStamp: the schema version and generation time a facet block carries.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db\Schema
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db\Schema;

/**
 * What a regenerated facet block is stamped with.
 *
 * @spec exclude schema plumbing moved out of the entity, not a capability of its own
 */
final class FacetBlockStamp {

	/**
	 * Constructor.
	 *
	 * @param string|null $version     The schema's version; null reads as 1.0.
	 * @param int         $generatedAt When the block is generated, as a Unix timestamp.
	 */
	public function __construct(
		public readonly ?string $version,
		public readonly int $generatedAt,
	) {
	}//end __construct()
}//end class
