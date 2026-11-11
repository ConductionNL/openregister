<?php

/**
 * Resolves the task a calendar write names, refusing visibly when it does not exist.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Task\WriteBack
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-ticking-off-the-vtodo-completes-the-engine-task-through-authorization
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Task\WriteBack;

use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Db\TaskMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use Psr\Log\LoggerInterface;

/**
 * The task lookup of the write-back gate.
 *
 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-ticking-off-the-vtodo-completes-the-engine-task-through-authorization
 */
final class WriteBackTaskResolver {

	/**
	 * Why an unresolvable write is refused.
	 *
	 * @var string
	 */
	private const MISSING = 'The calendar entry names a task that does not exist.';

	/**
	 * Constructor.
	 *
	 * @param TaskMapper      $mapper Resolves the task the VTODO names.
	 * @param LoggerInterface $logger Where the refusal is recorded.
	 */
	public function __construct(
		private readonly TaskMapper $mapper,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Resolve the task a write names; log and rethrow when there is none.
	 *
	 * @param string      $taskUuid The task uuid.
	 * @param string|null $actor    The acting identity.
	 *
	 * @return Task The task.
	 *
	 * @throws DoesNotExistException When no such task exists.
	 *
	 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-ticking-off-the-vtodo-completes-the-engine-task-through-authorization
	 */
	public function resolve(string $taskUuid, ?string $actor): Task {
		try {
			return $this->mapper->findByUuid(uuid: $taskUuid);
		} catch (DoesNotExistException $missing) {
			$this->logger->warning(
				'[TaskVtodoWriteBackGate] Refused: ' . self::MISSING,
				['task' => $taskUuid, 'actor' => $actor]
			);
			throw $missing;
		}
	}//end resolve()
}//end class
