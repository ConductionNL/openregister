<?php

/**
 * OpenRegister RecipientResolverInterface
 *
 * Public contract apps implement to provide dynamic recipient resolution
 * for the `expression` notification recipient kind. Apps register their
 * implementation under a DI tag; the schema's notification annotation
 * references the tag via `{kind: "expression", resolver: "<tag>"}`.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Notification
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Notification;

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * Apps implement this interface to dynamically resolve recipient uids
 * for an `expression` recipient declaration.
 *
 * Resolvers are read-only — they MUST NOT mutate the object. They MAY
 * call back into the OR API or other Nextcloud services, but they are
 * called inline on every notification dispatch, so they should be fast
 * (sub-100ms) or coordinate their own caching.
 */
interface RecipientResolverInterface
{
    /**
     * Resolve the recipient uids for a notification dispatch.
     *
     * @param ObjectEntity         $object  The object the event happened on.
     * @param array<string, mixed> $context Trigger-specific extras (action, from, to, aggregation, ...).
     *
     * @return array<int, string> List of Nextcloud uids.
     */
    public function resolve(ObjectEntity $object, array $context): array;
}//end interface
