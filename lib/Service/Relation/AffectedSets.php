<?php

/**
 * AffectedSets: builds the affected-set derivation that callers outside this component used to construct with `new`.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Relation
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/relations-that-travel-and-what-they-expose/specs/referential-integrity/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Relation;

/**
 * Constructs exactly what the caller used to construct itself with `new`: a
 * fresh instance with no arguments, typed as its interface, so the caller names
 * only this class and the interface. Types only: no logic lives here.
 *
 * @spec openspec/changes/relations-that-travel-and-what-they-expose/specs/referential-integrity/spec.md
 */
final class AffectedSets {

	/**
	 * The affected-set derivation.
	 *
	 * @return AffectedSetInterface A fresh instance.
	 *
	 * @spec openspec/changes/relations-that-travel-and-what-they-expose/specs/referential-integrity/spec.md
	 */
	public static function deriver(): AffectedSetInterface {
		return new AffectedSet();
	}//end deriver()
}//end class
