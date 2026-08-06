<?php

/**
 * OpenRegister ContextChatSubmissionListener
 *
 * Submits (and removes) register object content to Nextcloud's Context Chat
 * (`OCP\ContextChat`) RAG pipeline on the existing object lifecycle events —
 * the same events `ObjectMetricsListener` already consumes — so no hot-path
 * service needs modification. Content is only ever submitted for objects on
 * a schema that opted in via `x-openregister-contextchat` AND that satisfy
 * a publication predicate.
 *
 * Publication predicate note: the design this listener implements was
 * written against OpenRegister's now-removed `@self.published` /
 * `@self.depublished` object metadata columns (see
 * `openspec/specs/deprecate-published-metadata/spec.md` — those columns
 * were dropped and replaced fleet-wide by RBAC `$now`-based authorization
 * rules). This listener therefore implements the living equivalent: an
 * object is treated as "published" when the `public` group would be granted
 * `read` under the schema's currently-resolved authorization — the exact
 * mechanism the deprecation spec's own migration guide documents for
 * expressing a publication window (`{"read": [{"group": "public", "match":
 * {"field": {"$lte": "$now"}}}]}`). This preserves the design's intent
 * ("never submit content a given searcher would not otherwise be allowed to
 * see") using the mechanism that actually exists in the codebase today.
 *
 * Recording is fail-soft by construction: every public entry point catches
 * `Throwable` and only logs, so a Context Chat / `ContentManager` failure
 * can never abort the object write that triggered it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Listener
 * @package  OCA\OpenRegister\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @template-implements IEventListener<ObjectCreatedEvent|ObjectUpdatedEvent|ObjectDeletedEvent>
 *
 * @spec openspec/specs/context-chat-provider/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Listener;

use OCA\OpenRegister\ContextChat\ContentProvider;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Exception\AuthorizationUnresolvableException;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCP\ContextChat\ContentItem;
use OCP\ContextChat\IContentManager;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IUserManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Submit/remove object content on the object lifecycle events.
 *
 * @spec openspec/specs/context-chat-provider/spec.md
 */
class ContextChatSubmissionListener implements IEventListener
{

    /**
     * Request-scoped cache of "seen" (logged in at least once) user ids,
     * used as the broadcast audience for a publicly-readable object whose
     * authorization does not name specific groups. Populated lazily.
     *
     * @var string[]|null
     */
    private ?array $seenUserIdsCache = null;

    /**
     * Wire collaborators.
     *
     * ⚠️ `IContentManager` is resolved LAZILY, in {@see self::contentManager()}.
     * The docblock this replaces claimed it is "always resolvable via DI"; it
     * is not, and the difference is instance-wide.
     *
     * `OCP\ContextChat` is core API only from **Nextcloud 33**. It is absent
     * from `stable31` and `stable32`, and this app's `info.xml` declares
     * `min-version="28"`. A constructor typehint on a class that does not
     * exist is fatal the moment the container resolves this listener —
     * `SimpleContainer::resolve()` calls `new ReflectionClass()` on each
     * parameter type, which is the load.
     *
     * That matters far more here than it would in a command, because this
     * listener is registered for ObjectCreatedEvent, ObjectUpdatedEvent and
     * ObjectDeletedEvent — it is resolved on EVERY object write. On NC 28-32
     * the old signature made every create, update and delete throw
     * `ReflectionException: Class "OCP\ContextChat\IContentManager" does not
     * exist`, from a feature the instance is not even using.
     *
     * Reproduced under a probe that maps OCP\ContextChat\* to nothing, exactly
     * as those servers do; see the fleet note in ContextChatReindexCommand.
     *
     * @param ContainerInterface $container         Container, for the lazy IContentManager resolve.
     * @param SchemaMapper       $schemaMapper      Schema lookup mapper.
     * @param PermissionHandler  $permissionHandler RBAC evaluator, used for the publication predicate + audience.
     * @param IUserManager       $userManager       User manager, for the broadcast-audience fallback.
     * @param LoggerInterface    $logger            PSR logger.
     *
     * @return void
     *
     * @spec openspec/specs/context-chat-provider/spec.md
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly SchemaMapper $schemaMapper,
        private readonly PermissionHandler $permissionHandler,
        private readonly IUserManager $userManager,
        private readonly LoggerInterface $logger
    ) {
    }//end __construct()

    /**
     * Resolve core's Context Chat content manager, or null when unavailable.
     *
     * Returns null on Nextcloud below 33, where `OCP\ContextChat` does not
     * exist. Callers skip submission in that case — the alternative is a fatal
     * on an object write, which is not a trade this feature gets to make.
     *
     * `interface_exists()` asks the autoloader and answers false rather than
     * dying; the interface name is a plain string here so nothing is resolved
     * at compile time.
     *
     * @return IContentManager|null The content manager, or null when Context Chat is unavailable.
     *
     * @spec openspec/specs/context-chat-provider/spec.md
     */
    private function contentManager(): ?IContentManager
    {
        if (interface_exists('OCP\\ContextChat\\IContentManager') === false) {
            return null;
        }

        try {
            return $this->container->get(IContentManager::class);
        } catch (Throwable $e) {
            $this->logger->debug(
                '[ContextChatSubmissionListener] Context Chat unavailable: '.$e->getMessage()
            );
            return null;
        }
    }//end contentManager()

