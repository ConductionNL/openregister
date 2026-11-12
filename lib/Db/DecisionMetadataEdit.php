<?php

/**
 * DecisionMetadataEdit
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
 * An operator's edit of the decision metadata of an entity relation (ADR-011 Rule 2).
 *
 * @spec exclude ADR-011 parameter object of EntityRelationMapper::updateDecisionMetadata(), not a capability of its own
 */
final class DecisionMetadataEdit {

	/**
	 * Constructor
	 *
	 * @param EntityRelation       $relation   Pre-loaded relation row (callers handle find/404).
	 * @param array<string, mixed> $fields     Subset of the decision whitelist keys with new values.
	 * @param IUser|null           $actingUser Explicit acting user, or null to fall back to the session user.
	 */
	public function __construct(
		public readonly EntityRelation $relation,
		public readonly array $fields,
		public readonly ?IUser $actingUser = null,
	) {
	}//end __construct()
}//end class
