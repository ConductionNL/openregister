<?php

/**
 * LifecycleColumns
 *
 * The lifecycle metadata columns of one magic table under one alias.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Handler
 * @package   OCA\OpenRegister\Db\MagicMapper
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db\MagicMapper;

/**
 * The lifecycle metadata columns (`_archived`, `_status`) of one magic table,
 * as one read spells them: the table alias with its dot (or empty) and the
 * metadata columns the table could not be given, which the archive and draft
 * predicates leave out.
 *
 * @category  Handler
 * @package   OCA\OpenRegister\Db\MagicMapper
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://www.OpenRegister.app
 *
 * @spec openspec/specs/object-lifecycle/spec.md#requirement-a-table-that-cannot-be-healed-degrades-quietly-req-mth-002
 */
final class LifecycleColumns
{

	/**
	 * Constructor.
	 *
	 * @param string   $prefix  The table alias with its dot, or empty.
	 * @param string[] $missing Metadata columns this table lacks.
	 */
	public function __construct(
		public readonly string $prefix,
		public readonly array $missing = [],
	) {
	}//end __construct()

	/**
	 * Whether the table lacks a metadata column.
	 *
	 * @param string $column The metadata column, e.g. `_archived`.
	 *
	 * @return bool True when the table could not be given the column.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md#requirement-a-table-that-cannot-be-healed-degrades-quietly-req-mth-002
	 */
	public function lacks(string $column): bool {
		return in_array($column, $this->missing, true);
	}//end lacks()
}//end class
