<?php

/**
 * The form a task presents: declaration × live schema, derived on every read.
 *
 * Two paths and no third. A task carrying a run resolves its declaration
 * through the flow definition VERSION that run is pinned to, never the
 * editable head, so editing and publishing a flow changes the form of zero
 * already-open tasks. A run-less task carries its declaration on its own
 * record. Where a pinned version cannot be resolved the form fails visibly,
 * naming the flow and the version, and falls back to nothing: not the head,
 * not the latest published version and least of all an empty form, which is
 * the one fallback that reports success for a task that required evidence.
 *
 * Nothing here is cached on the task row. The rendered field list is the
 * declaration intersected with the LIVE schema, recomputed per call, so a
 * schema that dropped or locked a field after the step was saved shows that
 * field as broken with its reason instead of silently omitting it.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Task
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-task-forms/specs/flow-task-forms/spec.md#requirement-the-form-a-task-presents-is-the-one-its-flow-version-declared
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Task;

use DateTime;
use OCA\OpenRegister\Db\FlowMapper;
use OCA\OpenRegister\Db\FlowRun;
use OCA\OpenRegister\Db\FlowRunMapper;
use OCA\OpenRegister\Db\FormLinkMapper;
use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Service\Flow\FlowPublishedGraph;
use OCP\IL10N;
use Throwable;
use UnexpectedValueException;

/**
 * Resolves and describes the form a task presents, per read.
 *
 * @spec openspec/changes/flow-task-forms/specs/flow-task-forms/spec.md#requirement-the-form-a-task-presents-is-the-one-its-flow-version-declared
 */
class TaskFormResolver {

	/**
	 * Every declared field renders.
	 *
	 * @var string
	 */
	public const STATE_READY = 'ready';

	/**
	 * At least one declared field no longer renders against the live schema.
	 *
	 * @var string
	 */
	public const STATE_BROKEN = 'broken';

	/**
	 * The declaration itself could not be resolved.
	 *
	 * @var string
	 */
	public const STATE_UNRESOLVABLE = 'unresolvable';

	/**
	 * The bound external form is not usable.
	 *
	 * @var string
	 */
	public const STATE_UNAVAILABLE = 'unavailable';

	/**
	 * Constructor.
	 *
	 * @param FlowRunMapper $runs Reads the run for its flow and version pin.
	 * @param FlowMapper $flows Reads the live flow, for an unpinned draft test run only.
	 * @param FlowPublishedGraph $published Resolves the pinned graph of a run.
	 * @param FormLinkMapper $formLinks Reads the subject's bound Forms form.
	 * @param TaskFormReader $reader Normalises and inspects declarations.
	 * @param IL10N $l10n Translations, for reasons a performer reads.
	 */
	public function __construct(
		private readonly FlowRunMapper $runs,
		private readonly FlowMapper $flows,
		private readonly FlowPublishedGraph $published,
		private readonly FormLinkMapper $formLinks,
		private readonly TaskFormReader $reader,
		private readonly IL10N $l10n,
	) {

	}//end __construct()

	/**
	 * The task's completion surface, as the read exposes it.
	 *
	 * `form` is null when the step declares none, else a description carrying
	 * `kind`, `state` and, for the native kind, each declared field with its
	 * `required` flag from the DECLARATION, its `order` from the declaration
	 * position, and whether it is `renderable` against the live schema. An
	 * unresolvable declaration is a form with `state` unresolvable and an
	 * `error` naming the flow and the version: never an empty field list.
	 *
	 * @param Task $task The task.
	 *
	 * @return array{form: array<string, mixed>|null, requireChecklist: bool} The description.
	 *
	 * @spec openspec/changes/flow-task-forms/specs/flow-task-forms/spec.md#requirement-the-rendered-form-carries-the-declarations-required-flags-and-order
	 */
	public function describe(Task $task): array {
		try {
			$declaration = $this->declarationOf(task: $task);
		} catch (UnexpectedValueException $unresolvable) {
			return [
				'form' => [
					'kind' => null,
					'state' => self::STATE_UNRESOLVABLE,
					'error' => $unresolvable->getMessage(),
				],
				'requireChecklist' => false,
			];
		}

		$form = null;
		if ($declaration->isNative() === true) {
			$form = $this->describeNative(declaration: $declaration);
		} else if ($declaration->isExternal() === true) {
			$form = $this->describeExternal(declaration: $declaration, task: $task);
		}

		return [
			'form' => $form,
			'requireChecklist' => $declaration->requireChecklist,
		];
	}//end describe()

