<?php

/**
 * OpenRegister ReferenceQuery
 *
 * What one declared reference reads: the saving object's payload, the
 * target schema and register, and the saving object's tenant.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Calculation\Reference
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/computed-fields/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Calculation\Reference;

use OCA\OpenRegister\Db\Register;

/**
 * The read one declared reference makes.
 *
 * @spec openspec/specs/computed-fields/spec.md
 */
final class ReferenceQuery {

	/**
	 * Hold the read's inputs.
	 *
	 * @param array<string, mixed> $payload Object data with `@self` injected.
	 * @param string $schema Target schema reference.
	 * @param Register|string|int|null $register Register context of the read.
	 * @param string|null $organisation Saving object's organisation UUID (the tenant boundary).
	 *
	 * @return void
	 */
	public function __construct(
		public readonly array $payload,
		public readonly string $schema,
		public readonly mixed $register,
		public readonly ?string $organisation,
	) {
	}//end __construct()
}//end class
