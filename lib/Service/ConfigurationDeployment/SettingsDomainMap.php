<?php

/**
 * SettingsDomainMap — which configuration keys a settings domain writes.
 *
 * The settings facade has ten `update*` methods over six handlers, and each
 * one writes one or more app-config keys. To draft a settings write instead of
 * applying it, the draft gate has to know which addresses that write would
 * touch. That is this table, and it is the ONLY thing this table knows.
 *
 * It deliberately does NOT carry the defaults. Every handler fills a missing
 * field from its own default on write, and every getter fills a missing field
 * from the same default on read, so a second copy here would be a second set of
 * defaults that drifts the first time somebody changes one of them. The gate
 * therefore drafts the incoming data merged over the live value, and the
 * getters supply what neither holds. `SettingsDraftGateTest` pins that
 * equivalence against the real handlers.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ConfigurationDeployment
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/configuration-as-a-deployment/specs/settings-management/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ConfigurationDeployment;

/**
 * The settings domains and the addresses each one writes.
 */
final class SettingsDomainMap {

	/**
	 * The domain behind SettingsService::updateSearchBackendConfig().
	 *
	 * @var string
	 */
	public const DOMAIN_SEARCH_BACKEND = 'searchBackend';

	/**
	 * The domain behind SettingsService::updateLLMSettingsOnly().
	 *
	 * @var string
	 */
	public const DOMAIN_LLM = 'llm';

	/**
	 * The domain behind SettingsService::updateFileSettingsOnly().
	 *
	 * @var string
	 */
	public const DOMAIN_FILE = 'fileManagement';

	/**
	 * The domain behind SettingsService::updateObjectSettingsOnly().
	 *
	 * @var string
	 */
	public const DOMAIN_OBJECT = 'objectManagement';

	/**
	 * The domain behind SettingsService::updateRetentionSettingsOnly().
	 *
	 * @var string
	 */
	public const DOMAIN_RETENTION = 'retention';

	/**
	 * The domain behind SettingsService::updateArchivalSettingsOnly().
	 *
	 * @var string
	 */
	public const DOMAIN_ARCHIVAL = 'archival';

	/**
	 * The domain behind SettingsService::updateRbacSettingsOnly().
	 *
	 * @var string
	 */
	public const DOMAIN_RBAC = 'rbac';

	/**
	 * The domain behind SettingsService::updateOrganisationSettingsOnly().
	 *
	 * @var string
	 */
	public const DOMAIN_ORGANISATION = 'organisation';

	/**
	 * The domain behind SettingsService::updateMultitenancySettingsOnly().
	 *
	 * @var string
	 */
	public const DOMAIN_MULTITENANCY = 'multitenancy';

	/**
	 * The domain behind SettingsService::updateSettings(), which is the only
	 * one writing more than one key.
	 *
	 * @var string
	 */
	public const DOMAIN_SETTINGS = 'settings';

	/**
	 * Every domain, and the addresses its write touches.
	 *
	 * An entry with no `section` takes the whole payload as its value. An entry
	 * with a `section` takes that key of the payload. An entry with a `section`
	 * and a `field` takes one scalar out of that section, which is how the flow
	 * and party settings travel: they are stored as loose keys rather than as a
	 * blob, and a draft has to address them the same way a deployment will.
	 *
	 * @var array<string, array<int, array<string, string|null>>>
	 */
	private const DOMAINS = [
		self::DOMAIN_SEARCH_BACKEND => [['key' => 'search_backend']],
		self::DOMAIN_LLM => [['key' => 'llm']],
		self::DOMAIN_FILE => [['key' => 'fileManagement']],
		self::DOMAIN_OBJECT => [['key' => 'objectManagement']],
		self::DOMAIN_RETENTION => [['key' => 'retention']],
		self::DOMAIN_ARCHIVAL => [['key' => 'archival']],
		self::DOMAIN_RBAC => [['key' => 'rbac']],
		self::DOMAIN_ORGANISATION => [['key' => 'organisation']],
		self::DOMAIN_MULTITENANCY => [['key' => 'multitenancy']],
		self::DOMAIN_SETTINGS => [
			['key' => 'rbac', 'section' => 'rbac'],
			['key' => 'multitenancy', 'section' => 'multitenancy'],
			['key' => 'retention', 'section' => 'retention'],
			['key' => 'solr', 'section' => 'solr'],
			['key' => 'flow_run_retention_days', 'section' => 'flow', 'field' => 'retentionDays', 'cast' => 'string'],
			['key' => 'flow_audit_enabled', 'section' => 'flow', 'field' => 'auditEnabled', 'cast' => 'boolean'],
			['key' => 'flow_oversight_enabled', 'section' => 'flow', 'field' => 'oversightEnabled', 'cast' => 'boolean'],
			['key' => 'flow_kill_switch', 'section' => 'flow', 'field' => 'killSwitch', 'cast' => 'boolean'],
			['key' => 'party_query_cap', 'section' => 'party', 'field' => 'queryCap', 'cast' => 'integer'],
		],
	];

	/**
	 * Every domain name the facade routes through the gate.
	 *
	 * @return array<int, string> The domain names.
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/specs/settings-management/spec.md
	 */
	public function domains(): array {
		return array_keys(self::DOMAINS);

	}//end domains()

	/**
	 * Whether this map knows a domain.
	 *
	 * @param string $domain The domain name.
	 *
	 * @return boolean True when the domain is mapped.
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/specs/settings-management/spec.md
	 */
	public function knows(string $domain): bool {
		return isset(self::DOMAINS[$domain]);

	}//end knows()

	/**
	 * The addresses and values one settings write would produce.
	 *
	 * A section the payload does not carry is skipped rather than drafted as
	 * null: the handlers themselves only write a section the payload names, so
	 * drafting an absent section would stage a change the straight-through
	 * write would never have made.
	 *
	 * @param string               $domain  The domain name.
	 * @param array<string, mixed> $payload The data handed to the facade.
	 *
	 * @return array<int, array<string, mixed>> One entry per key, each with
	 *                                          `key`, `value` and `merge`.
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/specs/settings-management/spec.md
	 */
	public function project(string $domain, array $payload): array {
		$projected = [];

		foreach ((self::DOMAINS[$domain] ?? []) as $entry) {
			$section = ($entry['section'] ?? null);
			$field = ($entry['field'] ?? null);

			if ($section === null) {
				$projected[] = ['key' => (string)$entry['key'], 'value' => $payload, 'merge' => true];
				continue;
			}

			if (array_key_exists($section, $payload) === false || is_array($payload[$section]) === false) {
				continue;
			}

			if ($field === null) {
				$projected[] = ['key' => (string)$entry['key'], 'value' => $payload[$section], 'merge' => true];
				continue;
			}

			if (array_key_exists($field, $payload[$section]) === false) {
				continue;
			}

			$projected[] = [
				'key' => (string)$entry['key'],
				'value' => $this->cast(raw: $payload[$section][$field], cast: (string)($entry['cast'] ?? 'string')),
				'merge' => false,
			];
		}//end foreach

		return $projected;

	}//end project()

	/**
	 * Put a loose scalar in the shape its key is declared as.
	 *
	 * @param mixed  $raw  The value out of the payload.
	 * @param string $cast The declared cast.
	 *
	 * @return mixed The cast value.
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/specs/settings-management/spec.md
	 */
	private function cast(mixed $raw, string $cast): mixed {
		return match ($cast) {
			'boolean' => (bool)$raw,
			'integer' => (int)$raw,
			default => (string)$raw,
		};

	}//end cast()
}//end class
