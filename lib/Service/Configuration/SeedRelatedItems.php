<?php

/**
 * Creates the files, notes and tasks a seed object ships with.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Configuration
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/data-import-export/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Configuration;

use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Kernel\Contracts\NoteServiceInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectCalendarTasksInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Each item type is attempted independently, so a failure in one does not block the others.
 *
 * @spec openspec/specs/data-import-export/spec.md
 */
final class SeedRelatedItems
{

	/**
	 * @var FileService|null Writes the seed files, when present.
	 */
	private ?FileService $fileService = null;

	/**
	 * @var NoteServiceInterface|null Writes the seed notes, when present.
	 */
	private ?NoteServiceInterface $noteService = null;

	/**
	 * @var ObjectCalendarTasksInterface|null Writes the seed tasks, when present.
	 */
	private ?ObjectCalendarTasksInterface $taskService = null;

	/**
	 * Build the creator; the services arrive later and may stay missing.
	 *
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(private readonly LoggerInterface $logger)
	{
	}//end __construct()

	/**
	 * Set the service that writes seed files.
	 *
	 * @param FileService|null $fileService The file service.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	public function useFileService(?FileService $fileService): void
	{
		$this->fileService = $fileService;
	}//end useFileService()

	/**
	 * Set the service that writes seed notes.
	 *
	 * @param NoteServiceInterface|null $noteService The note service.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	public function useNoteService(?NoteServiceInterface $noteService): void
	{
		$this->noteService = $noteService;
	}//end useNoteService()

	/**
	 * Set the service that writes seed tasks.
	 *
	 * @param ObjectCalendarTasksInterface|null $taskService The task service.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	public function useTaskService(?ObjectCalendarTasksInterface $taskService): void
	{
		$this->taskService = $taskService;
	}//end useTaskService()

	/**
	 * Create a seeded object's related items and add their counts to the result.
	 *
	 * @param SeedRelatedTarget    $target The seeded object and its items.
	 * @param array<string, mixed> $result The result accumulator, updated in place.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	public function create(SeedRelatedTarget $target, array &$result): void
	{
		$files = (array) ($target->items['files'] ?? []);
		$notes = (array) ($target->items['notes'] ?? []);
		$tasks = (array) ($target->items['tasks'] ?? []);
		$this->logStart(target: $target, counts: [count($files), count($notes), count($tasks)]);

		$filesCreated = $this->createFiles(target: $target, files: $files);
		$notesCreated = $this->createNotes(target: $target, notes: $notes);
		$tasksCreated = $this->createTasks(target: $target, tasks: $tasks);

		$result['relatedFiles'] = (($result['relatedFiles'] ?? 0) + $filesCreated);
		$result['relatedNotes'] = (($result['relatedNotes'] ?? 0) + $notesCreated);
		$result['relatedTasks'] = (($result['relatedTasks'] ?? 0) + $tasksCreated);
		$this->logDone(target: $target, counts: [$filesCreated, $notesCreated, $tasksCreated]);
	}//end create()

	/**
	 * Log what is about to be created.
	 *
	 * @param SeedRelatedTarget $target The seeded object.
	 * @param array<int, int>   $counts The files, notes and tasks asked for.
	 *
	 * @return void
	 */
	private function logStart(SeedRelatedTarget $target, array $counts): void
	{
		$this->logger->debug(
			message: '[ImportHandler] Processing related items for seed object',
			context: [
				'file'        => __FILE__,
				'line'        => __LINE__,
				'object_uuid' => $target->object->getUuid(),
				'files_count' => $counts[0],
				'notes_count' => $counts[1],
				'tasks_count' => $counts[2],
			]
		);
	}//end logStart()

	/**
	 * Log what was created.
	 *
	 * @param SeedRelatedTarget $target The seeded object.
	 * @param array<int, int>   $counts The files, notes and tasks created.
	 *
	 * @return void
	 */
	private function logDone(SeedRelatedTarget $target, array $counts): void
	{
		$this->logger->debug(
			message: '[ImportHandler] Related items processed for seed object',
			context: [
				'file'          => __FILE__,
				'line'          => __LINE__,
				'object_uuid'   => $target->object->getUuid(),
				'files_created' => $counts[0],
				'notes_created' => $counts[1],
				'tasks_created' => $counts[2],
			]
		);
	}//end logDone()

