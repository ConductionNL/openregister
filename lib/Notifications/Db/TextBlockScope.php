<?php

/**
 * TextBlockScope: which administered text blocks a reader may insert.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/timeline-entries-are-records/specs/object-interactions/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Notifications\Db;

/**
 * The register and schema being written on, and the reader's groups.
 *
 * A null register or schema does not narrow on that column; an empty group
 * list leaves only the blocks scoped to no group.
 *
 * @spec openspec/changes/timeline-entries-are-records/specs/object-interactions/spec.md
 */
final class TextBlockScope {
	/**
	 * Constructor.
	 *
	 * @param string|null       $register The register being written on, or null.
	 * @param string|null       $schema   The schema being written on, or null.
	 * @param array<int,string> $groups   The reader's groups.
	 */
	public function __construct(
		public readonly ?string $register = null,
		public readonly ?string $schema = null,
		public readonly array $groups = [],
	) {
	}//end __construct()
}//end class
