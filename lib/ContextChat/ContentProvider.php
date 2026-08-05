<?php

/**
 * OpenRegister ContextChat ContentProvider
 *
 * Implements `OCP\ContextChat\IContentProvider` so opted-in, published
 * register objects are indexed into the Nextcloud Assistant's RAG pipeline.
 * Registration is soft-gated (see ContentProviderRegistrationListener) so a
 * standalone instance without the `context_chat` app installed is
 * unaffected — `OCP\ContextChat\*` are core Nextcloud interfaces (present
 * since NC 32) that resolve safely via DI regardless of whether the
 * `context_chat` app itself is enabled.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category ContextChat
 * @package  OCA\OpenRegister\ContextChat
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/context-chat-provider/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\ContextChat;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Listener\ContextChatSubmissionListener;
use OCA\OpenRegister\Service\DeepLinkRegistryService;
use OCP\ContextChat\IContentProvider;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Content provider surfacing OpenRegister objects to Context Chat.
 *
 * @spec openspec/specs/context-chat-provider/spec.md
 */
class ContentProvider implements IContentProvider
{

    /**
     * The app id this provider is registered under.
     *
     * @var string
     */
    public const APP_ID = 'openregister';

    /**
     * The provider id this provider is registered under.
     *
     * @var string
     */
    public const PROVIDER_ID = 'openregister_objects';

    /**
     * Batch size used when walking a (register, schema) pair during import.
     *
     * @var int
     */
    private const BATCH_SIZE = 200;

    /**
     * Wire collaborators.
     *
     * @param ContextChatSubmissionListener $submissionListener Shared submission logic (single object).
     * @param SchemaMapper                  $schemaMapper       Schema lookup mapper.
     * @param RegisterMapper                $registerMapper     Register lookup mapper.
     * @param MagicMapper                   $magicMapper        Object lookup/walk mapper.
     * @param DeepLinkRegistryService       $deepLinkRegistry   Deep link registry for `getItemUrl`.
     * @param IURLGenerator                 $urlGenerator       URL generator for the fallback route.
     * @param LoggerInterface               $logger             PSR logger.
     *
     * @return void
     *
     * @spec openspec/specs/context-chat-provider/spec.md
     */
    public function __construct(
        private readonly ContextChatSubmissionListener $submissionListener,
        private readonly SchemaMapper $schemaMapper,
        private readonly RegisterMapper $registerMapper,
        private readonly MagicMapper $magicMapper,
        private readonly DeepLinkRegistryService $deepLinkRegistry,
        private readonly IURLGenerator $urlGenerator,
        private readonly LoggerInterface $logger
    ) {
    }//end __construct()

    /**
     * The unique identifier for this provider.
     *
     * @return string
     *
     * @spec openspec/specs/context-chat-provider/spec.md
     */
    public function getId(): string
    {
        return self::PROVIDER_ID;
    }//end getId()

    /**
     * The app id making this provider available.
     *
     * @return string
     *
     * @spec openspec/specs/context-chat-provider/spec.md
     */
    public function getAppId(): string
    {
        return self::APP_ID;
    }//end getAppId()

    /**
     * Resolve the absolute URL a user would reach this content item at.
     *
     * Resolves via {@see DeepLinkRegistryService::resolveUrl()} — the same
     * mechanism `ObjectsProvider` already uses for unified-search result
     * URLs — and falls back to the `openregister.objects.show` route when no
     * app has claimed a deep link for the object's (register, schema) pair.
     *
     * @param string $id The object's uuid (the content item's `itemId`).
     *
     * @return string The resolved, absolute URL.
     *
     * @spec openspec/specs/context-chat-provider/spec.md#requirement-getitemurl-and-initial-import-reuse-existing-openregister-infrastructure
     */
    public function getItemUrl(string $id): string
    {
        try {
            $object = $this->magicMapper->find(
                identifier: $id,
                includeDeleted: false,
                _rbac: false,
                _multitenancy: false
            );
        } catch (Throwable $e) {
            $this->logger->debug(
                message: '[ContentProvider] getItemUrl: object not found, falling back to generic route',
                context: [
                    'file'  => __FILE__,
                    'line'  => __LINE__,
                    'id'    => $id,
                    'error' => $e->getMessage(),
                ]
            );
            return $this->urlGenerator->linkToRoute(
                'openregister.objects.show',
                ['register' => 0, 'schema' => 0, 'id' => $id]
            );
        }//end try

        $registerId = (int) $object->getRegister();
        $schemaId   = (int) $object->getSchema();

        $flatData = array_merge(
            ($object->getObject() ?? []),
            [
                'id'       => $object->getUuid(),
                'uuid'     => $object->getUuid(),
                'register' => $registerId,
                'schema'   => $schemaId,
            ]
        );

        $url = $this->deepLinkRegistry->resolveUrl(
            registerId: $registerId,
            schemaId: $schemaId,
            objectData: $flatData
        );
        if ($url !== null) {
            return $url;
        }

        return $this->urlGenerator->linkToRoute(
            'openregister.objects.show',
            ['register' => $registerId, 'schema' => $schemaId, 'id' => $object->getUuid()]
        );
    }//end getItemUrl()