	/**
	 * The declaration a task resolves to: pinned version, or its own record.
	 *
	 * @param Task $task The task.
	 *
	 * @return TaskForm The declaration.
	 *
	 * @throws UnexpectedValueException When the run, its version or its step cannot be resolved.
	 *
	 * @spec openspec/changes/flow-task-forms/specs/flow-task-forms/spec.md#requirement-the-form-a-task-presents-is-the-one-its-flow-version-declared
	 */
	public function declarationOf(Task $task): TaskForm {
		$runUuid = trim((string)$task->getRunUuid());
		if ($runUuid === '') {
			// A run-less task is first-class: its record is its declaration.
			return $this->reader->fromRecord(record: (array)(($task->getMetadata() ?? [])['form'] ?? []));
		}

		try {
			$run = $this->runs->findByUuid(uuid: $runUuid);
		} catch (Throwable) {
			throw new UnexpectedValueException(
				$this->l10n->t('Task %1$s belongs to run %2$s, which no longer exists.', [(string)$task->getUuid(), $runUuid])
			);
		}

		$nodeId = trim((string)$task->getNodeId());
		foreach ($this->nodesOf(run: $run) as $node) {
			if (is_array($node) === true && trim((string)($node['id'] ?? '')) === $nodeId) {
				return $this->reader->fromConfig(config: (array)($node['config'] ?? []));
			}
		}

		throw new UnexpectedValueException(
			$this->l10n->t(
				'Version %1$s of flow %2$s has no step "%3$s", so the form of task %4$s cannot be resolved.',
				[$this->versionLabel(run: $run), (string)$run->getFlowId(), $nodeId, (string)$task->getUuid()]
			)
		);
	}//end declarationOf()

	/**
	 * The nodes of the graph a run walks: its pinned version, or the live
	 * document for the one dispatch that may walk a draft, the interactive
	 * test run, which carries no pin because there is no published version
	 * to pin.
	 *
	 * @param FlowRun $run The run.
	 *
	 * @return array<int, mixed> The node entries.
	 *
	 * @throws UnexpectedValueException When the pinned version cannot be resolved.
	 */
	private function nodesOf(FlowRun $run): array {
		if ($run->getFlowVersion() === null) {
			try {
				return ($this->flows->findByUuid(uuid: (string)$run->getFlowId())->getNodes() ?? []);
			} catch (Throwable) {
				throw new UnexpectedValueException(
					$this->l10n->t('Flow %1$s no longer exists.', [(string)$run->getFlowId()])
				);
			}
		}

		$graph = $this->published->ofRun(run: $run);
		if ($graph === null) {
			throw new UnexpectedValueException(
				$this->l10n->t(
					'Version %1$s of flow %2$s cannot be resolved, so the form of this task cannot be shown.',
					[$this->versionLabel(run: $run), (string)$run->getFlowId()]
				)
			);
		}

		return (array)($graph['nodes'] ?? []);
	}//end nodesOf()

