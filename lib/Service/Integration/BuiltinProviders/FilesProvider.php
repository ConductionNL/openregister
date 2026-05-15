<?php

/**
 * FilesProvider — wraps FileService as an IntegrationProvider.
 *
 * Files use the magic-column storage strategy: the link to a file is
 * stored as a column on the OR object row (the per-object folder lives
 * under that path). The provider's `list()` enumerates that folder
 * via `FileService::getFilesForEntity()`.
 *
 * Mutation methods route through the existing FileController + share
 * pipeline for now (the umbrella's controller refactor in tasks 18-22
 * will consolidate). Throwing `NotImplementedException` here keeps the
 * provider honest about its current capabilities while still
 * satisfying the contract.
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
 * @spec openspec/changes/pluggable-integration-registry/tasks.md#task-12
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Integration\BuiltinProviders;

use OCA\OpenRegister\Exception\NotImplementedException;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\Integration\AbstractIntegrationProvider;
use OCP\IL10N;
use Psr\Container\ContainerInterface;

/**
 * Files integration provider — magic-column read path, mutation
 * deferred to controller refactor.
 */
class FilesProvider extends AbstractIntegrationProvider
{

    /**
     * Constructor.
     *
     * @param FileService        $fileService File service.
     * @param ContainerInterface $container   DI container — used to
     *                                        resolve an ObjectEntity-
     *                                        producing mapper at call
     *                                        time (the canonical mapper
     *                                        varies across OR builds
     *                                        — MagicMapper /
     *                                        AbstractObjectMapper).
     * @param IL10N              $l10n        Localisation.
     *
     * @return void
     */
    public function __construct(
        private FileService $fileService,
        private ContainerInterface $container,
        private IL10N $l10n,
    ) {
    }//end __construct()

    public function getId(): string
    {
        return 'files';
    }//end getId()

    public function getLabel(): string
    {
        return $this->l10n->t('Files');
    }//end getLabel()

    public function getIcon(): string
    {
        return 'Paperclip';
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
        return 'magic-column';
    }//end getStorageStrategy()

    public function isEnabled(): bool
    {
        return true;
    }//end isEnabled()

    public function list(string $register, string $schema, string $objectId, array $filters = []): array
    {
        try {
            $object = $this->resolveObject($objectId);
            if ($object === null) {
                return [];
            }

            $nodes = $this->fileService->getFilesForEntity($object);
            return $this->normalize($nodes);
        } catch (\Throwable $e) {
            return [];
        }//end try
    }//end list()

    /**
     * Resolve an ObjectEntity-shaped value by uuid via whichever mapper
     * the host OR build exposes.
     *
     * @param string $objectId Object uuid.
     *
     * @return mixed|null ObjectEntity-shaped value, or null.
     */
    private function resolveObject(string $objectId)
    {
        foreach (
            [
                '\OCA\OpenRegister\Db\ObjectEntityMapper',
                '\OCA\OpenRegister\Db\MagicMapper',
                '\OCA\OpenRegister\Db\AbstractObjectMapper',
            ] as $candidate
        ) {
            try {
                $mapper = $this->container->get($candidate);
                if (is_object($mapper) === true && method_exists($mapper, 'find') === true) {
                    return $mapper->find($objectId);
                }
            } catch (\Throwable $e) {
                continue;
            }
        }

        return null;
    }//end resolveObject()

    /**
     * Mutation routes via FileController for now; see class docblock.
     *
     * @param string              $register Register slug or numeric id.
     * @param string              $schema   Schema slug or numeric id.
     * @param string              $objectId Owning object uuid.
     * @param array<string,mixed> $payload  New linked-thing fields.
     *
     * @return array<string,mixed>
     *
     * @throws NotImplementedException Always — write path consolidates
     *                                 in tasks 18-22.
     */
    public function create(string $register, string $schema, string $objectId, array $payload): array
    {
        throw new NotImplementedException(
            'FilesProvider write path delegates to FileController for now (umbrella tasks 18-22).'
        );
    }//end create()

    /**
     * Convert a list of NC Node-shaped values into the array shape
     * IntegrationProvider::list() promises.
     *
     * @param mixed $nodes Output from FileService::getFilesForEntity().
     *
     * @return array<int, array<string, mixed>>
     */
    private function normalize($nodes): array
    {
        if (is_array($nodes) === false) {
            return [];
        }

        $rows = [];
        foreach ($nodes as $node) {
            if (is_array($node) === true) {
                $rows[] = $node;
                continue;
            }

            if (is_object($node) === false) {
                continue;
            }

            $row = [];
            foreach (['getId', 'getName', 'getPath', 'getMimetype', 'getSize', 'getMTime'] as $accessor) {
                if (method_exists($node, $accessor) === false) {
                    continue;
                }
                try {
                    $value = $node->{$accessor}();
                } catch (\Throwable $e) {
                    continue;
                }
                $key       = lcfirst(substr($accessor, 3));
                $row[$key] = $value;
            }

            $rows[] = $row;
        }

        return $rows;
    }//end normalize()

}//end class