    /**
     * Route an inbound object lifecycle event to submit or remove.
     *
     * @param Event $event Inbound dispatcher event.
     *
     * @return void
     *
     * @spec openspec/specs/context-chat-provider/spec.md
     */
    public function handle(Event $event): void
    {
        try {
            if ($event instanceof ObjectDeletedEvent) {
                $this->remove(object: $event->getObject());
                return;
            }

            if ($event instanceof ObjectCreatedEvent) {
                $this->submitIfEligible(object: $event->getObject());
                return;
            }

            if ($event instanceof ObjectUpdatedEvent) {
                $this->submitIfEligible(object: $event->getNewObject());
                return;
            }
        } catch (Throwable $e) {
            // Context Chat submission must never break the write it observes.
            $this->logger->warning(
                message: '[ContextChatSubmissionListener] Failed to process object lifecycle event',
                context: [
                    'file'  => __FILE__,
                    'line'  => __LINE__,
                    'event' => get_class($event),
                    'error' => $e->getMessage(),
                ]
            );
        }//end try
    }//end handle()

    /**
     * Submit an object's content when its schema is opted in AND it
     * satisfies the publication predicate. When the schema is opted in but
     * the object does NOT satisfy the predicate (e.g. a depublished update),
     * any previously-submitted content is removed instead of resubmitted.
     *
     * @param ObjectEntity $object The affected object (new state, for updates).
     * @param Schema|null  $schema Optional pre-resolved schema (avoids a repeat lookup during a batch walk).
     *
     * @return bool True when content was actually submitted.
     *
     * @spec openspec/specs/context-chat-provider/spec.md#requirement-only-opted-in-schemas-must-have-their-objects-submitted-to-context-chat
     * @spec openspec/specs/context-chat-provider/spec.md#requirement-only-published-objects-must-be-submitted-to-context-chat
     */
    public function submitIfEligible(ObjectEntity $object, ?Schema $schema=null): bool
    {
        $schema = ($schema ?? $this->resolveSchema(object: $object));
        if ($schema === null || $schema->isContextChatIndexingEnabled() === false) {
            return false;
        }

        $audience = $this->resolvePublicReadAudience(object: $object, schema: $schema);
        if ($audience === null) {
            // Not publicly readable: remove any previously-submitted content
            // rather than resubmitting a now-depublished object.
            $this->removeContentItem(object: $object);
            return false;
        }

        $this->submitContentItem(object: $object, schema: $schema, audience: $audience);
        return true;
    }//end submitIfEligible()

