<?php

/**
 * CodedValueGuardInterface: the coded-value guard as the coded-value validation listener reaches it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Vocabulary
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

namespace OCA\OpenRegister\Service\Vocabulary;

use OCA\OpenRegister\Exception\CodedValueException;

/**
 * The coded-value guard, typed for callers outside the Service component.
 *
 * Its only outside caller, the coded-value validation listener, calls enforce(), so
 * that is the whole contract. Binding it in ContractBindings lets that caller resolve
 * the guard without naming the class.
 *
 * @spec openspec/specs/skos-concept-registers/spec.md
 */
interface CodedValueGuardInterface {
	/**
	 * Refuse the object's coded values that the vocabulary does not allow.
	 *
	 * @param CodedValueWrite $write The submitted object data, the schema it is written against and the instant to judge windows at (now when null).
	 *
	 * @return void
	 *
	 * @throws CodedValueException When a coded value is refused.
	 *
	 * @spec openspec/specs/skos-concept-registers/spec.md
	 */
	public function enforce(CodedValueWrite $write): void;
}//end interface
