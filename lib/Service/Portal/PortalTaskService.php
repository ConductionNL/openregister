<?php

/**
 * The subject-scoped read and the completion path of the portal seam.
 *
 * READ: a portal subject's open external tasks, with their case context, and
 * nothing of any other subject's: the party reference is a WHERE predicate,
 * so the page and its total cannot disagree and no foreign row is fetched
 * to be filtered away.
 *
 * COMPLETE, in this order and no other: authorize (the acting subject against
 * the STORED party reference, denial audited), validate the upload
 * constraints, store every file as an OpenRegister file attachment on the
 * CASE object, then record the completion referencing the stored files. A
 * stranger is refused before a byte lands on a case that is not theirs; a
 * constraint violation is refused before anything is stored; and a completion
 * that records first and stores second could be interrupted into a completed
 * task whose evidence does not exist, which is why storing comes first
 * (design D-5).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Portal
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-portal-task/specs/flow-portal-task/spec.md#requirement-an-upload-completion-lands-as-a-file-attachment-on-the-case-object
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Portal;

use DateTime;
use OCA\OpenRegister\Db\ListQuery;
use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Exception\TaskCompleteViaAppException;
use OCA\OpenRegister\Exception\TaskValidationException;
use OCA\OpenRegister\Service\FileWriterInterface;
use OCA\OpenRegister\Service\Task\InboxListing;
use OCA\OpenRegister\Service\Task\TaskBlockerGuard;
use OCP\AppFramework\Db\DoesNotExistException;
use Psr\Log\LoggerInterface;

/**
 * Lists a subject's portal tasks and completes one with uploads.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) The seam joins the task
 * layer (service, mapper, inbox rows, temporal projection) to the file
 * service and the subject value; each import is one side of that join.
 *
 * @spec openspec/changes/flow-portal-task/specs/flow-portal-task/spec.md#requirement-an-upload-completion-lands-as-a-file-attachment-on-the-case-object
 */
class PortalTaskService implements PortalTaskServiceInterface {

	/**
	 * The tag every stored upload carries, so the case's file list can say
	 * which portal task delivered it.
	 *
	 * @var string
	 */
	public const FILE_TAG_PREFIX = 'portal-task:';

	/**
	 * The task metadata key naming the app action that completes the task:
	 * `{app, action}`. With it set, only that app completes the task here.
	 *
	 * @var string
	 */
	public const COMPLETE_VIA = 'completeVia';

