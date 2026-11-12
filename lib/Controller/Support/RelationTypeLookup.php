<?php

/**
 * OpenRegister relation type lookup
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller\Support;

/**
 * One relation type looked up for one object while the relations of that
 * object are gathered: what a lookup reads, and what its failure is logged as.
 *
 * @spec openspec/specs/object-interactions/spec.md
 */
final class RelationTypeLookup {
	/**
	 * Name the lookup.
	 *
	 * @param string $key        The relation type (notes, tasks, emails, …).
	 * @param string $objectUuid The object UUID being aggregated.
	 *
	 * @spec openspec/specs/object-interactions/spec.md
	 */
	public function __construct(
		public readonly string $key,
		public readonly string $objectUuid,
	) {
	}//end __construct()
}//end class
