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
     *  - `register`    — virtual register slug (all core rows live in `directory`);
     *  - `schema`      — virtual schema slug;
     *  - `schemaOrg`   — schema.org CURIE → the schema's `x-schema-org` marker;
     *  - `provider`    — the ObjectSourceProvider id that serves its objects;
     *  - `requiredApp` — the NC app that must be installed (`null` = core).
     *
     * @var array<string, array{register: string, schema: string, schemaOrg: string, provider: string, requiredApp: string|null}>
     */
    public const ENTITIES = [
        'user'  => [
            'register'    => self::DIRECTORY_REGISTER,
            'schema'      => 'nc-user',
            'schemaOrg'   => 'schema:Person',
            'provider'    => 'user-directory-source',
            'requiredApp' => null,
        ],
        'group' => [
            'register'    => self::DIRECTORY_REGISTER,
            'schema'      => 'nc-group',
            'schemaOrg'   => 'schema:Organization',
            'provider'    => 'group-source',
            'requiredApp' => null,
        ],
        // Follow-on rows (each ships in its own change — see tasks.md §5.1):
        // contact → nc-contact / schema:Person via contacts-source (app contacts).
        // event → nc-event / schema:Event via calendar-source (app calendar).
        // file → nc-file / schema:DigitalDocument via files-source (core).
        // deck → nc-card / schema:Action via deck-source (app deck).
        // talk → nc-room / schema:Conversation via talk-source (app spreed).
    ];

    /**
     * Not instantiable — this is a static map only.
     */
    private function __construct()
    {
    }//end __construct()
}//end class
