<?php

/**
 * OpenRegister System Schema Notification Registry
 *
 * Holds the x-openregister-notifications rules for OpenRegister's own system
 * entity types (register, schema, configuration, source, agent, webhook).
 * Because system entities are plain OCP\AppFramework\Db\Entity records that do
 * NOT flow through ObjectCreatedEvent / ObjectUpdatedEvent, the dispatcher
 * consults this in-code registry instead of reading a Schema row from the DB.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/openregister-system-notifications/tasks.md#task-2.1
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

/**
 * Registry of x-openregister-notifications rules for OpenRegister system schemas.
 *
 * Each system entity type maps to an array of rule descriptors that follow the
 * same shape as the x-openregister-notifications annotation on a stored Schema:
 *
 * [
 *   'trigger'    => 'updated'|'created'|'deleted',
 *   'condition'  => null | ['field'=>..., 'operator'=>..., 'value'=>...],
 *   'recipients' => [ ['kind'=>'groups', 'groups'=>[...]] ],
 *   'subject'    => ['nl'=>'...', 'en'=>'...'],
 * ]
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */
class SystemSchemaNotificationRegistry
{

    /**
     * Canonical slugs for OpenRegister system entity types.
     * Used by AnnotationNotificationListener to identify events.
     *
     * @var array<string> ENTITY_TYPES
     */
    public const ENTITY_TYPES = [
        'register',
        'schema',
        'configuration',
        'source',
        'agent',
        'webhook',
    ];

    /**
     * Returns the x-openregister-notifications rules for a system entity type.
     *
     * Rules follow the same shape as the annotation on a stored Schema.
     * Returns an empty array when no rules are declared for the given type.
     *
     * @param string $entityType Canonical system entity type slug (e.g. 'schema').
     *
     * @return array<int, array<string, mixed>> Array of rule descriptors.
     *
     * @spec openspec/changes/openregister-system-notifications/tasks.md#task-2.1
     * @spec openspec/changes/openregister-system-notifications/tasks.md#task-2.2
     */
    public function getRulesForEntityType(string $entityType): array
    {
        $allRules = $this->buildAllRules();
        return $allRules[$entityType] ?? [];
    }//end getRulesForEntityType()

    /**
     * Returns all registered system entity types.
     *
     * @return array<string> List of supported entity type slugs.
     *
     * @spec openspec/changes/openregister-system-notifications/tasks.md#task-1.3
     */
    public function getEntityTypes(): array
    {
        return self::ENTITY_TYPES;
    }//end getEntityTypes()

    /**
     * Build the full rules map for all system entity types.
     *
     * Each entry follows the x-openregister-notifications rule shape.
     * Subjects are bilingual (nl / en) and metadata-only — no payload contents.
     *
     * @return array<string, array<int, array<string, mixed>>>
     *
     * @spec openspec/changes/openregister-system-notifications/tasks.md#task-2.2
     * @spec openspec/changes/openregister-system-notifications/tasks.md#task-4.1
     */
    private function buildAllRules(): array
    {
        return [
            // Register changed — notify admin group.
            'register'      => [
                [
                    'trigger'    => 'updated',
                    'condition'  => null,
                    'recipients' => [
                        ['kind' => 'groups', 'groups' => ['admin']],
                    ],
                    'subject'    => [
                        'nl' => 'Register gewijzigd: {{title}}',
                        'en' => 'Register changed: {{title}}',
                    ],
                ],
            ],

            // Schema changed — notify admin group.
            'schema'        => [
                [
                    'trigger'    => 'updated',
                    'condition'  => null,
                    'recipients' => [
                        ['kind' => 'groups', 'groups' => ['admin']],
                    ],
                    'subject'    => [
                        'nl' => 'Schema gewijzigd: {{title}}',
                        'en' => 'Schema changed: {{title}}',
                    ],
                ],
            ],

            // Configuration changed — notify admin group.
            'configuration' => [
                [
                    'trigger'    => 'updated',
                    'condition'  => null,
                    'recipients' => [
                        ['kind' => 'groups', 'groups' => ['admin']],
                    ],
                    'subject'    => [
                        'nl' => 'Configuratie gewijzigd: {{title}}',
                        'en' => 'Configuration changed: {{title}}',
                    ],
                ],
                // Sync failure via condition on syncStatus field.
                [
                    'trigger'    => 'updated',
                    'condition'  => [
                        'field'    => 'syncStatus',
                        'operator' => 'equals',
                        'value'    => 'failed',
                    ],
                    'recipients' => [
                        ['kind' => 'groups', 'groups' => ['admin']],
                    ],
                    'subject'    => [
                        'nl' => 'Synchronisatie mislukt: {{title}}',
                        'en' => 'Synchronization failed: {{title}}',
                    ],
                ],
            ],

            // Source updated — notify admin group on any update.
            'source'        => [
                [
                    'trigger'    => 'updated',
                    'condition'  => null,
                    'recipients' => [
                        ['kind' => 'groups', 'groups' => ['admin']],
                    ],
                    'subject'    => [
                        'nl' => 'Bron gewijzigd: {{title}}',
                        'en' => 'Source changed: {{title}}',
                    ],
                ],
            ],

            // Agent updated — notify admin group on any update.
            'agent'         => [
                [
                    'trigger'    => 'updated',
                    'condition'  => null,
                    'recipients' => [
                        ['kind' => 'groups', 'groups' => ['admin']],
                    ],
                    'subject'    => [
                        'nl' => 'Agent gewijzigd: {{name}}',
                        'en' => 'Agent changed: {{name}}',
                    ],
                ],
            ],

            // Webhook updated — notify admin group.
            'webhook'       => [
                [
                    'trigger'    => 'updated',
                    'condition'  => null,
                    'recipients' => [
                        ['kind' => 'groups', 'groups' => ['admin']],
                    ],
                    'subject'    => [
                        'nl' => 'Webhook gewijzigd: {{name}}',
                        'en' => 'Webhook changed: {{name}}',
                    ],
                ],
            ],
        ];
    }//end buildAllRules()
}//end class
