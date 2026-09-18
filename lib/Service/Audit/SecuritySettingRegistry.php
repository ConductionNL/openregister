<?php

/**
 * Which settings are security relevant, and which of them hold a secret.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Audit
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/audit-trail-shipped-and-purpose-bound/specs/enhanced-audit-trail/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Audit;

use OCP\IAppConfig;
use Throwable;

/**
 * The security-relevant marker (D-6), and the snapshot it is compared on.
 *
 * The marker is a list rather than an attribute scattered through the settings
 * code, for the reason Redmine's `security_notifications: 1` is one file: the
 * question "which settings will page the beheerteam" must have one answer an
 * administrator can read, not thirty to find.
 *
 * Each entry names where the value lives, its default, whether it is a secret,
 * and the label the announcement shows. The default matters more than it
 * looks: a setting that was never stored and is then saved with its default
 * value has not changed, and treating "unset" as different from "the default"
 * would page the administrators every time somebody first opens a settings
 * page and clicks save.
 *
 * ⚠️ A SECRET IS DECIDED HERE, NOT GUESSED FROM THE VALUE. {@see isSecret()}
 * also treats any path naming a password, secret, token or key as one, so a
 * credential added to this list without the flag still is not quoted. The
 * fallback exists because the cost of the two mistakes is not symmetric: a
 * non-secret announced as "changed" costs a click, a secret quoted in a
 * notification is a credential stored in somebody's inbox.
 *
 * @spec openspec/changes/audit-trail-shipped-and-purpose-bound/specs/enhanced-audit-trail/spec.md
 */
class SecuritySettingRegistry {
	/**
	 * The app the settings live under.
	 *
	 * @var string
	 */
	private const APP = 'openregister';

	/**
	 * Path fragments that make a setting a secret whatever the flag says.
	 *
	 * @var string[]
	 */
	private const SECRET_FRAGMENTS = ['password', 'secret', 'token', 'apikey', 'api_key', 'privatekey'];

