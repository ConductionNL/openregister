<?php

/**
 * OpenRegister ManualEntityResult.
 *
 * Immutable DTO carrying the result of a `ManualEntityService::
 * addManualEntity` call. The controller layer maps this into the
 * JSON response body documented in the
 * `manual-entity-anonymisation` proposal.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\File
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/entity-relation-grondslagen/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\File;

use OCA\OpenRegister\Db\EntityRelation;
use OCA\OpenRegister\Db\GdprEntity;

/**
 * Result payload from `ManualEntityService::addManualEntity`.
 */
final class ManualEntityResult {
	/**
	 * Constructor.
	 *
	 * @param GdprEntity $entity The catalogue entry (newly inserted or reused).
	 * @param bool $entityWasNew True when this call inserted the catalogue row;
	 *                           false when an existing one was reused.
	 * @param EntityRelation[] $relations Relation rows inserted by this call. Excludes rows
	 *                                    that were skipped because they already existed.
	 * @param int $matchCount Total positions found in the file's chunks.
	 * @param int $matchesSkipped How many of those positions were skipped because
	 *                            a relation row already covered them.
	 */
	public function __construct(
		public readonly GdprEntity $entity,
		public readonly bool $entityWasNew,
		public readonly array $relations,
		public readonly int $matchCount,
		public readonly int $matchesSkipped,
	) {

	}//end __construct()
}//end class
