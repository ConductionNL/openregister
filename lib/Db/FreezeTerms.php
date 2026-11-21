<?php

/**
 * FreezeTerms
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

/**
 * Why an object is frozen and which lifecycle state declared it (ADR-011 Rule 2).
 *
 * @spec exclude ADR-011 parameter object of ObjectEntity::freezeWith(), not a capability of its own
 */
final class FreezeTerms {

	/**
	 * Constructor
	 *
	 * @param string|null $reason Why the object was frozen.
	 * @param string|null $state  The lifecycle state that declared the freeze, or null when a person did.
	 */
	public function __construct(
		public readonly ?string $reason = null,
		public readonly ?string $state = null,
	) {
	}//end __construct()
}//end class
