<?php

/**
 * Shared machinery for the bulk actions that write properties on an object.
 *
 * Both built-in actions end in the same place: a merge patch over one object,
 * made by the actor who created the job. What differs between them is what
 * they declare, and declaration is the point (D-6, D-7).
 *
 * @category BulkAction
 * @package  OCA\OpenRegister\BulkAction
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\BulkAction;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IUser;
use Psr\Log\LoggerInterface;

/**
 * A bulk action that writes a set of properties on each member.
 */
abstract class AbstractPropertyWriteAction implements BulkActionInterface {
	/**
	 * Constructor.
	 *
	 * @param ObjectService $objectService The object write path.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		protected readonly ObjectService $objectService,
		protected readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The properties this action would write on the given object.
	 *
	 * @param array<string, mixed> $parameters The job's parameters.
	 *
	 * @return array<string, mixed> The merge patch.
	 */
	abstract protected function patchFor(array $parameters): array;

	/**
	 * The reason given when every target property already holds its value.
	 *
	 * @return string The skip reason.
	 */
	abstract protected function alreadyThereReason(): string;

	/**
	 * Rehearse or make the write.
	 *
	 * @param ObjectEntity $object The object to act on.
	 * @param array<string, mixed> $parameters The job's parameters.
	 * @param bool $commit False to rehearse, true to write.
	 * @param IUser|null $actor The user the job runs as.
	 *
	 * @return BulkActionResult What happened, or what would happen.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) One executor for the
	 * rehearsal and the commit is the design property of D-1.
	 * @SuppressWarnings(PHPMD.StaticAccess) BulkActionResult's named
	 * constructors are its only constructor: the class is immutable and its
	 * private __construct exists so an outcome cannot be built without
	 * saying which of the four it is.
	 *
	 * @spec openspec/changes/bulk-action-jobs/specs/bulk-action-jobs/spec.md
	 */
	public function apply(ObjectEntity $object, array $parameters, bool $commit, ?IUser $actor = null): BulkActionResult {
		$patch = $this->patchFor(parameters: $parameters);
		$current = $object->getObject();

		if ($this->wouldChangeNothing(patch: $patch, current: $current) === true) {
			return BulkActionResult::skipped(reason: $this->alreadyThereReason());
		}

		if ($commit === false) {
			return BulkActionResult::applied();
		}

		try {
			$this->objectService->patchObject(
				objectId: (string)$object->getUuid(),
				data: $patch,
				register: $object->getRegister(),
				schema: $object->getSchema(),
				currentUser: $actor
			);
		} catch (\Throwable $exception) {
			$this->logger->warning(
				message: '[BulkAction] Write failed for object',
				context: [
					'action' => $this->getId(),
					'object' => $object->getUuid(),
					'error' => $exception->getMessage(),
				]
			);

			return BulkActionResult::failed(message: $exception->getMessage());
		}//end try

		return BulkActionResult::applied();
	}//end apply()

	/**
	 * Whether every property in the patch already holds its target value.
	 *
	 * @param array<string, mixed> $patch The merge patch.
	 * @param array<string, mixed> $current The object's current data.
	 *
	 * @return bool True when the write would change nothing.
	 */
	protected function wouldChangeNothing(array $patch, array $current): bool {
		foreach ($patch as $key => $value) {
			if (array_key_exists($key, $current) === false) {
				return false;
			}

			if ($current[$key] !== $value) {
				return false;
			}
		}

		return true;
	}//end wouldChangeNothing()
}//end class