	/**
	 * Constructor.
	 *
	 * @param PortalTaskSources $sources The task service, the task rows, the inbox and the temporal projection.
	 * @param FileWriterInterface $files Stores uploads onto the case object.
	 * @param LoggerInterface $logger Diagnostics.
	 */
	public function __construct(
		private readonly PortalTaskSources $sources,
		private readonly FileWriterInterface $files,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * One subject's open portal tasks with case context, and the total the
	 * same predicates count.
	 *
	 * @param PortalSubject $subject The acting subject.
	 * @param int $limit Page size (clamped to 1..500).
	 * @param int $offset Page offset.
	 *
	 * @return array{results: array<int, array<string, mixed>>, total: int, limit: int, offset: int} The page.
	 *
	 * @spec openspec/changes/flow-portal-task/specs/flow-portal-task/spec.md#requirement-delivery-rides-the-portal-contribution-surface-and-nothing-else
	 */
	public function listForSubject(PortalSubject $subject, int $limit = 25, int $offset = 0): array {
		return $this->listPage(subject: $subject, query: new ListQuery(limit: max(1, min($limit, 500)), offset: max(0, $offset)));
	}//end listForSubject()

	/**
	 * One page of a subject's open portal tasks, and the total the same
	 * predicates count.
	 *
	 * @param PortalSubject $subject The acting subject.
	 * @param ListQuery     $query   The page, its limit and offset already clamped.
	 *
	 * @return array{results: array<int, array<string, mixed>>, total: int, limit: int, offset: int} The page.
	 */
	private function listPage(PortalSubject $subject, ListQuery $query): array {
		$party = $subject->partyReference();
		$offset = (int)$query->offset;

		$page = $this->sources->mapper->findOpenExternalForParty(partyReference: $party, page: $query);
		$total = $this->sources->mapper->countOpenExternalForParty(partyReference: $party);

		return [
			'results' => $this->rowsFor(page: $page, party: $party),
			'total' => $total,
			'limit' => $query->limit,
			'offset' => $offset,
		];
	}//end listPage()

	/**
	 * The rows of one page, with their case context, keeping only the party's own tasks.
	 *
	 * @param array<int, Task> $page  The tasks the query returned.
	 * @param string           $party The subject's party reference.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 */
	private function rowsFor(array $page, string $party): array {
		$contexts = $this->sources->inbox->subjectContextsFor(tasks: $page);
		$now = $this->sources->temporal->now();

		$results = [];
		foreach ($page as $task) {
			// Belt and braces over the WHERE clause: a row that is not this
			// party's never leaves the service, whatever the query returned.
			if (hash_equals($party, (string)$task->getAssignee()) === false) {
				continue;
			}

			// The page was read with the open-blocker exclusion, so no row of it is
			// blocked: saying so up front spares every row its own blocker query.
			$results[] = $this->sources->inbox->row(task: $task, listing: new InboxListing(subjects: $contexts, now: $now, openBlockers: []));
		}

		return $results;
	}//end rowsFor()

	/**
	 * One portal task, if it is this subject's.
	 *
	 * @param PortalSubject $subject The acting subject.
	 * @param string $uuid The task uuid.
	 *
	 * @return Task The task.
	 *
	 * @throws DoesNotExistException When absent, not external, not this
	 *                               subject's, or held back by an open blocker
	 *                               (the list leaves it out too): all read
	 *                               identically, so a stranger learns nothing
	 *                               from the answer.
	 *
	 * @spec openspec/changes/flow-portal-task/specs/flow-portal-task/spec.md#requirement-delivery-rides-the-portal-contribution-surface-and-nothing-else
	 */
	public function show(PortalSubject $subject, string $uuid): Task {
		$task = $this->sources->tasks->get(uuid: $uuid);
		if ((string)$task->getPerformerType() !== Task::PERFORMER_EXTERNAL
			|| hash_equals($subject->partyReference(), (string)$task->getAssignee()) === false
			|| (new TaskBlockerGuard(tasks: $this->sources->mapper))->isHeldBack(task: $task) === true
		) {
			throw new DoesNotExistException('No such portal task.');
		}

		return $task;
	}//end show()


	/**
	 * The row shape of one task for the portal, with delivery state and
	 * case context.
	 *
	 * @param Task $task The task.
	 *
	 * @return array<string, mixed> The row.
	 *
	 * @spec openspec/changes/flow-portal-task/specs/flow-portal-task/spec.md#requirement-delivery-rides-the-portal-contribution-surface-and-nothing-else
	 */
	public function row(Task $task): array {
		return $this->sources->inbox->enrich(task: $task);
	}//end row()

	/**
	 * Complete a portal task as the acting subject, storing its uploads on
	 * the case object first.
	 *
	 * @param PortalSubject $subject The acting subject.
	 * @param string $uuid The task uuid.
	 * @param PortalCompletion $completion What the party submitted: answers, comment, uploads and outcome.
	 * @param string $viaApp The app completing the task in-process, when it
	 *                       is the app the task's `completeVia` names; empty
	 *                       for the portal's own direct completion.
	 *
	 * @return Task The completed task.
	 *
	 * @throws \OCA\OpenRegister\Exception\TaskAccessDeniedException When the
	 *         subject is not the matched party (audited before anything is stored).
	 * @throws \OCA\OpenRegister\Exception\TaskConflictException When the task is terminal.
	 * @throws TaskCompleteViaAppException When the task names an app action that
	 *         completes it and the caller is not that app (nothing stored).
	 * @throws TaskValidationException When an upload constraint is violated (nothing stored).
	 * @throws DoesNotExistException When no such task exists, or it waits on an open blocker.
	 *
	 * @spec openspec/changes/flow-portal-task/specs/flow-portal-task/spec.md#requirement-an-upload-completion-lands-as-a-file-attachment-on-the-case-object
	 */
	public function complete(
		PortalSubject $subject,
		string $uuid,
		PortalCompletion $completion=new PortalCompletion(),
		string $viaApp = '',
	): Task {
		return $this->completeTask(command: new TaskCompletionCommand(subject: $subject, uuid: $uuid, completion: $completion, viaApp: $viaApp));
	}//end complete()

	/**
	 * Complete a portal task as the command's subject: authorize, refuse a
	 * held-back task or the wrong app, validate, store the uploads on the
	 * case object, then record the completion. In this order and no other.
	 *
	 * @param TaskCompletionCommand $command Who completes which task, with what, through which app.
	 *
	 * @return Task The completed task.
	 *
	 * @throws TaskCompleteViaAppException When the task names another app.
	 * @throws TaskValidationException When an upload constraint is violated (nothing stored).
	 */
	private function completeTask(TaskCompletionCommand $command): Task {
		// 1. Authorize, and audit a denial, BEFORE any other step. A stranger
		//    who knows the uuid must not get a validation message, let alone a
		//    file on somebody else's case.
		$task = $this->sources->tasks->openFor(verb: 'complete', uuid: $command->uuid, actor: $command->subject->actor());

		$this->assertCompletableBy(task: $task, viaApp: $command->viaApp);

		// 2. Validate against the constraints the node froze on the task.
		//    Refused means nothing was stored and the task is still open.
		$this->assertUploadConstraints(task: $task, files: $command->completion->files);

		// 3. Store every file on the CASE object. Order matters (design D-5):
		//    an orphaned file on the right case is recoverable and visible; a
		//    completed task whose evidence does not exist is neither.
		$evidence = $this->storeFiles(task: $task, files: $command->completion->files);

		// 4. Record the completion, referencing the stored files.
		return $this->sources->tasks->complete(
			uuid: $command->uuid,
			outcome: $command->completion->outcome(),
			resultText: null,
			comment: $command->completion->comment,
			actor: $command->subject->actor(),
			responses: $command->completion->answers,
			evidence: $evidence
		);
	}//end completeTask()

	/**
	 * Refuse a task the matched party may not complete here: one held back
	 * behind an open blocker, or one that names another app to complete it.
	 * Asked after the authorization and before anything is stored.
	 *
	 * @param Task   $task   The task, already authorized for the subject.
	 * @param string $viaApp The app completing the task in-process; empty for the portal itself.
	 *
	 * @return void
	 *
	 * @throws DoesNotExistException When the task waits on an open blocker.
	 * @throws TaskCompleteViaAppException When the task names another app.
	 */
	private function assertCompletableBy(Task $task, string $viaApp): void {
		// 1a. A task the case holds back behind an open blocker is not on the
		//     party's list, so it is not theirs to complete either. Asked after
		//     the authorization, so only the matched party can tell it from a
		//     task that does not exist, and before anything is stored.
		(new TaskBlockerGuard(tasks: $this->sources->mapper))->assertNotHeldBack(task: $task);

		// 1b. A task that names the app action completing it is completed by
		//     that app alone, so the app's own checks cannot be skipped by
		//     calling this seam directly. Only the matched party gets here, so
		//     the refusal tells nobody else that the task exists.
		$via = $this->completeVia(task: $task);
		if ($via !== null && $via['app'] !== $viaApp) {
			throw new TaskCompleteViaAppException(app: $via['app'], action: $via['action']);
		}
	}//end assertCompletableBy()

	/**
	 * The app action a task names as the one that completes it, or null when
	 * it names none or names it incompletely (an app without an action, or
	 * the reverse, is ignored rather than locking the task).
	 *
	 * @param Task $task The task.
	 *
	 * @return array{app: string, action: string}|null The app and its action.
	 *
	 * @spec openspec/changes/a-portal-task-names-the-app-that-completes-it/specs/flow-portal-task/spec.md#requirement-a-task-may-name-the-app-action-that-completes-it
	 */
	public function completeVia(Task $task): ?array {
		// A scalar casts to a list without `app` or `action`, so it reads as none.
		$via = (array)(($task->getMetadata() ?? [])[self::COMPLETE_VIA] ?? []);
		$route = ['app' => trim((string)($via['app'] ?? '')), 'action' => trim((string)($via['action'] ?? ''))];
		if (in_array('', $route, true) === true) {
			return null;
		}

		return $route;
	}//end completeVia()

	/**
	 * Refuse a completion that violates the task's upload constraints,
	 * naming the constraint.
	 *
	 * @param Task $task The task, carrying `metadata.upload`.
	 * @param array<int, array<string, mixed>> $files The uploads.
	 *
	 * @return void
	 *
	 * @throws TaskValidationException On the first violated constraint.
	 *
	 * @spec openspec/changes/flow-portal-task/specs/flow-portal-task/spec.md#requirement-an-upload-completion-lands-as-a-file-attachment-on-the-case-object
	 */
	public function assertUploadConstraints(Task $task, array $files): void {
		$constraints = (array)(($task->getMetadata() ?? [])['upload'] ?? []);
		$required = (($constraints['required'] ?? false) === true);
		$maxFiles = (int)($constraints['maxFiles'] ?? 1);
		$maxBytes = ($constraints['maxSizeBytes'] ?? null);
		$accepted = array_values(array_filter(array_map('strval', (array)($constraints['acceptedTypes'] ?? []))));

		if ($required === true && $files === []) {
			throw new TaskValidationException(message: 'This task requires at least one file (uploadRequired).');
		}

		if ($maxFiles > 0 && count($files) > $maxFiles) {
			throw new TaskValidationException(
				message: sprintf('At most %d file(s) are accepted (uploadMaxFiles); %d were sent.', $maxFiles, count($files))
			);
		}

		if ($files !== [] && trim((string)$task->getObjectUuid()) === '') {
			throw new TaskValidationException(message: 'This task is anchored to no case object, so a file has nowhere to land.');
		}

		$maxBytesLimit = 0;
		if (is_numeric($maxBytes) === true) {
			$maxBytesLimit = (int)$maxBytes;
		}

		foreach ($files as $file) {
			$this->assertFileAllowed(file: $file, maxBytes: $maxBytesLimit);
			$this->assertTypeAccepted(file: $file, accepted: $accepted);
		}
	}//end assertUploadConstraints()

	/**
	 * Refuse ONE file that breaks a per-file constraint, naming it.
	 *
	 * The name and the size; the type is assertTypeAccepted()'s, asked next.
	 *
	 * @param array<string, mixed> $file     The upload.
	 * @param int                  $maxBytes The size limit; 0 for none.
	 *
	 * @return void
	 *
	 * @throws TaskValidationException On the violated constraint.
	 */
	private function assertFileAllowed(array $file, int $maxBytes): void {
		$name = trim((string)($file['name'] ?? ''));
		if ($name === '') {
			throw new TaskValidationException(message: 'Every uploaded file needs a name.');
		}

		$size = (int)($file['size'] ?? 0);
		if ($maxBytes > 0 && $size > $maxBytes) {
			throw new TaskValidationException(
				message: sprintf("File '%s' is %d bytes, larger than the %d byte limit (uploadMaxSizeMb).", $name, $size, $maxBytes)
			);
		}
	}//end assertFileAllowed()

	/**
	 * Refuse ONE file whose type the accepted-type list does not admit, naming it.
	 *
	 * @param array<string, mixed> $file     The upload, its name already checked.
	 * @param array<int, string>   $accepted The accepted types; empty for any.
	 *
	 * @return void
	 *
	 * @throws TaskValidationException When the type is not accepted.
	 */
	private function assertTypeAccepted(array $file, array $accepted): void {
		if ($accepted === [] || $this->typeAccepted(file: $file, accepted: $accepted) === true) {
			return;
		}

		$name = trim((string)($file['name'] ?? ''));
		$type = trim((string)($file['type'] ?? ''));
		throw new TaskValidationException(
			message: sprintf("File '%s' has type '%s', which is not one of %s (uploadAcceptedTypes).", $name, $type, implode(', ', $accepted))
		);
	}//end assertTypeAccepted()

	/**
	 * Whether a file matches the accepted-type list: an exact media type, a
	 * `type/*` wildcard, or an extension (`pdf` or `.pdf`).
	 *
	 * @param array<string, mixed> $file     The upload: its name and declared media type.
	 * @param array<int, string>   $accepted The accepted entries.
	 *
	 * @return bool True when at least one entry admits the file.
	 */
	private function typeAccepted(array $file, array $accepted): bool {
		$extension = strtolower((string)pathinfo(trim((string)($file['name'] ?? '')), PATHINFO_EXTENSION));
		$type = strtolower(trim((string)($file['type'] ?? '')));
		foreach ($accepted as $entry) {
			$entry = strtolower(trim($entry));
			if ($entry === '') {
				continue;
			}

			if (str_contains($entry, '/') === true) {
				if ($this->mediaTypeMatches(entry: $entry, type: $type) === true) {
					return true;
				}

				continue;
			}

			if (ltrim($entry, '.') === $extension && $extension !== '') {
				return true;
			}
		}

		return false;
	}//end typeAccepted()

	/**
	 * Whether an accepted media-type entry admits a declared type: exactly,
	 * or as a `type/*` wildcard.
	 *
	 * @param string $entry The accepted entry, lower-cased.
	 * @param string $type The declared media type, lower-cased.
	 *
	 * @return bool True when the entry admits the type.
	 */
	private function mediaTypeMatches(string $entry, string $type): bool {
		if ($entry === $type) {
			return true;
		}

		return str_ends_with($entry, '/*') === true && $type !== '' && str_starts_with($type, substr($entry, 0, -1)) === true;
	}//end mediaTypeMatches()

	/**
	 * Store the uploads on the case object; return what the completion references.
	 *
	 * @param Task $task The task, anchored to the case.
	 * @param array<int, array<string, mixed>> $files The uploads.
	 *
	 * @return array<int, array<string, mixed>> One reference per stored file.
	 *
	 * @spec openspec/changes/flow-portal-task/specs/flow-portal-task/spec.md#requirement-an-upload-completion-lands-as-a-file-attachment-on-the-case-object
	 */
	private function storeFiles(Task $task, array $files): array {
		$references = [];
		foreach ($files as $file) {
			$name = (string)$file['name'];
			$content = $this->contentOf(file: $file);
			$stored = $this->files->addFile(
				objectEntity: (string)$task->getObjectUuid(),
				fileName: $name,
				content: $content,
				share: false,
				tags: [self::FILE_TAG_PREFIX . (string)$task->getUuid()],
				registerId: $task->getRegisterId()
			);

			if (is_resource($content) === true) {
				fclose($content);
			}

			$references[] = [
				'fileId' => $stored->getId(),
				'name' => $stored->getName(),
				'path' => $stored->getPath(),
				'size' => $stored->getSize(),
				'mimeType' => $stored->getMimeType(),
				'storedAt' => (new DateTime())->format('c'),
				'taskUuid' => $task->getUuid(),
			];

			$this->logger->info(
				'[PortalTaskService] Stored portal upload ' . $name . ' on case ' . $task->getObjectUuid() . ' for task ' . $task->getUuid(),
				['fileId' => $stored->getId()]
			);
		}//end foreach

		return $references;
	}//end storeFiles()

	/**
	 * The bytes of an upload: a stream over its temporary path, or the
	 * inline content a caller handed in.
	 *
	 * @param array<string, mixed> $file The upload.
	 *
	 * @return resource|string The content.
	 *
	 * @throws TaskValidationException When the upload carries no readable content.
	 */
	private function contentOf(array $file): mixed {
		$path = trim((string)($file['tmp_name'] ?? ''));
		if ($path !== '') {
			$stream = fopen($path, 'rb');
			if ($stream === false) {
				throw new TaskValidationException(message: sprintf("File '%s' could not be read.", (string)($file['name'] ?? '')));
			}

			return $stream;
		}

		if (isset($file['content']) === true && is_string($file['content']) === true) {
			return $file['content'];
		}

		throw new TaskValidationException(message: sprintf("File '%s' carries no content.", (string)($file['name'] ?? '')));
	}//end contentOf()
}//end class
