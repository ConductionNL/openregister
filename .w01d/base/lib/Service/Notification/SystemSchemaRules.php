<?php

/**
 * OpenRegister SystemSchemaRules
 *
 * Provides the in-code x-openregister-notifications rules for OpenRegister's own
 * system schemas (register, schema, configuration, source, agent, webhook). The
 * dispatcher resolves these through the same annotation-sourced path it uses for
 * stored register objects; no separate notification-rule table is introduced.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Notification
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/openregister-system-notifications/tasks.md#task-2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Notification;

use OCA\OpenRegister\Db\Schema;

/**
 * System-schema rule registry — option (b) from the design doc.
 *
 * Holds x-openregister-notifications rules for each system entity type and
 * produces a synthetic Schema entity carrying those rules so the existing
 * AnnotationNotificationDispatcher path works without modification.
 *
 * @spec openspec/changes/openregister-system-notifications/tasks.md#task-2
 */
class SystemSchemaRules {

	/**
	 * Canonical slugs for each system schema.
	 */
	public const SLUG_REGISTER = 'openregister_register';
	public const SLUG_SCHEMA = 'openregister_schema';
	public const SLUG_CONFIGURATION = 'openregister_configuration';
	public const SLUG_SOURCE = 'openregister_source';
	public const SLUG_AGENT = 'openregister_agent';
	public const SLUG_WEBHOOK = 'openregister_webhook';

	/**
	 * All declared system-schema rules, keyed by canonical slug.
	 *
	 * Rules follow the same x-openregister-notifications schema as stored-object
	 * schemas so the dispatcher evaluates them identically.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private const RULES = [
		self::SLUG_REGISTER => [
			'register-changed' => [
				'trigger' => ['type' => 'updated'],
				'recipients' => [['kind' => 'groups', 'groups' => ['admin']]],
				'channels' => ['nc-notification'],
				'subject' => [
					'nl' => 'Register "{{title}}" is bijgewerkt',
					'en' => 'Register "{{title}}" was updated',
				],
			],
		],
		self::SLUG_SCHEMA => [
			'schema-changed' => [
				'trigger' => ['type' => 'updated'],
				'recipients' => [['kind' => 'groups', 'groups' => ['admin']]],
				'channels' => ['nc-notification'],
				'subject' => [
					'nl' => 'Schema "{{title}}" is bijgewerkt',
					'en' => 'Schema "{{title}}" was updated',
				],
			],
		],
		self::SLUG_CONFIGURATION => [
			'configuration-changed' => [
				'trigger' => ['type' => 'updated'],
				'recipients' => [['kind' => 'groups', 'groups' => ['admin']]],
				'channels' => ['nc-notification'],
				'subject' => [
					'nl' => 'Configuratie "{{title}}" is bijgewerkt',
					'en' => 'Configuration "{{title}}" was updated',
				],
			],
		],
		self::SLUG_SOURCE => [
			'source-unhealthy' => [
				'trigger' => [
					'type' => 'updated',
					'condition' => [
						'field' => 'status',
						'operator' => 'equals',
						'value' => 'error',
					],
				],
				'recipients' => [['kind' => 'groups', 'groups' => ['admin']]],
				'channels' => ['nc-notification'],
				'subject' => [
					'nl' => 'Bron "{{title}}" is niet beschikbaar',
					'en' => 'Source "{{title}}" is unhealthy',
				],
			],
			'source-changed' => [
				'trigger' => ['type' => 'updated'],
				'recipients' => [['kind' => 'groups', 'groups' => ['admin']]],
				'channels' => ['nc-notification'],
				'subject' => [
					'nl' => 'Bron "{{title}}" is bijgewerkt',
					'en' => 'Source "{{title}}" was updated',
				],
			],
		],
		self::SLUG_AGENT => [
			'agent-unhealthy' => [
				'trigger' => [
					'type' => 'updated',
					'condition' => [
						'field' => 'status',
						'operator' => 'equals',
						'value' => 'error',
					],
				],
				'recipients' => [['kind' => 'groups', 'groups' => ['admin']]],
				'channels' => ['nc-notification'],
				'subject' => [
					'nl' => 'Agent "{{name}}" is niet beschikbaar',
					'en' => 'Agent "{{name}}" is unhealthy',
				],
			],
			'agent-changed' => [
				'trigger' => ['type' => 'updated'],
				'recipients' => [['kind' => 'groups', 'groups' => ['admin']]],
				'channels' => ['nc-notification'],
				'subject' => [
					'nl' => 'Agent "{{name}}" is bijgewerkt',
					'en' => 'Agent "{{name}}" was updated',
				],
			],
		],
		self::SLUG_WEBHOOK => [
			'webhook-changed' => [
				'trigger' => ['type' => 'updated'],
				'recipients' => [['kind' => 'groups', 'groups' => ['admin']]],
				'channels' => ['nc-notification'],
				'subject' => [
					'nl' => 'Webhook "{{name}}" is bijgewerkt',
					'en' => 'Webhook "{{name}}" was updated',
				],
			],
		],
	];

	/**
	 * Return the declared rules for a given system schema slug, or null when
	 * the slug is not a known system schema.
	 *
	 * @param string $slug One of the SLUG_* constants.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/openregister-system-notifications/tasks.md#task-2
	 */
	public function getRules(string $slug): ?array {
		return self::RULES[$slug] ?? null;
	}//end getRules()

	/**
	 * Return all known system schema slugs.
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/changes/openregister-system-notifications/tasks.md#task-1
	 */
	public function getSlugs(): array {
		return array_keys(self::RULES);
	}//end getSlugs()

	/**
	 * Build a synthetic Schema entity carrying the declared rules for the
	 * given system slug. The dispatcher reads the rules from
	 * $schema->getConfiguration()['x-openregister-notifications'], so we
	 * embed them there — no DB row needed.
	 *
	 * Returns null when $slug is not a known system schema.
	 *
	 * @param string $slug The canonical system schema slug.
	 *
	 * @return Schema|null
	 *
	 * @spec openspec/changes/openregister-system-notifications/tasks.md#task-2
	 */
	public function buildSchema(string $slug): ?Schema {
		$rules = $this->getRules(slug: $slug);
		if ($rules === null) {
			return null;
		}

		$schema = new Schema();
		$schema->setSlug($slug);
		$schema->setTitle($slug);
		$schema->setConfiguration(['x-openregister-notifications' => $rules]);

		return $schema;
	}//end buildSchema()
}//end class
