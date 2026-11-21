<?php

/**
 * OpenRegister relation decision stores
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Decision
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

namespace OCA\OpenRegister\Service\Decision;

use OCA\OpenRegister\Files\Db\EntityRelationMapperInterface;
use OCP\IDBConnection;

/**
 * Where a relation decision is written: the relation, its decision record and
 * the one transaction around both.
 *
 * @spec openspec/specs/entity-relation-grondslagen/spec.md
 */
final class RelationDecisionStores {
	/**
	 * Bundle the stores.
	 *
	 * @param EntityRelationMapperInterface $relationMapper The relation store and its audited decision write.
	 * @param DecisionService               $decisions      Records decision objects.
	 * @param IDBConnection                 $db             One transaction for the write and its decision.
	 *
	 * @spec openspec/specs/entity-relation-grondslagen/spec.md
	 */
	public function __construct(
		public readonly EntityRelationMapperInterface $relationMapper,
		public readonly DecisionService $decisions,
		public readonly IDBConnection $db,
	) {
	}//end __construct()
}//end class
