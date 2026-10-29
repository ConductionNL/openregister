<?php

/**
 * A selection too large to run in one request becomes a bulk job.
 *
 * The job is created PREVIEWED: each member is rehearsed through the same
 * four checks a run makes, nothing is written, and the person sees how many
 * objects the action would apply to, skip or refuse before committing it at
 * `/api/bulk-jobs/{id}/commit`. Progress, retry and the audit row per member
 * are `bulk-action-jobs`' own.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ObjectAction
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/object-actions-declared-and-run/specs/object-actions/spec.md#requirement-selections-run-per-object-and-large-selections-become-a-bulk-job
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ObjectAction;

use OCA\OpenRegister\BulkAction\RunActionAction;
use OCA\OpenRegister\Db\BulkJob;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Exception\BulkJobRefusedException;
use OCA\OpenRegister\Kernel\Contracts\RegisterMapperInterface;
use OCA\OpenRegister\Service\BulkJob\BulkJobService;
use InvalidArgumentException;
use Throwable;

/**
 * Starts the bulk job for a large selection.
 *
 * @spec openspec/changes/object-actions-declared-and-run/specs/object-actions/spec.md#requirement-selections-run-per-object-and-large-selections-become-a-bulk-job
 */
class ObjectActionBulk {

	/**
	 * Constructor.
	 *
	 * @param BulkJobService   $jobs      Creates and previews the job.
	 * @param RegisterMapperInterface<Register, Schema>   $registers Resolves the register the caller named.
	 * @param ObjectActionGate $gate      The person starting it.
	 */
	public function __construct(
		private readonly BulkJobService $jobs,
		private readonly RegisterMapperInterface $registers,
		private readonly ObjectActionGate $gate,
	) {
	}//end __construct()

	/**
	 * Create the previewed job.
	 *
	 * @param Schema               $schema   The selection's schema.
	 * @param string               $register The register as the caller named it.
	 * @param string               $schemaRef The schema as the caller named it.
	 * @param string               $action   The action key.
	 * @param list<string>         $ids      The selection.
	 * @param array<string, mixed> $inputs   The inputs.
	 *
	 * @return BulkJob The previewed job.
	 *
	 * @throws BulkJobRefusedException When the ceiling or a guard refuses the selection.
	 * @throws InvalidArgumentException When nobody is signed in to run it as.
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList) One argument per part of the request being handed over.
	 *
	 * @spec openspec/changes/object-actions-declared-and-run/specs/object-actions/spec.md#requirement-selections-run-per-object-and-large-selections-become-a-bulk-job
	 */
	public function start(
		Schema $schema,
		string $register,
		string $schemaRef,
		string $action,
		array $ids,
		array $inputs,
	): BulkJob {
		$actorUid = $this->gate->userId();
		if ($actorUid === null) {
			throw new InvalidArgumentException('A bulk job runs as the person who starts it, so it needs a signed-in user.');
		}

		return $this->jobs->create(
			actionId: RunActionAction::ID,
			parameters: ['action' => $action, 'inputs' => $inputs, 'register' => $register, 'schema' => $schemaRef],
			selection: ['ids' => $ids],
			justification: null,
			actorUid: $actorUid,
			registerId: $this->registerId(register: $register),
			schemaId: $schema->getId()
		);
	}//end start()

	/**
	 * The register's id, or null when the name resolves to none.
	 *
	 * @param string $register The register's id or slug.
	 *
	 * @return int|null The id.
	 */
	private function registerId(string $register): ?int {
		try {
			return $this->registers->find($register, _multitenancy: false, _rbac: false)->getId();
		} catch (Throwable) {
			return null;
		}
	}//end registerId()
}//end class
