<?php

/**
 * DecisionVisibilityInterface: what callers outside the Service component use of DecisionVisibility.
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

use OCA\OpenRegister\Db\EntityRelation;

/**
 * The methods of DecisionVisibility that callers outside the Service component use.
 *
 * Callers type against this interface and resolve it from the container; the
 * registrar binds it to DecisionVisibility. Guarded by F05InterfaceBindingsTest.
 */
interface DecisionVisibilityInterface {

	/**
	 * The relation as the caller may see it.
	 *
	 * @param EntityRelation            $relation   The relation.
	 * @param string                    $userId     The caller ('' for nobody signed in).
	 * @param array<string, mixed>|null $serialised The serialisation to mask; the relation's own when omitted.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/reviewer-owns-their-decisions/specs/entity-relation-grondslagen/spec.md#requirement-a-schema-can-hide-one-reviewers-decisions-from-another-req-rod-002
	 */
	public function present(EntityRelation $relation, string $userId, ?array $serialised=null): array;

	/**
	 * Every relation as the caller may see it.
	 *
	 * @param EntityRelation[] $relations The relations.
	 * @param string           $userId    The caller.
	 *
	 * @return list<array<string, mixed>>
	 *
	 * @spec openspec/changes/reviewer-owns-their-decisions/specs/entity-relation-grondslagen/spec.md#requirement-a-schema-can-hide-one-reviewers-decisions-from-another-req-rod-002
	 */
	public function presentAll(array $relations, string $userId): array;
}//end interface