    /**
     * Walk every opted-in (register, schema) pair and submit each qualifying
     * (published) object, in bounded batches.
     *
     * @return void
     *
     * @spec openspec/specs/context-chat-provider/spec.md#requirement-getitemurl-and-initial-import-reuse-existing-openregister-infrastructure
     */
    public function triggerInitialImport(): void
    {
        $this->reindex(registerId: null, schemaId: null);
    }//end triggerInitialImport()

    /**
     * Batched (re)submission walk, optionally scoped to a single register
     * and/or schema. Shared by {@see triggerInitialImport()} and
     * `openregister:contextchat:reindex`.
     *
     * @param int|null $registerId Optional register id to scope the walk to.
     * @param int|null $schemaId   Optional schema id to scope the walk to.
     *
     * @return int Number of objects actually submitted (published, opted-in objects).
     *
     * @spec openspec/specs/context-chat-provider/spec.md#requirement-getitemurl-and-initial-import-reuse-existing-openregister-infrastructure
     */
    public function reindex(?int $registerId, ?int $schemaId): int
    {
        $submitted = 0;

        foreach ($this->resolveOptedInPairs(registerId: $registerId, schemaId: $schemaId) as $pair) {
            [$register, $schema] = $pair;

            $offset = 0;
            do {
                $batch = $this->magicMapper->findAllInRegisterSchemaTable(
                    register: $register,
                    schema: $schema,
                    limit: self::BATCH_SIZE,
                    offset: $offset
                );

                // Counted once per page, not re-counted by the loop condition.
                // $batch is replaced wholesale on every iteration and never
                // mutated below, so this is the same value the condition read.
                $batchSize = count($batch);

                foreach ($batch as $object) {
                    if ($this->submissionListener->submitIfEligible(object: $object, schema: $schema) === true) {
                        $submitted++;
                    }
                }

                $offset += self::BATCH_SIZE;
            } while ($batchSize === self::BATCH_SIZE);
        }//end foreach

        return $submitted;
    }//end reindex()

    /**
     * Resolve every opted-in (register, schema) pair, optionally narrowed to
     * a single register and/or schema id.
     *
     * @param int|null $registerId Optional register id filter.
     * @param int|null $schemaId   Optional schema id filter.
     *
     * @return array<int, array{0: Register, 1: Schema}> List of (register, schema) pairs.
     *
     * @spec openspec/specs/context-chat-provider/spec.md#requirement-getitemurl-and-initial-import-reuse-existing-openregister-infrastructure
     */
    private function resolveOptedInPairs(?int $registerId, ?int $schemaId): array
    {
        $pairs = [];

        try {
            $schemas = $this->schemaMapper->findAll(_rbac: false, _multitenancy: false);
        } catch (Throwable $e) {
            $this->logger->warning(
                message: '[ContentProvider] Failed to enumerate schemas for reindex',
                context: ['file' => __FILE__, 'line' => __LINE__, 'error' => $e->getMessage()]
            );
            return [];
        }

        foreach ($schemas as $schema) {
            if ($schema->isContextChatIndexingEnabled() === false) {
                continue;
            }

            if ($schemaId !== null && $schema->getId() !== $schemaId) {
                continue;
            }

            $registerIds = $this->registerMapper->getAllRegisterIdsWithSchema((int) $schema->getId());
            foreach ($registerIds as $candidateRegisterId) {
                if ($registerId !== null && (int) $candidateRegisterId !== $registerId) {
                    continue;
                }

                try {
                    $register = $this->registerMapper->find(
                        id: $candidateRegisterId,
                        _rbac: false,
                        _multitenancy: false
                    );
                } catch (Throwable $e) {
                    continue;
                }

                $pairs[] = [$register, $schema];
            }
        }//end foreach

        return $pairs;
    }//end resolveOptedInPairs()
}//end class
