<?php

/**
 * Where and when a grant constraint is judged: the area and the moment.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/rbac-scopes/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rbac;

use DateTimeInterface;

/**
 * The area (register and schema) a question is asked in, and the moment.
 *
 * @spec openspec/specs/rbac-scopes/spec.md
 */
final class ConstraintPoint {
	/**
	 * Name the area and the moment.
	 *
	 * @param array<string, mixed> $area Keys `register` and `schema`, naming where the question is asked.
	 * @param DateTimeInterface    $now  The moment.
	 */
	public function __construct(
		public readonly array $area,
		public readonly DateTimeInterface $now,
	) {
	}//end __construct()
}//end class
