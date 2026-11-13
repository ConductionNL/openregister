<?php

/**
 * RelationDecisionServiceInterface: what callers outside the Service component use of RelationDecisionService.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Decision
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Decision;

use OCA\OpenRegister\Db\Decision;
use OCA\OpenRegister\Db\EntityRelation;
use OCA\OpenRegister\Exception\CustomValidationException;
use OCP\IUser;

/**
 * The methods of RelationDecisionService that callers outside the Service component use.
 *
 * Callers type against this interface and resolve it from the container; the
 * registrar binds it to RelationDecisionService. Guarded by F05InterfaceBindingsTest.
 */
interface RelationDecisionServiceInterface {

	/**
	 * Apply a reviewer's decision to a relation.
	 *
	 * The subject write check runs before this (the caller's job).
	 *
	 * @param EntityRelation       $relation The relation.
	 * @param array<string, mixed> $body     bases, skipAnonymization and decisionNote.
	 * @param IUser                $actor    The reviewer.
	 *
	 * @return array{relation: EntityRelation, decision: Decision|null} The relation and its current decision.
	 *
	 * @throws DecisionOwnedException When the decision belongs to someone else, its record is missing, or another reviewer decided first.
	 * @throws CustomValidationException When the body is malformed.
	 *
	 * @spec openspec/specs/entity-relation-grondslagen/spec.md
	 */
	public function decide(EntityRelation $relation, array $body, IUser $actor): array;
}//end interface