	/**
	 * Attach the object's files.
	 *
	 * @param SeedRelatedTarget $target The seeded object.
	 * @param array             $files  The file specs: name, content, tags, share.
	 *
	 * @return int The number of files created.
	 */
	private function createFiles(SeedRelatedTarget $target, array $files): int
	{
		if ($this->fileService === null) {
			return 0;
		}

		$created = 0;
		foreach ($files as $spec) {
			$created += $this->createFile(target: $target, spec: $spec);
		}

		return $created;
	}//end createFiles()

	/**
	 * Attach one file; a failed write is logged and skipped.
	 *
	 * @param SeedRelatedTarget $target The seeded object.
	 * @param mixed             $spec   The file spec.
	 *
	 * @return int 1 when the file was created, otherwise 0.
	 */
	private function createFile(SeedRelatedTarget $target, mixed $spec): int
	{
		$name    = (string) ($spec['name'] ?? '');
		$content = $this->fileContent(target: $target, spec: $spec);
		if ($content === null) {
			return 0;
		}

		try {
			$this->fileService->addFile($target->object, $name, $content, (bool) ($spec['share'] ?? false), (array) ($spec['tags'] ?? []));
			return 1;
		} catch (Throwable $e) {
			$this->failed(target: $target, detail: ['kind' => 'file', 'name' => $name, 'error' => $e->getMessage()]);
			return 0;
		}
	}//end createFile()

	/**
	 * The content to write; a `base64:` prefix marks encoded content.
	 *
	 * A file without a name or with non-string content has none, and neither has one whose base64 does not decode.
	 *
	 * @param SeedRelatedTarget $target The seeded object.
	 * @param mixed             $spec   The file spec.
	 *
	 * @return string|null The content, or null to skip the file.
	 */
	private function fileContent(SeedRelatedTarget $target, mixed $spec): ?string
	{
		$name    = (string) ($spec['name'] ?? '');
		$content = ($spec['content'] ?? null);
		if ($name === '' || is_string($content) === false) {
			return null;
		}

		if (str_starts_with($content, 'base64:') === false) {
			return $content;
		}

		return $this->decoded(target: $target, spec: $spec);
	}//end fileContent()

	/**
	 * Decode `base64:` content; content that does not decode is logged and skipped.
	 *
	 * @param SeedRelatedTarget $target The seeded object.
	 * @param mixed             $spec   The file spec.
	 *
	 * @return string|null The content, or null to skip the file.
	 */
	private function decoded(SeedRelatedTarget $target, mixed $spec): ?string
	{
		$decoded = base64_decode(substr((string) $spec['content'], 7), strict: true);
		if ($decoded === false) {
			$context = ['object_uuid' => $target->object->getUuid(), 'name' => (string) $spec['name']];
			$this->warn(message: '[ImportHandler] Seed file base64 decode failed - skipping', context: $context);
			return null;
		}

		return $decoded;
	}//end decoded()

	/**
	 * Add the object's notes; they are written as the logged-in user, so without one they are skipped.
	 *
	 * @param SeedRelatedTarget $target The seeded object.
	 * @param array             $notes  The note specs: message.
	 *
	 * @return int The number of notes created.
	 */
	private function createNotes(SeedRelatedTarget $target, array $notes): int
	{
		if (count($notes) === 0 || $this->noteService === null) {
			return 0;
		}

		if ($target->hasUserContext === false) {
			$this->warnNoUser(kind: 'notes', context: ['object_uuid' => $target->object->getUuid(), 'count' => count($notes)]);
			return 0;
		}

		$created = 0;
		foreach ($notes as $spec) {
			$created += $this->createNote(target: $target, spec: $spec);
		}

		return $created;
	}//end createNotes()

