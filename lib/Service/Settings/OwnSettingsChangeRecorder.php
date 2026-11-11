<?php

/**
 * Records and announces a change to Open Register's own settings.
 *
 * The leaf app settings and the feature toggles already write a
 * `settings.updated` row through the SettingsChangeAuditor. Open Register's own
 * settings did not: the per-section saves the settings screens use (RBAC,
 * multitenancy, organisation, object, retention, archival) neither recorded
 * nor announced, and the full save only announced. So the settings that decide
 * who may read what were the ones the audit trail did not mention.
 *
 * Every door takes a snapshot before its write and hands it back after, and
 * this class does the rest in one place: the per-key diff goes to the auditor
 * (secrets masked there), and the security-marked settings go to the
 * announcer. The settings are stored as JSON blobs per section, so a snapshot
 * flattens each blob into `section.field` keys (nested objects become
 * `section.object.field`), which makes a row name the one field that moved and
 * lets a nested credential such as `llm.openaiConfig.apiKey` be masked.
 *
 * NEVER THROWS. The setting is stored by the time this runs; a failure here
 * must not turn a save that happened into a reported failure.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/settings-change-audit/specs/audit-trail-immutable/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Settings;

use OCA\OpenRegister\Service\Rbac\SettingsChangeAuditor;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Snapshot, diff, record and announce for Open Register's own settings.
 *
 * @spec openspec/changes/settings-change-audit/specs/audit-trail-immutable/spec.md
 */
class OwnSettingsChangeRecorder {

	/**
	 * The app whose settings these are, as the audit row names it.
	 *
	 * @var string
	 */
	public const APP = 'openregister';

	/**
	 * A key prefixed with this is a plain app config value, not a JSON blob.
	 *
	 * @var string
	 */
	public const PLAIN_PREFIX = '@';

	/**
	 * What the full settings save (`PUT /api/settings`) can write.
	 *
	 * @var array<int, string>
	 */
	public const FULL_SAVE_KEYS = [
		'rbac',
		'multitenancy',
		'organisation',
		'retention',
		'@flow_run_retention_days',
		'@flow_audit_enabled',
		'@flow_kill_switch',
	];

	/**
	 * Constructor.
	 *
	 * @param IAppConfig         $appConfig  The stored settings.
	 * @param SettingsAuditTrail $auditTrail The auditor, the secret registry and the announcer.
	 * @param LoggerInterface    $logger     Reports a failed snapshot.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly SettingsAuditTrail $auditTrail,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The settings a door is about to write, as they stand now.
	 *
	 * @param array<int, string> $keys The app config keys the door writes.
	 *
	 * @return array{settings: array<string, mixed>, security: array<string, mixed>} The snapshot.
	 *
	 * @spec openspec/changes/settings-change-audit/specs/audit-trail-immutable/spec.md
	 */
	public function snapshot(array $keys): array {
		$security = [];
		if ($this->auditTrail->announcer !== null) {
			$security = $this->auditTrail->announcer->snapshot();
		}

		return [
			'settings' => $this->read(keys: $keys),
			'security' => $security,
		];
	}//end snapshot()

	/**
	 * Record and announce what moved since the snapshot.
	 *
	 * @param array{settings: array<string, mixed>, security: array<string, mixed>} $before The snapshot taken before the write.
	 * @param array<int, string>                                                    $keys   The same keys the snapshot read.
	 *
	 * @return int How many audit rows were written.
	 *
	 * @spec openspec/changes/settings-change-audit/specs/audit-trail-immutable/spec.md
	 */
	public function record(array $before, array $keys): int {
		$written = 0;
		try {
			$written = $this->recordRows(before: $before['settings'], after: $this->read(keys: $keys));
			$this->announceSecurity(before: $before['security']);
		} catch (Throwable $e) {
			$this->logger->error(
				message: '[OwnSettingsChangeRecorder] A settings change was stored but not recorded: ' . $e->getMessage(),
				context: ['app' => self::APP]
			);
		}

		return $written;
	}//end record()

	/**
	 * Write the audit rows for what moved, with the secret keys masked.
	 *
	 * @param array<string, mixed> $before The settings before the write.
	 * @param array<string, mixed> $after  The settings after the write.
	 *
	 * @return int How many audit rows were written.
	 */
	private function recordRows(array $before, array $after): int {
		return $this->auditTrail->auditor->recordUpdate(
			app: self::APP,
			before: $before,
			after: $after,
			secretKeys: $this->secretKeysIn(before: $before, after: $after)
		);
	}//end recordRows()

