<?php

/**
 * Writes the one `action.run` audit entry of a run, digesting only the storable inputs.
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
 * @spec openspec/changes/object-actions-declared-and-run/specs/object-actions/spec.md#requirement-every-run-is-audited-once-and-the-objects-action-history-can-be-read
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ObjectAction;

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * Records a run of an action as the acting user.
 *
 * @spec openspec/changes/object-actions-declared-and-run/specs/object-actions/spec.md#requirement-every-run-is-audited-once-and-the-objects-action-history-can-be-read
 */
class ObjectActionRunRecorder {

	/**
	 * Constructor.
	 *
	 * @param ObjectActionInputs $inputs The writeOnly split and the digest.
	 * @param ObjectActionAudit  $audit  Writes the entry.
	 * @param ObjectActionGate   $gate   The acting user.
	 */
	public function __construct(
		private readonly ObjectActionInputs $inputs,
		private readonly ObjectActionAudit $audit,
		private readonly ObjectActionGate $gate,
	) {
	}//end __construct()

	/**
	 * Write the `action.run` entry.
	 *
	 * @param ObjectEntity           $object     The subject.
	 * @param ObjectActionDefinition $definition The action.
	 * @param array<string, mixed>   $inputs     The inputs; writeOnly ones never reach the digest.
	 * @param ObjectActionRunOutcome $outcome    The run, its changes and its outcome.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/object-actions-declared-and-run/specs/object-actions/spec.md#requirement-every-run-is-audited-once-and-the-objects-action-history-can-be-read
	 */
	public function record(ObjectEntity $object, ObjectActionDefinition $definition, array $inputs, ObjectActionRunOutcome $outcome): void {
		[$storable] = $this->inputs->split(inputs: $inputs, writeOnly: $definition->writeOnlyInputs());

		$this->audit->record(
			object: $object,
			definition: $definition,
			outcome: $outcome,
			inputsDigest: $this->inputs->digest(storable: $storable),
			userId: $this->gate->userId()
		);
	}//end record()
}//end class
