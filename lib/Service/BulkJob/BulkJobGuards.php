<?php

/**
 * What a bulk job must satisfy before it may be created or committed.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\BulkJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/bulk-action-jobs/specs/bulk-action-jobs/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\BulkJob;

use InvalidArgumentException;
use OCA\OpenRegister\BulkAction\BulkActionInterface;
use OCA\OpenRegister\BulkAction\ReversibleBulkActionInterface;
use OCA\OpenRegister\Db\BulkJob;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Exception\BulkJobRefusedException;

/**
 * The four refusals a bulk job has to get past, in one place.
 *
 * 🔴 EVERY ONE OF THEM REFUSES AT THE MOMENT IT COSTS NOTHING. A job with no
 * scope hydrates nothing and looks like a working job over an unlucky
 * selection; a selection over the ceiling is measured before anything is
 * written; the undo budget is measured over the REHEARSED selection, because
 * half way through a commit the only choices left are an unbounded buffer or
 * a job that quietly stops recording how to go back.
 *
 * Together they are what makes a rehearsal meaningful, which is why they are
 * one class rather than four private methods on the service that also does
 * the writing.
 *
 * @spec openspec/changes/bulk-action-jobs/specs/bulk-action-jobs/spec.md
 */
class BulkJobGuards {

	/**
	 * Constructor.
	 *
	 * @param integer $undoCeiling How much undo data one job may store, in bytes.
	 *
	 * @spec openspec/changes/bulk-action-jobs/specs/bulk-action-jobs/spec.md
	 */
	public function __construct(
		private readonly int $undoCeiling,
	) {
	}//end __construct()

	/**
	 * Refuse a job that does not say which register and schema it acts on.
	 *
	 * The object search resolves its table from the register and the schema,
	 * and answers an EMPTY LIST rather than an error when it has neither. A
	 * job without a scope would therefore hydrate nothing, report every
	 * member as not visible, and look like a working job over an unlucky
	 * selection. Refusing it here is the difference between an error and a
	 * confident wrong answer.
	 *
	 * @param int|null $registerId The register.
	 * @param int|null $schemaId The schema.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When either is missing.
	 *
	 * @spec openspec/changes/bulk-action-jobs/specs/bulk-action-jobs/spec.md
	 */
	public function assertScope(?int $registerId, ?int $schemaId): void {
		if ($registerId !== null && $schemaId !== null) {
			return;
		}

		throw new InvalidArgumentException(
			'A bulk job needs both a register and a schema. The object search resolves its table from the two, '
				.'and without them it answers an empty selection rather than an error.'
		);
	}//end assertScope()

	/**
	 * Refuse a selection larger than the instance ceiling.
	 *
	 * @param int $count The selection size.
	 * @param int $ceiling The ceiling.
	 *
	 * @return void
	 *
	 * @throws BulkJobRefusedException When the selection is too large.
	 *
	 * @spec openspec/changes/bulk-action-jobs/specs/bulk-action-jobs/spec.md
	 */
	public function assertCeiling(int $count, int $ceiling): void {
		if ($count <= $ceiling) {
			return;
		}

		throw new BulkJobRefusedException(
			message: 'This instance allows at most '.$ceiling.' objects in one bulk job, and this selection holds '
				.$count.'. Narrow the selection, or ask an administrator to raise the ceiling.',
			reason: 'ceiling',
			details: ['ceiling' => $ceiling, 'count' => $count]
		);
	}//end assertCeiling()

	/**
	 * Refuse a job whose recorded prior values would outgrow the undo ceiling.
	 *
	 * Measured at CREATION, over the rehearsed selection, because that is the
	 * only moment at which refusing costs nobody anything. Half way through a
	 * commit the choice is between an unbounded buffer and a job that silently
	 * stops recording what it would take to go back, and the second is the
	 * failure this change exists to prevent.
	 *
	 * @param BulkActionInterface           $action     The action.
	 * @param array<string, ObjectEntity>   $objects    The hydrated selection.
	 * @param array<string, mixed>          $parameters The job's parameters.
	 *
	 * @return void
	 *
	 * @throws BulkJobRefusedException When the job would store too much.
	 *
	 * @spec openspec/changes/undo-a-bulk-action/specs/bulk-action-jobs/spec.md
	 *
	 * @spec openspec/changes/bulk-action-jobs/specs/bulk-action-jobs/spec.md
	 */
	public function assertUndoCeiling(BulkActionInterface $action, array $objects, array $parameters): void {
		if (($action instanceof ReversibleBulkActionInterface) === false) {
			return;
		}

		$ceiling = $this->undoCeiling;
		$bytes = 0;

		foreach ($objects as $object) {
			$plan = $action->reversalPlanFor(object: $object, parameters: $parameters);
			$encoded = json_encode($plan);

			if ($encoded === false) {
				continue;
			}

			$bytes += strlen($encoded);

			if ($bytes <= $ceiling) {
				continue;
			}

			throw new BulkJobRefusedException(
				message: 'This instance stores at most '.$ceiling.' bytes of undo data per bulk job, and this one '
					.'would store more. Narrow the selection, write fewer properties, or ask an administrator to '
					.'raise the ceiling.',
				reason: 'undo-ceiling',
				details: ['ceiling' => $ceiling, 'objects' => count($objects)]
			);
		}//end foreach
	}//end assertUndoCeiling()

	/**
	 * Refuse a commit with no reason where the action requires one.
	 *
	 * @param BulkActionInterface $action The action.
	 * @param BulkJob $job The job.
	 *
	 * @return void
	 *
	 * @throws BulkJobRefusedException When the reason is missing.
	 *
	 * @spec openspec/changes/bulk-action-jobs/specs/bulk-action-jobs/spec.md
	 */
	public function assertJustification(BulkActionInterface $action, BulkJob $job): void {
		if ($action->requiresJustification() === false) {
			return;
		}

		$justification = (string)($job->getJustification() ?? '');

		if (trim($justification) !== '') {
			return;
		}

		throw new BulkJobRefusedException(
			message: 'The action '.$action->getId().' cannot be committed without a written reason. '
				.'Nothing was modified.',
			reason: 'justification-required',
			details: ['action' => $action->getId()]
		);
	}//end assertJustification()

}//end class
