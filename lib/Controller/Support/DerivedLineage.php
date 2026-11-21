<?php

/**
 * OpenRegister derived lineage
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

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * The lineage of a derived object: the object it came out of, the object itself and what it inherited.
 *
 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
 */
final class DerivedLineage {
	/**
	 * Hold the lineage.
	 *
	 * @param ObjectEntity         $source    The object it came out of.
	 * @param ObjectEntity         $created   The new object.
	 * @param array<string, mixed> $inherited What the child took at creation; empty for a split.
	 *
	 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
	 */
	public function __construct(
		public readonly ObjectEntity $source,
		public readonly ObjectEntity $created,
		public readonly array $inherited,
	) {
	}//end __construct()
}//end class