    /**
     * Remove an object's content from Context Chat, provided its schema is
     * (or was) opted in. Runs regardless of the object's published state at
     * delete time.
     *
     * @param ObjectEntity $object The deleted object.
     * @param Schema|null  $schema Optional pre-resolved schema.
     *
     * @return void
     *
     * @spec openspec/specs/context-chat-provider/spec.md#requirement-object-deletion-must-remove-submitted-content-from-context-chat
     */
    public function remove(ObjectEntity $object, ?Schema $schema=null): void
    {
        $schema = ($schema ?? $this->resolveSchema(object: $object));
        if ($schema === null || $schema->isContextChatIndexingEnabled() === false) {
            return;
        }

        $this->removeContentItem(object: $object);
    }//end remove()

    /**
     * Resolve the schema an object belongs to.
     *
     * @param ObjectEntity $object The object.
     *
     * @return Schema|null The schema, or null when unresolvable.
     */
    private function resolveSchema(ObjectEntity $object): ?Schema
    {
        $schemaId = $object->getSchema();
        if ($schemaId === null || $schemaId === '') {
            return null;
        }

        try {
            return $this->schemaMapper->find(id: $schemaId, _rbac: false, _multitenancy: false);
        } catch (Throwable $e) {
            $this->logger->debug(
                message: '[ContextChatSubmissionListener] Failed to resolve schema for object',
                context: [
                    'file'     => __FILE__,
                    'line'     => __LINE__,
                    'schemaId' => $schemaId,
                    'error'    => $e->getMessage(),
                ]
            );
            return null;
        }
    }//end resolveSchema()

    /**
     * Determine whether the `public` group would be granted `read` on this
     * object under the schema's currently-resolved authorization — the
     * living equivalent of the removed `@self.published` predicate — and,
     * when so, resolve the audience of user ids Context Chat should grant
     * access to for this content item.
     *
     * @param ObjectEntity $object The object being evaluated.
     * @param Schema       $schema The object's schema.
     *
     * @return string[]|null The audience (non-empty list of user ids), or null when not publicly readable.
     *
     * @spec openspec/specs/context-chat-provider/spec.md#requirement-only-published-objects-must-be-submitted-to-context-chat
     */
    private function resolvePublicReadAudience(ObjectEntity $object, Schema $schema): ?array
    {
        try {
            $authorization = $this->permissionHandler->resolveAuthorization(schema: $schema, object: $object);
        } catch (AuthorizationUnresolvableException $e) {
            // Fail-closed: an unresolvable authorization must never submit.
            return null;
        }

        $isPubliclyReadable = $this->permissionHandler->hasGroupPermission(
            authorization: $authorization,
            groupId: 'public',
            action: 'read',
            userId: null,
            userGroup: null,
            objectOwner: null,
            objectData: $object->getObject(),
            objectOrganisation: $object->getOrganisation()
        );

        if ($isPubliclyReadable === false) {
            return null;
        }

        // Prefer a concrete, group-scoped reader list when the schema names
        // specific groups; fall back to every seen user for a genuinely
        // open/broadcast schema (Context Chat's ContentItem::$users contract
        // requires a non-empty audience — an empty list is interpreted as
        // "remove from the knowledge base").
        $readers = $this->permissionHandler->getReadableByUsers(object: $object);
        if ($readers !== []) {
            return $readers;
        }

        return $this->allSeenUserIds();
    }//end resolvePublicReadAudience()

    /**
     * Lazily resolve and cache every "seen" (previously logged in) user id
     * on this instance, for the broadcast-audience fallback.
     *
     * @return string[] User ids.
     */
    private function allSeenUserIds(): array
    {
        if ($this->seenUserIdsCache !== null) {
            return $this->seenUserIdsCache;
        }

        $userIds = [];
        try {
            $this->userManager->callForSeenUsers(
                function ($user) use (&$userIds): void {
                    $userIds[] = $user->getUID();
                }
            );
        } catch (Throwable $e) {
            $this->logger->warning(
                message: '[ContextChatSubmissionListener] Failed to enumerate seen users for broadcast audience',
                context: ['file' => __FILE__, 'line' => __LINE__, 'error' => $e->getMessage()]
            );
        }

        $this->seenUserIdsCache = $userIds;
        return $userIds;
    }//end allSeenUserIds()

