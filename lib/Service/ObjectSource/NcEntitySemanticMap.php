<?php

/**
 * NcEntitySemanticMap — the canonical mapping of Nextcloud entity kinds onto the
 * virtual OpenRegister schema that projects them.
 *
 * Each row ties a Nextcloud entity type (user, group, …) to (a) the virtual
 * `register`/`schema` slugs it is seeded under, (b) the schema.org CURIE that
 * becomes the schema's `x-schema-org` marker (feeding the single
 * {@see \OCA\OpenRegister\Service\JsonLd\JsonLdContextService::getImplementedTypes()}
 * → {@see \OCA\OpenRegister\Service\SemanticTypeResolver} path — no parallel
 * resolution branch), (c) the id of the read-only ObjectSourceProvider that
 * serves its objects live, and (d) the Nextcloud app that must be installed for
 * the provider to be usable (`null` = Nextcloud core, always available).
 *
 * The seed step (see {@see \OCA\OpenRegister\Repair\SeedDirectoryVirtualSchemas})
 * materialises one virtual register + schema per row. This first slice ships the
 * two always-available core rows (user → Person, group → Organization); the
 * commented follow-on rows are the planned next providers, each a separate change
 * that reuses the Integration Registry read code (see tasks.md §5.1).
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ObjectSource
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
 * @spec openspec/changes/virtual-schema-semantic-providers/tasks.md#task-2.1
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ObjectSource;

/**
 * Static registry of Nextcloud-entity → virtual-schema semantic mappings.
 */
final class NcEntitySemanticMap
{

    /**
     * The virtual register slug every core NC-entity schema is seeded under.
     *
     * @var string
     */
    public const DIRECTORY_REGISTER = 'directory';

    /**
     * Canonical NC-entity → virtual-schema rows.
     *
     * Row shape:
     *  - `register`    — virtual register slug (core rows live in `directory`; each
     *                    app-gated row lives in its own app-named register so the
     *                    ADR-048 app-enabled gate degrades it when the app is gone);
     *  - `schema`      — virtual schema slug (always `nc-`-prefixed so it never
     *                    collides with a leaf-app schema of the same bare name);
     *  - `schemaOrg`   — schema.org CURIE → the schema's `x-schema-org` marker;
     *  - `provider`    — the ObjectSourceProvider id that serves its objects;
     *  - `requiredApp` — the NC app that must be installed for the provider to be
     *                    usable (`null` = Nextcloud core, always available);
     *  - `application` — the register's `application` (drives the ADR-048 gate).
     *
     * @var array<string, array{register: string, schema: string, schemaOrg: string, provider: string, requiredApp: string|null, application: string}>
     */
    public const ENTITIES = [
        'user'    => [
            'register'    => self::DIRECTORY_REGISTER,
            'schema'      => 'nc-user',
            'schemaOrg'   => 'schema:Person',
            'provider'    => 'user-directory-source',
            'requiredApp' => null,
            'application' => 'openregister',
        ],
        'group'   => [
            'register'    => self::DIRECTORY_REGISTER,
            'schema'      => 'nc-group',
            'schemaOrg'   => 'schema:Organization',
            'provider'    => 'group-source',
            'requiredApp' => null,
            'application' => 'openregister',
        ],
        // App-gated rows — each lives on its OWN app-named register (application =
        // register slug) so the ADR-048 app-enabled gate degrades the projection
        // when the backing app is uninstalled. Schemas are `nc-`-prefixed to avoid
        // colliding with same-named leaf-app schemas (e.g. `contact`, `event`).
        'contact' => [
            'register'    => 'contacts',
            'schema'      => 'nc-contact',
            'schemaOrg'   => 'schema:Person',
            'provider'    => 'contacts-source',
            'requiredApp' => 'contacts',
            'application' => 'contacts',
        ],
        'event'   => [
            'register'    => 'calendar',
            'schema'      => 'nc-event',
            'schemaOrg'   => 'schema:Event',
            'provider'    => 'calendar-event-source',
            'requiredApp' => 'calendar',
            'application' => 'calendar',
        ],
        'file'    => [
            'register'    => 'files',
            'schema'      => 'nc-file',
            'schemaOrg'   => 'schema:DigitalDocument',
            'provider'    => 'files-source',
            'requiredApp' => null,
            'application' => 'files',
        ],
        'card'    => [
            'register'    => 'deck',
            'schema'      => 'nc-card',
            'schemaOrg'   => 'schema:Action',
            'provider'    => 'deck-source',
            'requiredApp' => 'deck',
            'application' => 'deck',
        ],
        'talk'    => [
            'register'    => 'talk',
            'schema'      => 'nc-conversation',
            'schemaOrg'   => 'schema:Conversation',
            'provider'    => 'talk-source',
            'requiredApp' => 'spreed',
            'application' => 'spreed',
        ],
        // Tasks reuse the EXISTING `caldav-vtodo` provider (no new provider
        // class) — VTODOs are served from the core `dav` app, so the register's
        // `application` gates on the optional `tasks` UI app.
        'task'    => [
            'register'    => 'tasks',
            'schema'      => 'nc-task',
            'schemaOrg'   => 'schema:Action',
            'provider'    => 'caldav-vtodo',
            'requiredApp' => 'tasks',
            'application' => 'tasks',
        ],
    ];

    /**
     * Not instantiable — this is a static map only.
     */
    private function __construct()
    {
    }//end __construct()
}//end class
