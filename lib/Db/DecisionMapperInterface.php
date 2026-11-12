<?php

/**
 * DecisionMapperInterface: what callers outside the Db component use of DecisionMapper.
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
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\Entity;
use Symfony\Component\Uid\Uuid;

/**
 * The methods of DecisionMapper that callers outside the Db component use.
 *
 * Callers type against this interface and resolve it from the container; the
 * registrar binds it to DecisionMapper. Guarded by C04InterfaceBindingsTest.
 */
interface DecisionMapperInterface {

	/**
	 * One decision by its uuid.
	 *
	 * @param string $uuid The decision uuid.
	 *
	 * @return Decision The decision.
	 *
	 * @throws DoesNotExistException When no decision has that uuid.
	 *
	 * @spec openspec/changes/reviewer-owns-their-decisions/specs/decisions/spec.md#requirement-a-decision-is-its-own-object-req-dec-001
	 */
	public function findByUuid(string $uuid): Decision;

	/**
	 * Every decision taken on one subject, newest first.
	 *
	 * @param string $subjectType The subject type.
	 * @param string $subjectId   The subject id.
	 *
	 * @return Decision[] The decisions.
	 *
	 * @spec openspec/changes/reviewer-owns-their-decisions/specs/decisions/spec.md#requirement-a-decision-is-its-own-object-req-dec-001
	 */
	public function findForSubject(string $subjectType, string $subjectId): array;

	/**
	 * Store a new decision with its uuid and, when unset, the moment of deciding.
	 *
	 * @param Entity $entity The decision.
	 *
	 * @return Decision The stored decision.
	 *
	 * @spec openspec/changes/reviewer-owns-their-decisions/specs/decisions/spec.md#requirement-a-decision-is-its-own-object-req-dec-001
	 */
	public function insert(Entity $entity): Decision;
}//end interface
