<?php

/**
 * NotesProvider — wraps NoteService as an IntegrationProvider.
 *
 * Notes ride NC's Comments subsystem (link-table storage via
 * `oc_comments`). The provider is CRUD-capable; mutation methods
 * delegate to NoteService's existing APIs.
 *
 * Always available — comments ship with NC core — so `requiredApp`
 * returns null and `isEnabled()` is hardcoded true.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Integration\BuiltinProviders
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/pluggable-integration-registry/tasks.md#task-13
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Integration\BuiltinProviders;

use OCA\OpenRegister\Service\Integration\AbstractIntegrationProvider;
use OCA\OpenRegister\Service\NoteService;
use OCP\IL10N;

/**
 * Notes integration provider — delegates CRUD to NoteService.
 */
class NotesProvider extends AbstractIntegrationProvider {
	/**
	 * Constructor.
	 *
	 * @param NoteService $noteService Notes service.
	 * @param IL10N $l10n Localisation.
	 *
	 * @return void
	 */
	public function __construct(
		private NoteService $noteService,
		private IL10N $l10n,
	) {
	}//end __construct()

	/**
	 * Stable provider id used in routes and configs.
	 *
	 * @return string Stable provider identifier.
	 */
	public function getId(): string {
		return 'notes';
	}//end getId()

	/**
	 * Translated, human-readable provider label.
	 *
	 * @return string Translated, human-readable provider label.
	 */
	public function getLabel(): string {
		return $this->l10n->t('Notes');
	}//end getLabel()

	/**
	 * MDI icon name for the provider.
	 *
	 * @return string MDI icon name for the provider.
	 */
	public function getIcon(): string {
		return 'CommentTextOutline';
	}//end getIcon()

	/**
	 * Group identifier for UI grouping (or null).
	 *
	 * @return string|null Group identifier for UI grouping.
	 */
	public function getGroup(): ?string {
		return 'core';
	}//end getGroup()

	/**
	 * Required NC app id (null = built-in).
	 *
	 * @return string|null Required app id (null = built-in).
	 */
	public function getRequiredApp(): ?string {
		return null;
	}//end getRequiredApp()

	/**
	 * Storage strategy hint for the registry.
	 *
	 * @return string Storage strategy hint for the registry.
	 */
	public function getStorageStrategy(): string {
		return 'link-table';
	}//end getStorageStrategy()

	/**
	 * True when the provider is available for use.
	 *
	 * @return bool True when the provider is available for use.
	 */
	public function isEnabled(): bool {
		return true;
	}//end isEnabled()

	/**
	 * List notes linked to the given OR object.
	 *
	 * @param string $register Register slug or numeric id.
	 * @param string $schema Schema slug or numeric id.
	 * @param string $objectId Owning object uuid.
	 * @param array<string,mixed> $filters Pagination filters (_limit / _page).
	 *
	 * @return array<int,array<string,mixed>> Notes rows.
	 *
	 * @spec openspec/changes/pluggable-integration-registry/tasks.md#task-13
	 */
	public function list(string $register, string $schema, string $objectId, array $filters = []): array {
		$limit = (int)($filters['_limit'] ?? 50);
		$offset = (int)($filters['_page'] ?? 0) * $limit;
		return $this->noteService->getNotesForObject(objectUuid: $objectId, limit: $limit, offset: max(0, $offset));
	}//end list()

	/**
	 * Create a note linked to the given OR object.
	 *
	 * @param string $register Register slug or numeric id.
	 * @param string $schema Schema slug or numeric id.
	 * @param string $objectId Owning object uuid.
	 * @param array<string,mixed> $payload Note payload (message field).
	 *
	 * @return array<string,mixed> Created note row.
	 *
	 * @spec openspec/changes/pluggable-integration-registry/tasks.md#task-13
	 */
	public function create(string $register, string $schema, string $objectId, array $payload): array {
		$message = (string)($payload['message'] ?? '');
		return $this->noteService->createNote(objectUuid: $objectId, message: $message);
	}//end create()

	/**
	 * Update an existing note.
	 *
	 * @param string $register Register slug or numeric id.
	 * @param string $schema Schema slug or numeric id.
	 * @param string $objectId Owning object uuid.
	 * @param string $entityId Note id (numeric string).
	 * @param array<string,mixed> $payload Update payload (message field).
	 *
	 * @return array<string,mixed> Updated note row.
	 *
	 * @spec openspec/changes/pluggable-integration-registry/tasks.md#task-13
	 */
	public function update(string $register, string $schema, string $objectId, string $entityId, array $payload): array {
		$message = (string)($payload['message'] ?? '');
		return $this->noteService->updateNote(noteId: (int)$entityId, message: $message);
	}//end update()

	/**
	 * Delete a note.
	 *
	 * @param string $register Register slug or numeric id.
	 * @param string $schema Schema slug or numeric id.
	 * @param string $objectId Owning object uuid.
	 * @param string $entityId Note id (numeric string).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/pluggable-integration-registry/tasks.md#task-13
	 */
	public function delete(string $register, string $schema, string $objectId, string $entityId): void {
		$this->noteService->deleteNote(noteId: (int)$entityId);
	}//end delete()
}//end class
