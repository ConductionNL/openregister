<?php

/**
 * TaskSequenceMapperInterface: what callers outside the Db component use of TaskSequenceMapper.
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

/**
 * The methods of TaskSequenceMapper that callers outside the Db component use.
 *
 * Callers type against this interface and resolve it from the container; the
 * registrar binds it to TaskSequenceMapper. Guarded by C04InterfaceBindingsTest.
 */
interface TaskSequenceMapperInterface {

	/**
	 * Find a sequence by its public uuid.
	 *
	 * @param string $uuid The sequence uuid.
	 *
	 * @return TaskSequence The sequence.
	 *
	 * @throws DoesNotExistException When no such sequence exists.
	 *
	 * @spec openspec/specs/flow-approval-consolidation/spec.md#requirement-an-approval-is-an-ordered-task-sequence-with-one-position-enabled-at-a-time
	 */
	public function findByUuid(string $uuid): TaskSequence;

	/**
	 * The most recent sequence for an anchor and template, or null.
	 *
	 * @param string $anchorObjectUuid The object the approval is about.
	 * @param string $templateId The compiled template id.
	 *
	 * @return TaskSequence|null The newest sequence, or null.
	 *
	 * @spec openspec/specs/approval-workflow/spec.md#req-007
	 */
	public function findNewestForAnchor(string $anchorObjectUuid, string $templateId): ?TaskSequence;

	/**
	 * Insert one row.
	 *
	 * @param Entity $entity The row to insert.
	 *
	 * @return TaskSequence The inserted row, with its id.
	 */
	public function insert(Entity $entity): Entity;
}//end interface
