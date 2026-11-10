<?php

/**
 * MagicIdentifierFind
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Db
 * @package   OCA\OpenRegister
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/openregister
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db\MagicMapper;

/**
 * One lookup of a single object by identifier: the identifier, whether soft-deleted objects count and, for a scan across magic tables, the register it is narrowed to.
 *
 * @spec openspec/specs/magic-table-reads/spec.md
 */
final class MagicIdentifierFind {

	/**
	 * Constructor.
	 *
	 * @param string|int $identifier      Object identifier (ID, UUID, slug, or URI).
	 * @param bool       $includeDeleted  Whether to include soft-deleted objects.
	 * @param ?int       $registerIdScope Restrict a cross-table scan to one register's tables, or null for all.
	 *
	 * @spec openspec/specs/magic-table-reads/spec.md
	 */
	public function __construct(
		public readonly string|int $identifier,
		public readonly bool $includeDeleted=false,
		public readonly ?int $registerIdScope=null,
	) {
	}//end __construct()
}//end class