	/**
	 * The native form against the live schema: per field, render or broken-with-reason.
	 *
	 * @param TaskForm $declaration The declaration.
	 *
	 * @return array<string, mixed> The description.
	 *
	 * @spec openspec/changes/flow-task-forms/specs/flow-task-forms/spec.md#requirement-the-rendered-form-carries-the-declarations-required-flags-and-order
	 */
	private function describeNative(TaskForm $declaration): array {
		try {
			$schema = $this->reader->schema(reference: $declaration->schema);
			$declared = $this->reader->declaredFields(form: $declaration, schema: $schema);
		} catch (UnexpectedValueException $broken) {
			return [
				'kind' => TaskForm::KIND_FIELDS,
				'state' => self::STATE_BROKEN,
				'error' => $broken->getMessage(),
				'schema' => null,
				'action' => $declaration->action,
				'fields' => [],
			];
		}

		$fields = [];
		$state = self::STATE_READY;
		foreach ($declared as $order => $field) {
			$reason = $this->reader->unrenderableReason(schema: $schema, field: $field['field']);
			if ($reason !== null) {
				$state = self::STATE_BROKEN;
			}

			$fields[] = [
				'field' => $field['field'],
				'required' => $field['required'],
				'order' => $order,
				'renderable' => ($reason === null),
				'reason' => $reason,
			];
		}

		return [
			'kind' => TaskForm::KIND_FIELDS,
			'state' => $state,
			'error' => null,
			'schema' => [
				'id' => $schema->getId(),
				'uuid' => $schema->getUuid(),
				'slug' => $schema->getSlug(),
				'title' => $schema->getTitle(),
			],
			'action' => $declaration->action,
			'fields' => $fields,
		];
	}//end describeNative()

	/**
	 * The external form through the subject's link, saying so when it is unusable.
	 *
	 * Read from the link's cached snapshot (title, status, expiry), which the
	 * link service keeps precisely so a surface can still say what happened
	 * when the Forms app is gone or the form was deleted.
	 *
	 * @param TaskForm $declaration The declaration.
	 * @param Task $task The task, for its subject anchor.
	 *
	 * @return array<string, mixed> The description.
	 *
	 * @spec openspec/changes/flow-task-forms/specs/flow-task-forms/spec.md#requirement-the-external-form-path-binds-an-existing-forms-form-and-validates-nothing-about-its-contents
	 */
	private function describeExternal(TaskForm $declaration, Task $task): array {
		$description = [
			'kind' => TaskForm::KIND_EXTERNAL,
			'state' => self::STATE_UNAVAILABLE,
			'error' => null,
			'formId' => $declaration->formId,
			'formHash' => null,
			'title' => null,
			'status' => null,
			'expiresAt' => null,
		];

		$objectUuid = trim((string)$task->getObjectUuid());
		$link = null;
		if ($objectUuid !== '' && $declaration->formId !== null) {
			$link = $this->formLinks->findFormLink(objectUuid: $objectUuid, formId: $declaration->formId);
		}

		if ($link === null) {
			$description['error'] = $this->l10n->t('The form is no longer linked to this task\'s subject, so it cannot be used to finish the work.');

			return $description;
		}

		$status = (string)($link->getStatus() ?? 'open');
		$expiresAt = $link->getExpiresAt();
		$description['formHash'] = $link->getFormHash();
		$description['title'] = $link->getTitle();
		$description['status'] = $status;
		$description['expiresAt'] = $expiresAt?->format('c');

		if (in_array($status, ['archived', 'closed', 'draft'], true) === true) {
			$description['error'] = $this->l10n->t('The form is %1$s and cannot be filled in.', [$status]);

			return $description;
		}

		if ($expiresAt !== null && $expiresAt < new DateTime()) {
			$description['error'] = $this->l10n->t('The form expired on %1$s and cannot be filled in.', [$expiresAt->format('Y-m-d')]);

			return $description;
		}

		$description['state'] = self::STATE_READY;

		return $description;
	}//end describeExternal()

	/**
	 * A run's version for a message: its number, or "draft" for an unpinned test run.
	 *
	 * @param FlowRun $run The run.
	 *
	 * @return string The label.
	 */
	private function versionLabel(FlowRun $run): string {
		$version = $run->getFlowVersion();
		if ($version === null) {
			return 'draft';
		}

		return (string)$version;
	}//end versionLabel()
}//end class
