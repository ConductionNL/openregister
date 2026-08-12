<?php

/**
 * OpenRegister AuthorizationCacheInvalidationListener
 *
 * Enforces the invariant `PermissionHandler::clearInheritFromPublicCache()` has
 * always DOCUMENTED and nothing has ever CALLED:
 *
 *   "Any path that mutates authorization and then re-reads it within the same
 *    request must bust this cache to avoid serving a stale verdict."
 *
 * Both of PermissionHandler's per-request memos key on identity alone and carry
 * no fingerprint of the policy they memoised:
 *
 *   - `cachedInheritFromPublic` keys purely on schema id.
 *   - `permissionCache` keys on `s<schemaId>|a<action>|u<user>|o<owner>|i<uuid>`.
 *
 * PermissionHandler already refuses to cache the two cases where the AUTHORS saw
 * mid-request mutation coming — a schema carrying `match` rules, and an object
 * carrying its own `_authorization` override. The case left open is the one this
 * listener closes: an administrator edits a SCHEMA's or a REGISTER's
 * authorization block, and anything later in that same request re-reads the
 * verdict computed against the policy that no longer exists. Because
 * PermissionHandler is autowired, the container hands the listener the very
 * instance holding those memos, so evicting here evicts for the whole request.
 *
 * Register events clear the WHOLE inheritFromPublic map rather than one entry.
 * A register's authorization is the fallback for every schema under it and the
 * map keys on schema id only, so there is no subset that could be evicted
 * correctly — clearing everything is the only sound answer, and the cost is one
 * recompute on a rare administrative write.
 *
 * Eviction is UNCONDITIONAL rather than conditional on the authorization block
 * having changed. A conditional evict is only as correct as one's enumeration of
 * every schema field a verdict reads, and getting that enumeration wrong fails
 * OPEN — it serves the stale grant. Dropping a per-request memo on an
 * administrative write costs a recomputation; keeping a stale one costs a wrong
 * authorization answer.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Listener
 * @package  OCA\OpenRegister\Listener
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/rbac-scopes/spec.md#requirement-scope-caching-for-performance
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Listener;

use OCA\OpenRegister\Event\RegisterDeletedEvent;
use OCA\OpenRegister\Event\RegisterUpdatedEvent;
use OCA\OpenRegister\Event\SchemaDeletedEvent;
use OCA\OpenRegister\Event\SchemaUpdatedEvent;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * Evicts PermissionHandler's per-request authorization memos when the policy
 * behind them is rewritten.
 *
 * @template-implements IEventListener<SchemaUpdatedEvent|SchemaDeletedEvent|RegisterUpdatedEvent|RegisterDeletedEvent>
 */
class AuthorizationCacheInvalidationListener implements IEventListener
{
    /**
     * Wire the RBAC evaluator whose memos are evicted.
     *
     * @param PermissionHandler $permissionHandler The evaluator holding the per-request memos.
     *
     * @return void
     *
     * @spec openspec/specs/rbac-scopes/spec.md#requirement-scope-caching-for-performance
     */
    public function __construct(
        private readonly PermissionHandler $permissionHandler
    ) {

    }//end __construct()

    /**
     * Evict whatever the mutated entity could have been memoised under.
     *
     * @param Event $event Inbound dispatcher event.
     *
     * @return void
     *
     * @spec openspec/specs/rbac-scopes/spec.md#requirement-scope-caching-for-performance
     */
    public function handle(Event $event): void
    {
        if ($event instanceof SchemaUpdatedEvent === true) {
            $this->evictSchema(schemaId: $event->getNewSchema()->getId());
            return;
        }

        if ($event instanceof SchemaDeletedEvent === true) {
            $this->evictSchema(schemaId: $event->getSchema()->getId());
            return;
        }

        if ($event instanceof RegisterUpdatedEvent === true || $event instanceof RegisterDeletedEvent === true) {
            $this->evictEverything();
        }

    }//end handle()

    /**
     * Evict one schema's inheritance verdict plus every memoised permission verdict.
     *
     * The permission memo has no per-schema eviction: its keys are opaque
     * strings and there is no index from schema id to key. Clearing it whole is
     * the honest option — a partial evict that missed a key would leave exactly
     * the stale grant this listener exists to prevent.
     *
     * A null id is passed straight through, which `clearInheritFromPublicCache()`
     * reads as "clear all". That is the fail-SAFE direction on purpose: an
     * unidentifiable schema means we cannot know which entry went stale, and
     * over-evicting costs a recompute where under-evicting costs a wrong verdict.
     *
     * @param int|null $schemaId The mutated schema's id, or null when it has none.
     *
     * @return void
     *
     * @spec openspec/specs/rbac-scopes/spec.md#requirement-scope-caching-for-performance
     */
    private function evictSchema(?int $schemaId): void
    {
        $this->permissionHandler->clearInheritFromPublicCache(schemaId: $schemaId);
        $this->permissionHandler->clearPermissionCache();

    }//end evictSchema()

    /**
     * Evict both memos completely.
     *
     * @return void
     *
     * @spec openspec/specs/rbac-scopes/spec.md#requirement-scope-caching-for-performance
     */
    private function evictEverything(): void
    {
        $this->permissionHandler->clearInheritFromPublicCache();
        $this->permissionHandler->clearPermissionCache();

    }//end evictEverything()
}//end class