	/**
	 * The marked settings.
	 *
	 * Key: `<blob>.<field>` for a value stored inside a JSON configuration
	 * blob, or `@<appconfig key>` for a value stored under its own key.
	 *
	 * @var array<string, array{label: string, default: mixed, secret: bool, type: string}>
	 */
	public const SETTINGS = [
		'rbac.enabled' => ['label' => 'Access control', 'default' => true, 'secret' => false, 'type' => 'json'],
		'rbac.adminOverride' => ['label' => 'Administrators bypass access control', 'default' => true, 'secret' => false, 'type' => 'json'],
		'rbac.anonymousGroup' => ['label' => 'Group for anonymous visitors', 'default' => 'public', 'secret' => false, 'type' => 'json'],
		'rbac.defaultNewUserGroup' => ['label' => 'Group for new users', 'default' => 'viewer', 'secret' => false, 'type' => 'json'],
		'multitenancy.enabled' => ['label' => 'Separation between organisations', 'default' => true, 'secret' => false, 'type' => 'json'],
		'multitenancy.adminOverride' => ['label' => 'Administrators see every organisation', 'default' => true, 'secret' => false, 'type' => 'json'],
		'multitenancy.publishedObjectsBypassMultiTenancy' => ['label' => 'Published records visible to every organisation', 'default' => false, 'secret' => false, 'type' => 'json'],
		'retention.auditTrailsEnabled' => ['label' => 'Audit trail', 'default' => true, 'secret' => false, 'type' => 'json'],
		'retention.searchTrailsEnabled' => ['label' => 'Search trail', 'default' => true, 'secret' => false, 'type' => 'json'],
		'solr.username' => ['label' => 'Search index user name', 'default' => 'solr', 'secret' => false, 'type' => 'json'],
		'solr.password' => ['label' => 'Search index password', 'default' => 'SolrRocks', 'secret' => true, 'type' => 'json'],
		'solr.zookeeperPassword' => ['label' => 'Search cluster password', 'default' => '', 'secret' => true, 'type' => 'json'],
		'@flow_audit_enabled' => ['label' => 'Audit trail for automated flows', 'default' => false, 'secret' => false, 'type' => 'bool'],
		'@flow_oversight_enabled' => ['label' => 'Oversight of automated flows', 'default' => true, 'secret' => false, 'type' => 'bool'],
		'@flow_kill_switch' => ['label' => 'Emergency stop for automated flows', 'default' => false, 'secret' => false, 'type' => 'bool'],
		'@' . AuditSink::CONFIG_PATH => ['label' => 'Audit trail file location', 'default' => '', 'secret' => false, 'type' => 'string'],
	];

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig Where the settings are stored.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
	) {
	}//end __construct()

	/**
	 * Whether a setting carries the security-relevant marker.
	 *
	 * @param string $path The setting path.
	 *
	 * @return bool True when a change to it is announced.
	 *
	 * @spec openspec/changes/audit-trail-shipped-and-purpose-bound/specs/enhanced-audit-trail/spec.md
	 */
	public function isSecurityRelevant(string $path): bool {
		return array_key_exists($path, self::SETTINGS);
	}//end isSecurityRelevant()

	/**
	 * Whether a setting holds a secret that must never be quoted.
	 *
	 * @param string $path The setting path.
	 *
	 * @return bool True when the flag says so, or the path names a credential.
	 *
	 * @spec openspec/changes/audit-trail-shipped-and-purpose-bound/specs/enhanced-audit-trail/spec.md
	 */
	public function isSecret(string $path): bool {
		if ((self::SETTINGS[$path]['secret'] ?? false) === true) {
			return true;
		}

		$normalised = strtolower($path);
		foreach (self::SECRET_FRAGMENTS as $fragment) {
			if (str_contains($normalised, $fragment) === true) {
				return true;
			}
		}

		return false;
	}//end isSecret()

	/**
	 * The label an announcement shows for a setting.
	 *
	 * @param string $path The setting path.
	 *
	 * @return string The label, or the path itself when none is registered.
	 *
	 * @spec openspec/changes/audit-trail-shipped-and-purpose-bound/specs/enhanced-audit-trail/spec.md
	 */
	public function label(string $path): string {
		return (self::SETTINGS[$path]['label'] ?? $path);
	}//end label()

	/**
	 * The current value of every marked setting, defaults filled in.
	 *
	 * Read straight from the stored configuration rather than through the
	 * settings handler's getSettings(), which also lists every group, user and
	 * organisation on the instance. A snapshot taken twice per save should not
	 * cost two directory listings.
	 *
	 * @return array<string, mixed> Path to value.
	 *
	 * @spec openspec/changes/audit-trail-shipped-and-purpose-bound/specs/enhanced-audit-trail/spec.md
	 */
	public function snapshot(): array {
		$blobs = [];
		$values = [];
		foreach (self::SETTINGS as $path => $definition) {
			$values[$path] = $this->read(path: $path, definition: $definition, blobs: $blobs);
		}

		return $values;
	}//end snapshot()

	/**
	 * Read one marked setting.
	 *
	 * @param string                                                        $path       The setting path.
	 * @param array{label: string, default: mixed, secret: bool, type: string} $definition Its registry entry.
	 * @param array<string, array<string, mixed>>                           $blobs      Decoded blobs, cached per snapshot.
	 *
	 * @return mixed The stored value, or the default.
	 *
	 * @spec openspec/changes/audit-trail-shipped-and-purpose-bound/specs/enhanced-audit-trail/spec.md
	 */
	private function read(string $path, array $definition, array &$blobs): mixed {
		$default = $definition['default'];

		try {
			if (str_starts_with($path, '@') === true) {
				return $this->readKey(key: substr($path, 1), type: $definition['type'], default: $default);
			}

			[$blob, $field] = explode('.', $path, 2);
			if (array_key_exists($blob, $blobs) === false) {
				$decoded = json_decode($this->appConfig->getValueString(self::APP, $blob, ''), true);
				$blobs[$blob] = [];
				if (is_array($decoded) === true) {
					$blobs[$blob] = $decoded;
				}
			}

			return ($blobs[$blob][$field] ?? $default);
		} catch (Throwable $unreadable) {
			return $default;
		}
	}//end read()

	/**
	 * Read a setting stored under its own key.
	 *
	 * @param string $key     The app config key.
	 * @param string $type    `bool` or `string`.
	 * @param mixed  $default The default value.
	 *
	 * @return mixed The stored value.
	 *
	 * @spec openspec/changes/audit-trail-shipped-and-purpose-bound/specs/enhanced-audit-trail/spec.md
	 */
	private function readKey(string $key, string $type, mixed $default): mixed {
		if ($type === 'bool') {
			return $this->appConfig->getValueBool(self::APP, $key, (bool)$default);
		}

		return $this->appConfig->getValueString(self::APP, $key, (string)$default);
	}//end readKey()
}//end class
