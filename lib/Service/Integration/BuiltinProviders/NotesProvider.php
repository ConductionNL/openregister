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
class NotesProvider extends AbstractIntegrationProvider
{

    /**
     * Constructor.
     *
     * @param NoteService $noteService Notes service.
     * @param IL10N       $l10n        Localisation.
     *
     * @return void
     */
    public function __construct(
        private NoteService $noteService,
        private IL10N $l10n,
    ) {
    }//end __construct()

    public function getId(): string
    {
        return 'notes';
    }//end getId()

    public function getLabel(): string
    {
        return $this->l10n->t('Notes');
    }//end getLabel()

    public function getIcon(): string
    {
        return 'CommentTextOutline';
    }//end getIcon()

    public function getGroup(): ?string
    {
        return 'core';
    }//end getGroup()

    public function getRequiredApp(): ?string
    {
        return null;
    }//end getRequiredApp()

    public function getStorageStrategy(): string
    {
        return 'link-table';
    }//end getStorageStrategy()

    public function isEnabled(): bool
    {
        return true;
    }//end isEnabled()

    public function list(string $register, string $schema, string $objectId, array $filters = []): array
    {
        $limit  = (int) ($filters['_limit'] ?? 50);
        $offset = (int) ($filters['_page'] ?? 0) * $limit;
        return $this->noteService->getNotesForObject($objectId, $limit, max(0, $offset));
    }//end list()

    public function create(string $register, string $schema, string $objectId, array $payload): array
    {
        $message = (string) ($payload['message'] ?? '');
        return $this->noteService->createNote($objectId, $message);
    }//end create()

    public function update(string $register, string $schema, string $objectId, string $entityId, array $payload): array
    {
        $message = (string) ($payload['message'] ?? '');
        return $this->noteService->updateNote((int) $entityId, $message);
    }//end update()

    public function delete(string $register, string $schema, string $objectId, string $entityId): void
    {
        $this->noteService->deleteNote((int) $entityId);
    }//end delete()

}//end class
