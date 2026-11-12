<?php

/**
 * DecisionMetadataChange
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use OCP\IUser;

/**
 * One change to the decision metadata of an entity relation, as it is stored,
 * audited and announced (ADR-011 Rule 2). It never leaves EntityRelationMapper.
 *
 * @spec exclude ADR-011 parameter object of EntityRelationMapper::updateDecisionMetadata(), not a capability of its own
 */
final class DecisionMetadataChange {

	/**
	 * Constructor
	 *
	 * @param EntityRelation                                     $relation      The relation, with the new values applied.
	 * @param array<string, array{previous: mixed, new: mixed}> $changedFields Only the fields that changed.
	 * @param IUser|null                                         $actingUser    The acting user, or null for the system.
	 */
	public function __construct(
		public readonly EntityRelation $relation,
		public readonly array $changedFields,
		public readonly ?IUser $actingUser,
	) {
	}//end __construct()
}//end class
