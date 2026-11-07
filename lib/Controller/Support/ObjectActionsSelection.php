<?php

/**
 * OpenRegister object actions selection
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller\Support;

use OCA\OpenRegister\Db\BulkJob;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionBulkInterface;

/**
 * One request to run an action on a selection: the action, the objects, the inputs, and the route as the caller named it.
 *
 * @spec openspec/changes/object-actions-declared-and-run/specs/object-actions/spec.md#requirement-selections-run-per-object-and-large-selections-become-a-bulk-job
 */
final class ObjectActionsSelection {
	/**
	 * Constructor.
	 *
	 * @param string               $register The register as the caller named it.
	 * @param string               $schema   The schema as the caller named it.
	 * @param string               $action   The action key.
	 * @param list<string>         $ids      The selected objects, without repeats.
	 * @param array<string, mixed> $inputs   The inputs.
	 */
	public function __construct(
		public readonly string $register,
		public readonly string $schema,
		public readonly string $action,
		public readonly array $ids,
		public readonly array $inputs,
	) {
	}//end __construct()

	/**
	 * Hand this selection to a previewed bulk job.
	 *
	 * @param ObjectActionBulkInterface $bulk   The bulk job starter.
	 * @param Schema                    $schema The selection's schema.
	 *
	 * @return BulkJob The job.
	 *
	 * @throws \OCA\OpenRegister\Exception\BulkJobRefusedException When the job is refused.
	 * @throws \InvalidArgumentException When the inputs are not valid for the action.
	 */
	public function startOn(ObjectActionBulkInterface $bulk, Schema $schema): BulkJob {
		return $bulk->start(
			schema: $schema,
			register: $this->register,
			schemaRef: $this->schema,
			action: $this->action,
			ids: $this->ids,
			inputs: $this->inputs
		);
	}//end startOn()
}//end class