    /**
     * Build and submit a `ContentItem` for an object.
     *
     * @param ObjectEntity $object   The object to submit.
     * @param Schema       $schema   The object's schema.
     * @param string[]     $audience Non-empty list of user ids granted access.
     *
     * @return void
     *
     * @spec openspec/specs/context-chat-provider/spec.md#requirement-only-opted-in-schemas-must-have-their-objects-submitted-to-context-chat
     */
    private function submitContentItem(ObjectEntity $object, Schema $schema, array $audience): void
    {
        $uuid = $object->getUuid();
        if ($uuid === null || $uuid === '') {
            return;
        }

        $lastModified = ($object->getUpdated() ?? $object->getCreated() ?? new \DateTime());

        // Resolve BEFORE touching ContentItem or ContentProvider below: both
        // are unloadable on NC < 33 (ContentItem is OCP\ContextChat\*, and
        // reading ContentProvider::PROVIDER_ID loads a class whose header
        // implements the missing IContentProvider).
        $contentManager = $this->contentManager();
        if ($contentManager === null) {
            return;
        }

        $item = new ContentItem(
            itemId: $uuid,
            providerId: ContentProvider::PROVIDER_ID,
            title: $this->resolveTitle(object: $object),
            content: $this->renderContent(object: $object, schema: $schema),
            documentType: 'text/plain',
            lastModified: $lastModified,
            users: $audience
        );

        $contentManager->submitContent(
            ContentProvider::APP_ID,
            [$item]
        );
    }//end submitContentItem()

    /**
     * Issue a content-removal call for an object.
     *
     * @param ObjectEntity $object The object to remove.
     *
     * @return void
     *
     * @spec openspec/specs/context-chat-provider/spec.md#requirement-object-deletion-must-remove-submitted-content-from-context-chat
     */
    private function removeContentItem(ObjectEntity $object): void
    {
        $uuid = $object->getUuid();
        if ($uuid === null || $uuid === '') {
            return;
        }

        // Same reason as submitContentItem(): ContentProvider's constants are
        // read below, and loading that class is fatal on NC < 33.
        $contentManager = $this->contentManager();
        if ($contentManager === null) {
            return;
        }

        $contentManager->deleteContent(
            ContentProvider::APP_ID,
            ContentProvider::PROVIDER_ID,
            [$uuid]
        );
    }//end removeContentItem()

    /**
     * Resolve a human-readable title for the content item.
     *
     * @param ObjectEntity $object The object.
     *
     * @return string The title.
     */
    private function resolveTitle(ObjectEntity $object): string
    {
        $data = ($object->getObject() ?? []);

        $name = ($data['name'] ?? $object->getName() ?? null);
        if (is_string($name) === true && $name !== '') {
            return $name;
        }

        $summary = ($data['summary'] ?? $object->getSummary() ?? null);
        if (is_string($summary) === true && $summary !== '') {
            return $summary;
        }

        return (string) ($object->getUuid() ?? 'Untitled object');
    }//end resolveTitle()

    /**
     * Render a flat text representation of an object's non-writeOnly,
     * non-relation scalar properties, for the content item's plain-text
     * `content`. Richer per-field control is deferred (see design.md Open
     * Questions).
     *
     * @param ObjectEntity $object The object.
     * @param Schema       $schema The object's schema (for the writeOnly property allow-list).
     *
     * @return string The rendered content.
     *
     * @spec openspec/specs/context-chat-provider/spec.md
     */
    private function renderContent(ObjectEntity $object, Schema $schema): string
    {
        $data      = ($object->getObject() ?? []);
        $writeOnly = $schema->getWriteOnlyProperties();
        $lines     = [];

        foreach ($data as $key => $value) {
            if ($key === '@self' || in_array($key, $writeOnly, true) === true) {
                continue;
            }

            if (is_scalar($value) === false) {
                // Skip relations/nested objects/arrays — text rendering
                // covers scalar properties only.
                continue;
            }

            $lines[] = $key.': '.$value;
        }

        return implode(PHP_EOL, $lines);
    }//end renderContent()
}//end class
