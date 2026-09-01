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
use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Db\TaskMapper;
use OCA\OpenRegister\Exception\TaskValidationException;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\Task\TaskInboxService;
use OCA\OpenRegister\Service\Task\TaskService;
use OCA\OpenRegister\Service\Task\TaskTemporalProjection;
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
class PortalTaskService {

	/**
	 * The outcome a portal completion records when the party names none.
	 *
	 * @var string
	 */
	public const DEFAULT_OUTCOME = 'submitted';

	/**
	 * The tag every stored upload carries, so the case's file list can say
	 * which portal task delivered it.
	 *
	 * @var string
	 */
	public const FILE_TAG_PREFIX = 'portal-task:';

	/**
	 * Constructor.
	 *
	 * @param TaskService $tasks The authorized lifecycle.
	 * @param TaskMapper $mapper The subject-scoped finders.
	 * @param TaskInboxService $inbox Row shaping and case context.
	 * @param TaskTemporalProjection $temporal The clock for the row projection.
	 * @param FileService $files Stores uploads onto the case object.
	 * @param LoggerInterface $logger Diagnostics.
	 */
	public function __construct(
		private readonly TaskService $tasks,
		private readonly TaskMapper $mapper,
		private readonly TaskInboxService $inbox,
		private readonly TaskTemporalProjection $temporal,
		private readonly FileService $files,
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
		$limit = max(1, min($limit, 500));
		$offset = max(0, $offset);
		$party = $subject->partyReference();

		$page = $this->mapper->findOpenExternalForParty(partyReference: $party, limit: $limit, offset: $offset);
		$total = $this->mapper->countOpenExternalForParty(partyReference: $party);
		$contexts = $this->inbox->subjectContextsFor(tasks: $page);
		$now = $this->temporal->now();

		$results = [];
		foreach ($page as $task) {
			// Belt and braces over the WHERE clause: a row that is not this
			// party's never leaves the service, whatever the query returned.
			if (hash_equals($party, (string)$task->getAssignee()) === false) {
				continue;
			}

			$results[] = $this->inbox->row(task: $task, subjects: $contexts, now: $now);
		}

		return [
			'results' => $results,
			'total' => $total,
			'limit' => $limit,
			'offset' => $offset,
		];
	}//end listForSubject()

	/**
	 * One portal task, if it is this subject's.
	 *
	 * @param PortalSubject $subject The acting subject.
	 * @param string $uuid The task uuid.
	 *
	 * @return Task The task.
	 *
	 * @throws DoesNotExistException When absent, not external, or not this
	 *                               subject's: all three read identically, so
	 *                               a stranger learns nothing from the answer.
	 *
	 * @spec openspec/changes/flow-portal-task/specs/flow-portal-task/spec.md#requirement-delivery-rides-the-portal-contribution-surface-and-nothing-else
	 */
	public function show(PortalSubject $subject, string $uuid): Task {
		$task = $this->tasks->get(uuid: $uuid);
		if ((string)$task->getPerformerType() !== Task::PERFORMER_EXTERNAL
			|| hash_equals($subject->partyReference(), (string)$task->getAssignee()) === false
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
		return $this->inbox->row(
			task: $task,
			subjects: $this->inbox->subjectContextsFor(tasks: [$task]),
			now: $this->temporal->now()
		);
	}//end row()

	/**
	 * Complete a portal task as the acting subject, storing its uploads on
	 * the case object first.
	 *
	 * @param PortalSubject $subject The acting subject.
	 * @param string $uuid The task uuid.
	 * @param array<string, mixed> $answers The submitted answer fields.
	 * @param string|null $comment The party's comment, when any.
	 * @param array<int, array<string, mixed>> $files The uploads, each
	 *                                                {name, type, size, tmp_name|content}.
	 * @param string $outcome The outcome to record; defaults to `submitted`.
	 *
	 * @return Task The completed task.
	 *
	 * @throws \OCA\OpenRegister\Exception\TaskAccessDeniedException When the
	 *         subject is not the matched party (audited before anything is stored).
	 * @throws \OCA\OpenRegister\Exception\TaskConflictException When the task is terminal.
	 * @throws TaskValidationException When an upload constraint is violated (nothing stored).
	 * @throws DoesNotExistException When no such task exists.
	 *
	 * @spec openspec/changes/flow-portal-task/specs/flow-portal-task/spec.md#requirement-an-upload-completion-lands-as-a-file-attachment-on-the-case-object
	 */
	public function complete(
		PortalSubject $subject,
		string $uuid,
		array $answers = [],
		?string $comment = null,
		array $files = [],
		string $outcome = self::DEFAULT_OUTCOME,
	): Task {
		// 1. Authorize, and audit a denial, BEFORE any other step. A stranger
		//    who knows the uuid must not get a validation message, let alone a
		//    file on somebody else's case.
		$task = $this->tasks->openFor(verb: 'complete', uuid: $uuid, actor: $subject->actor());

		// 2. Validate against the constraints the node froze on the task.
		//    Refused means nothing was stored and the task is still open.
		$this->assertUploadConstraints(task: $task, files: $files);

		// 3. Store every file on the CASE object. Order matters (design D-5):
		//    an orphaned file on the right case is recoverable and visible; a
		//    completed task whose evidence does not exist is neither.
		$evidence = $this->storeFiles(task: $task, files: $files);

		// 4. Record the completion, referencing the stored files.
		$outcome = trim($outcome);
		if ($outcome === '') {
			$outcome = self::DEFAULT_OUTCOME;
		}

		return $this->tasks->complete(
			uuid: $uuid,
			outcome: $outcome,
			resultText: null,
			comment: $comment,
			actor: $subject->actor(),
			responses: $answers,
			evidence: $evidence
		);
	}//end complete()

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
			$this->assertFileAllowed(file: $file, maxBytes: $maxBytesLimit, accepted: $accepted);
		}
	}//end assertUploadConstraints()

	/**
	 * Refuse ONE file that breaks a per-file constraint, naming it.
	 *
	 * @param array<string, mixed> $file The upload.
	 * @param int $maxBytes The size limit; 0 for none.
	 * @param array<int, string> $accepted The accepted types; empty for any.
	 *
	 * @return void
	 *
	 * @throws TaskValidationException On the violated constraint.
	 */
	private function assertFileAllowed(array $file, int $maxBytes, array $accepted): void {
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

		$type = trim((string)($file['type'] ?? ''));
		if ($accepted !== [] && $this->typeAccepted(name: $name, type: $type, accepted: $accepted) === false) {
			throw new TaskValidationException(
				message: sprintf("File '%s' has type '%s', which is not one of %s (uploadAcceptedTypes).", $name, $type, implode(', ', $accepted))
			);
		}
	}//end assertFileAllowed()

	/**
	 * Whether a file matches the accepted-type list: an exact media type, a
	 * `type/*` wildcard, or an extension (`pdf` or `.pdf`).
	 *
	 * @param string $name The file name.
	 * @param string $type The declared media type.
	 * @param array<int, string> $accepted The accepted entries.
	 *
	 * @return bool True when at least one entry admits the file.
	 */
	private function typeAccepted(string $name, string $type, array $accepted): bool {
		$extension = strtolower((string)pathinfo($name, PATHINFO_EXTENSION));
		$type = strtolower($type);
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