	/**
	 * Add one note; one without a message is skipped, a failed one logged.
	 *
	 * @param SeedRelatedTarget $target The seeded object.
	 * @param mixed             $spec   The note spec.
	 *
	 * @return int 1 when the note was created, otherwise 0.
	 */
	private function createNote(SeedRelatedTarget $target, mixed $spec): int
	{
		$message = (string) ($spec['message'] ?? '');
		if ($message === '') {
			return 0;
		}

		try {
			$this->noteService->createNote((string) $target->object->getUuid(), $message);
			return 1;
		} catch (Throwable $e) {
			$this->failed(target: $target, detail: ['kind' => 'note', 'error' => $e->getMessage()]);
			return 0;
		}
	}//end createNote()

	/**
	 * Add the object's tasks; they are written as the logged-in user, so without one they are skipped.
	 *
	 * @param SeedRelatedTarget $target The seeded object.
	 * @param array             $tasks  The task specs: summary, description, status, priority, due.
	 *
	 * @return int The number of tasks created.
	 */
	private function createTasks(SeedRelatedTarget $target, array $tasks): int
	{
		if (count($tasks) === 0 || $this->taskService === null) {
			return 0;
		}

		if ($target->hasUserContext === false) {
			$this->warnNoUser(kind: 'tasks', context: ['object_uuid' => $target->object->getUuid(), 'count' => count($tasks)]);
			return 0;
		}

		$created = 0;
		foreach ($tasks as $spec) {
			$created += $this->createTask(target: $target, spec: $spec);
		}

		return $created;
	}//end createTasks()

	/**
	 * Add one task; one without a summary is skipped, a failed one logged.
	 *
	 * @param SeedRelatedTarget $target The seeded object.
	 * @param mixed             $spec   The task spec.
	 *
	 * @return int 1 when the task was created, otherwise 0.
	 */
	private function createTask(SeedRelatedTarget $target, mixed $spec): int
	{
		$summary = (string) ($spec['summary'] ?? '');
		if ($summary === '') {
			return 0;
		}

		try {
			$data = $this->taskData(spec: $spec);
			$this->taskService->createTask($target->registerId, $target->schemaId, (string) $target->object->getUuid(), $target->title, $data);
			return 1;
		} catch (Throwable $e) {
			$this->failed(target: $target, detail: ['kind' => 'task', 'summary' => $summary, 'error' => $e->getMessage()]);
			return 0;
		}
	}//end createTask()

	/**
	 * The task fields of a spec, with their defaults.
	 *
	 * @param mixed $spec The task spec.
	 *
	 * @return array<string, mixed> The summary, description, status, priority and due date.
	 */
	private function taskData(mixed $spec): array
	{
		return [
			'summary'     => (string) ($spec['summary'] ?? ''),
			'description' => (string) ($spec['description'] ?? ''),
			'status'      => (string) ($spec['status'] ?? 'needs-action'),
			'priority'    => (int) ($spec['priority'] ?? 0),
			'due'         => ($spec['due'] ?? null),
		];
	}//end taskData()

	/**
	 * Warn that notes or tasks are skipped for lack of a user session.
	 *
	 * @param string               $kind    What is skipped: notes or tasks.
	 * @param array<string, mixed> $context The log context.
	 *
	 * @return void
	 */
	private function warnNoUser(string $kind, array $context): void
	{
		$this->warn(message: "[ImportHandler] Skipping seed {$kind} - no user session available", context: $context);
	}//end warnNoUser()

	/**
	 * Warn that one item could not be created.
	 *
	 * @param SeedRelatedTarget    $target The seeded object.
	 * @param array<string, mixed> $detail The `kind` of item, and what to log about it.
	 *
	 * @return void
	 */
	private function failed(SeedRelatedTarget $target, array $detail): void
	{
		$kind = $detail['kind'];
		unset($detail['kind']);
		$this->warn(message: "[ImportHandler] Seed {$kind} creation failed", context: (['object_uuid' => $target->object->getUuid()] + $detail));
	}//end failed()

	/**
	 * Log a warning.
	 *
	 * @param string               $message The message.
	 * @param array<string, mixed> $context The log context.
	 *
	 * @return void
	 */
	private function warn(string $message, array $context): void
	{
		$this->logger->warning(message: $message, context: $context);
	}//end warn()
}//end class
