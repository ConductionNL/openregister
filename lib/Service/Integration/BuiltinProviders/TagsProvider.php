<?php

/**
 * TagsProvider — wraps the Nextcloud system tag manager as an
 * IntegrationProvider so tag CRUD on an OR object rides the same
 * registry machinery as every other integration.
 *
 * Storage strategy is `link-table` because tag<->object relationships
 * live in Nextcloud's own systemtag_object_mapping table. Tags
 * are always available — they ship with NC core — so `requiredApp`
 * returns null.
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
 * @spec openspec/changes/pluggable-integration-registry/tasks.md#task-15
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Integration\BuiltinProviders;

use OCA\OpenRegister\Exception\NotImplementedException;
use OCA\OpenRegister\Service\Integration\AbstractIntegrationProvider;
use OCP\IL10N;
use OCP\SystemTag\ISystemTagManager;
use OCP\SystemTag\ISystemTagObjectMapper;

/**
 * Tags integration provider — read path delegates to the system tag
 * object mapper; mutation is left to the existing TagsController
 * routes for now (the legacy path remains the authoritative writer
 * until the umbrella's controller refactor in tasks 18-22).
 */
class TagsProvider extends AbstractIntegrationProvider
{
    /**
     * Constructor.
     *
     * @param ISystemTagManager      $tagManager   System tag manager.
     * @param ISystemTagObjectMapper $objectMapper Tag-to-object mapper.
     * @param IL10N                  $l10n         Localisation service.
     *
     * @return void
     */
    public function __construct(
        private ISystemTagManager $tagManager,
        private ISystemTagObjectMapper $objectMapper,
        private IL10N $l10n,
    ) {
    }//end __construct()

    /**
     * Stable provider id used in routes and configs.
     *
     * @return string Stable provider identifier.
     */
    public function getId(): string
    {
        return 'tags';
    }//end getId()

    /**
     * Translated, human-readable provider label.
     *
     * @return string Translated, human-readable provider label.
     */
    public function getLabel(): string
    {
        return $this->l10n->t('Tags');
    }//end getLabel()

    /**
     * MDI icon name for the provider.
     *
     * @return string MDI icon name for the provider.
     */
    public function getIcon(): string
    {
        return 'TagOutline';
    }//end getIcon()

    /**
     * Group identifier for UI grouping (or null).
     *
     * @return string|null Group identifier for UI grouping (or null).
     */
    public function getGroup(): ?string
    {
        return 'core';
    }//end getGroup()

    /**
     * Required NC app id (null = built-in).
     *
     * @return string|null Required app id (null = built-in).
     */
    public function getRequiredApp(): ?string
    {
        return null;
    }//end getRequiredApp()

    /**
     * Storage strategy hint for the registry.
     *
     * @return string Storage strategy hint for the registry.
     */
    public function getStorageStrategy(): string
    {
        return 'link-table';
    }//end getStorageStrategy()

    /**
     * True when the provider is available for use.
     *
     * @return bool True when the provider is available for use.
     */
    public function isEnabled(): bool
    {
        return true;
    }//end isEnabled()

    /**
     * List tags attached to the given OR object.
     *
     * @param string              $register Register slug or numeric id.
     * @param string              $schema   Schema slug or numeric id.
     * @param string              $objectId Object uuid.
     * @param array<string,mixed> $filters  Reserved.
     *
     * @return array<int,array<string,mixed>>
     *
     * @spec openspec/changes/pluggable-integration-registry/tasks.md#task-15
     */
    public function list(string $register, string $schema, string $objectId, array $filters=[]): array
    {
        try {
            $tagIds = $this->objectMapper->getTagIdsForObjects([$objectId], 'openregister');
            $ids    = $tagIds[$objectId] ?? [];

            if ($ids === []) {
                return [];
            }

            $tags = $this->tagManager->getTagsByIds($ids);
            $rows = [];
            foreach ($tags as $tag) {
                $rows[] = [
                    'id'         => (string) $tag->getId(),
                    'name'       => $tag->getName(),
                    'visibility' => $tag->isUserVisible(),
                    'assignable' => $tag->isUserAssignable(),
                ];
            }

            return $rows;
        } catch (\Throwable $e) {
            // The object may simply have no tags or the mapping might
            // not yet exist. Surface an empty list rather than 500.
            return [];
        }//end try
    }//end list()

    /**
     * Mutation methods routed through the existing TagsController for
     * now — keep the umbrella focused on the registry contract and
     * leave the write-path consolidation to tasks 18-22.
     *
     * @param string              $register Register slug or numeric id.
     * @param string              $schema   Schema slug or numeric id.
     * @param string              $objectId Owning object uuid.
     * @param array<string,mixed> $payload  New linked-thing fields.
     *
     * @return array<string,mixed>
     *
     * @throws NotImplementedException Always — write path lives at
     *                                 `/api/tags/{...}` controllers.
     *
     * @spec exclude NotImplemented write stub — tag writes still route through TagsController;
     *   the consolidated write path is owned by pluggable-integration-registry tasks 18-22, not this stub.
     */
    public function create(string $register, string $schema, string $objectId, array $payload): array
    {
        throw new NotImplementedException(
            message: 'TagsProvider write path delegates to TagsController for now (umbrella tasks 18-22).'
        );
    }//end create()
}//end class
