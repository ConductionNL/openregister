<?php

/**
 * RelationDecisionUpdate: a stored change to an entity relation's decision metadata.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts;

use OCP\AppFramework\Db\Entity;
use OCP\IUser;

/**
 * The relation as stored after a decision-metadata write, only the fields that
 * changed, and who decided.
 *
 * @spec openspec/specs/event-driven-architecture/spec.md#requirement-all-entity-mutations-must-dispatch-typed-php-events-via-ieventdispatcher
 */
final class RelationDecisionUpdate {

	/**
	 * Constructor
	 *
	 * @param Entity                                            $relation      The relation as stored now.
	 * @param array<string, array{previous: mixed, new: mixed}> $changedFields Only the fields that changed.
	 * @param IUser|null                                        $actingUser    The user who decided; null for a system write.
	 */
	public function __construct(
		public readonly Entity $relation,
		public readonly array $changedFields,
		public readonly ?IUser $actingUser,
	) {
	}//end __construct()
}//end class
