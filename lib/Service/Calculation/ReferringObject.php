<?php

/**
 * OpenRegister ReferringObject
 *
 * The saving object whose declared references (`x-openregister-references`)
 * or aggregate-references (`x-openregister-aggregate-refs`) are being resolved.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Calculation
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Calculation;

use OCA\OpenRegister\Db\Register;

/**
 * The referring side of a reference resolution: the saving object's payload
 * (with `@self` injected), the register it was saved in (the default context
 * of a declaration that names no register of its own) and its organisation
 * (the tenant boundary a reference may not cross).
 *
 * @spec openspec/specs/computed-fields/spec.md
 */
final class ReferringObject {

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed>     $payload      Object data WITH `@self` already injected.
	 * @param Register|string|int|null $register     The saving object's register context.
	 * @param string|null              $organisation The saving object's organisation UUID.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/computed-fields/spec.md
	 */
	public function __construct(
		public readonly array $payload,
		public readonly mixed $register=null,
		public readonly ?string $organisation=null,
	) {
	}//end __construct()
}//end class