	/**
	 * The keys on either side that the registry declares secret.
	 *
	 * @param array<string, mixed> $before The settings before the write.
	 * @param array<string, mixed> $after  The settings after the write.
	 *
	 * @return array<int, string> The secret keys.
	 */
	private function secretKeysIn(array $before, array $after): array {
		$secretKeys = [];
		foreach (array_keys(array_merge($before, $after)) as $key) {
			if ($this->auditTrail->registry->isSecret(path: (string) $key) === true) {
				$secretKeys[] = (string) $key;
			}
		}

		return $secretKeys;
	}//end secretKeysIn()

	/**
	 * Tell the announcer what moved in the security settings, when there is one.
	 *
	 * @param array<string, mixed> $before The security snapshot taken before the write.
	 *
	 * @return void
	 */
	private function announceSecurity(array $before): void {
		if ($this->auditTrail->announcer !== null) {
			$this->auditTrail->announcer->announce(before: $before, after: $this->auditTrail->announcer->snapshot());
		}
	}//end announceSecurity()

	/**
	 * Read the given keys, flattened to one entry per field.
	 *
	 * @param array<int, string> $keys The app config keys.
	 *
	 * @return array<string, mixed> Flat key to value.
	 */
	private function read(array $keys): array {
		$values = [];
		foreach ($keys as $key) {
			try {
				$values = array_replace($values, $this->valuesOf(key: $key));
			} catch (Throwable $e) {
				$this->logger->warning(
					message: '[OwnSettingsChangeRecorder] Could not read setting ' . $key . ': ' . $e->getMessage(),
					context: ['app' => self::APP]
				);
			}
		}

		return $values;
	}//end read()

	/**
	 * One key's flat entries: a plain value by its own name, a JSON object flattened under the key, else the raw string.
	 *
	 * @param string $key The app config key, with the plain prefix for a plain value.
	 *
	 * @return array<string, mixed> Flat key to value; empty when unset.
	 *
	 * @throws Throwable When the value cannot be read.
	 */
	private function valuesOf(string $key): array {
		if (str_starts_with($key, self::PLAIN_PREFIX) === true) {
			return $this->plainValue(key: substr($key, strlen(self::PLAIN_PREFIX)));
		}

		$raw = $this->appConfig->getValueString(self::APP, $key, '');
		if ($raw === '') {
			return [];
		}

		$decoded = json_decode($raw, true);
		if (is_array($decoded) === false) {
			return [$key => $raw];
		}

		return $this->flatten(prefix: $key, data: $decoded);
	}//end valuesOf()

	/**
	 * A plain app config value by its own name, or nothing when the key is not set.
	 *
	 * @param string $key The app config key, without the plain prefix.
	 *
	 * @return array<string, mixed> The value by its key; empty when unset.
	 *
	 * @throws Throwable When the value cannot be read.
	 */
	private function plainValue(string $key): array {
		if ($this->appConfig->hasKey(self::APP, $key) === false) {
			return [];
		}

		return [$key => $this->readPlain(key: $key)];
	}//end plainValue()

	/**
	 * Read one plain app config value with the getter its stored type needs.
	 *
	 * A value stored with setValueBool refuses getValueString, so the type is
	 * asked first.
	 *
	 * @param string $key The app config key.
	 *
	 * @return bool|int|string The stored value.
	 */
	private function readPlain(string $key): bool|int|string {
		$type = $this->appConfig->getValueType(self::APP, $key);
		if (($type & IAppConfig::VALUE_BOOL) !== 0) {
			return $this->appConfig->getValueBool(self::APP, $key);
		}

		if (($type & IAppConfig::VALUE_INT) !== 0) {
			return $this->appConfig->getValueInt(self::APP, $key);
		}

		return $this->appConfig->getValueString(self::APP, $key, '');
	}//end readPlain()

	/**
	 * Flatten an associative array into dotted keys; lists stay one value.
	 *
	 * @param string              $prefix The key so far.
	 * @param array<mixed, mixed> $data   The decoded blob.
	 *
	 * @return array<string, mixed> Flat key to value.
	 */
	private function flatten(string $prefix, array $data): array {
		if ($data === [] || array_is_list($data) === true) {
			return [$prefix => $data];
		}

		$flat = [];
		foreach ($data as $field => $value) {
			$path = $prefix . '.' . $field;
			if (is_array($value) === true && $value !== [] && array_is_list($value) === false) {
				$flat = array_merge($flat, $this->flatten(prefix: $path, data: $value));
				continue;
			}

			$flat[$path] = $value;
		}

		return $flat;
	}//end flatten()
}//end class
