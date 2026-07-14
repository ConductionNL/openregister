<?php

/**
 * OpenRegister Webhook Interception Cache
 *
 * Distributed-cache flag answering "does ANY interception webhook exist for
 * this event type?" so the common zero-webhook case skips the per-write
 * webhook table scan entirely.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Webhook
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Webhook;

use OCP\ICache;
use OCP\ICacheFactory;

/**
 * WebhookInterceptionCache caches the "has interception webhooks" flag
 *
 * Object create/update requests must ask "is any webhook configured to
 * intercept this event?" before saving. Without a cache that question costs
 * a webhook table scan on EVERY write, even on installs with zero webhooks.
 * This class stores a per-event-type boolean in the distributed cache so
 * the zero-webhook common case costs a cache read instead of a DB query.
 *
 * IMPORTANT — the flag is deliberately tenant-agnostic: it answers whether
 * ANY enabled interception webhook exists for the event type across ALL
 * organisations. A per-organisation "false" here would let one tenant's
 * cache entry silently disable another tenant's interception hooks. When
 * the global flag is true, the caller still runs the organisation-filtered
 * lookup to select the webhooks that actually apply.
 *
 * Invalidation: every webhook insert/update/delete clears the flags (see
 * WebhookMapper). A TTL bounds staleness across nodes that miss an
 * invalidation (e.g. cache backends without distributed clear).
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Webhook
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 */
class WebhookInterceptionCache
{
    /**
     * Cache TTL for the has-interception-webhooks flag, in seconds.
     *
     * Bounds staleness when an invalidation is missed; webhook CRUD clears
     * the flags eagerly so this is a safety net, not the primary mechanism.
     *
     * @var integer
     */
    private const CACHE_TTL_SECONDS = 300;

    /**
     * Cache key prefix for per-event-type flags.
     *
     * @var string
     */
    private const KEY_PREFIX = 'has-interception-webhooks:';

    /**
     * Distributed cache instance scoped to webhook interception flags.
     *
     * @var ICache
     */
    private ICache $cache;

    /**
     * Constructor
     *
     * @param ICacheFactory $cacheFactory Cache factory used to create the distributed cache.
     *
     * @return void
     */
    public function __construct(ICacheFactory $cacheFactory)
    {
        $this->cache = $cacheFactory->createDistributed('openregister-webhook-interception');
    }//end __construct()

    /**
     * Get the cached "has interception webhooks" flag for an event type.
     *
     * @param string $eventType Event type (e.g. 'object.creating').
     *
     * @return bool|null True/false when cached, null on cache miss.
     *
     * @spec openspec/specs/webhook-payload-mapping/spec.md#request-interception-pre-event-webhooks
     */
    public function get(string $eventType): ?bool
    {
        $value = $this->cache->get(self::KEY_PREFIX.$eventType);
        if ($value === null) {
            return null;
        }

        return (bool) $value;
    }//end get()

    /**
     * Store the "has interception webhooks" flag for an event type.
     *
     * @param string $eventType   Event type (e.g. 'object.creating').
     * @param bool   $hasWebhooks Whether any enabled interception webhook exists for the event type.
     *
     * @return void
     *
     * @spec openspec/specs/webhook-payload-mapping/spec.md#request-interception-pre-event-webhooks
     */
    public function set(string $eventType, bool $hasWebhooks): void
    {
        // ICache values must be scalars; store as int for backend portability.
        $this->cache->set(self::KEY_PREFIX.$eventType, (int) $hasWebhooks, self::CACHE_TTL_SECONDS);
    }//end set()

    /**
     * Invalidate all cached flags.
     *
     * Called on every webhook insert/update/delete — any CRUD can change
     * which event types have interception webhooks, so all flags are cleared.
     *
     * @return void
     *
     * @spec openspec/specs/webhook-payload-mapping/spec.md#request-interception-pre-event-webhooks
     */
    public function invalidate(): void
    {
        $this->cache->clear(self::KEY_PREFIX);
    }//end invalidate()
}//end class
