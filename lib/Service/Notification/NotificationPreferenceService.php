<?php

/**
 * OpenRegister NotificationPreferenceService
 *
 * Override-only resolver for per-user notification preferences. Rules and
 * their on/off (and channel) defaults live ONLY in the schema annotation
 * `x-openregister-notifications`; a user's preference is stored as an
 * OVERRIDE in Nextcloud per-user app config under the `openregister` app,
 * keyed per `(schemaSlug, notificationKey)` pair. The effective value is
 * `user-override ?? schema-default`, so:
 *   - absence of an override falls through to the schema default, and
 *   - adding a NEW schema or a NEW notification to an existing schema keeps
 *     working with zero migration (no per-user row needs to pre-exist).
 *
 * Key shape (NB: Nextcloud's `oc_preferences.configkey` column is 64 chars,
 * so the slug + key must stay within that budget; typical slugs/keys fit):
 *   notification_pref/<schemaSlug>/<notificationKey>
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
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

use OCA\OpenRegister\Db\SchemaMapper;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

/**
 * Resolves and stores override-only notification preferences.
 */
class NotificationPreferenceService {
	/**
	 * App id used for the user-config namespace.
	 */
	private const APP_NAME = 'openregister';

	/**
	 * Prefix for every per-(schema, notification) override config key.
	 */
	private const KEY_PREFIX = 'notification_pref/';

	/**
	 * Maximum length of a Nextcloud `oc_preferences.configkey` value.
	 */
	private const MAX_KEY_LENGTH = 64;

	/**
	 * Constructor.
	 *
	 * @param IConfig $config Nextcloud config for per-user values.
	 * @param SchemaMapper $schemaMapper Mapper used to enumerate accessible schemas.
	 * @param LoggerInterface $logger Logger for diagnostics.
	 */
	public function __construct(
		private readonly IConfig $config,
		private readonly SchemaMapper $schemaMapper,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Build the user-config key for a `(schemaSlug, notificationKey)` pair.
	 *
	 * Nextcloud's `oc_preferences.configkey` column is 64 chars. Most
	 * `(slug, key)` pairs fit comfortably, but long system-schema slugs
	 * (e.g. `openregister_configuration` + `configuration-changed` = 66 chars)
	 * overflow and make the underlying `getUserValue()` / `setUserValue()`
	 * throw "for key is too long (64)". When the natural key fits, it is
	 * returned unchanged (full backward compatibility for every stored
	 * preference); when it would overflow, it is deterministically compressed
	 * to a stable 64-char key so the same pair always maps to the same key.
	 *
	 * @param string $schemaSlug The owning schema's slug.
	 * @param string $notificationKey The notification annotation key.
	 *
	 * @return string The namespaced config key (<= 64 chars).
	 */
	public function configKey(string $schemaSlug, string $notificationKey): string {
		$key = self::KEY_PREFIX . $schemaSlug . '/' . $notificationKey;
		if (strlen($key) <= self::MAX_KEY_LENGTH) {
			return $key;
		}

		// Deterministic 64-char fallback: keep the readable prefix and append a
		// stable hash of the full pair so distinct pairs never collide.
		$hash = substr(hash('sha256', $schemaSlug . '/' . $notificationKey), 0, 16);
		$budget = (self::MAX_KEY_LENGTH - strlen(self::KEY_PREFIX) - 1 - strlen($hash));
		$readable = substr($schemaSlug . '/' . $notificationKey, 0, max($budget, 0));
		return self::KEY_PREFIX . $readable . '~' . $hash;
	}//end configKey()

	/**
	 * Read a user's stored override for a `(schemaSlug, notificationKey)`
	 * pair. Returns null when no override is stored (fall through to the
	 * schema default) — never throws for an unknown key.
	 *
	 * @param string $userId The user UID.
	 * @param string $schemaSlug The owning schema's slug.
	 * @param string $notificationKey The notification annotation key.
	 *
	 * @return array<string, mixed>|null The decoded override, or null when none/invalid.
	 */
	public function getOverride(string $userId, string $schemaSlug, string $notificationKey): ?array {
		$raw = $this->config->getUserValue(
			$userId,
			self::APP_NAME,
			$this->configKey(schemaSlug: $schemaSlug, notificationKey: $notificationKey),
			''
		);

		if ($raw === '') {
			return null;
		}

		$decoded = json_decode($raw, true);
		if (is_array($decoded) === false) {
			return null;
		}

		return $decoded;
	}//end getOverride()

	/**
	 * Write or clear a user's override for one `(schemaSlug, notificationKey)`
	 * pair. Passing null clears the override so the schema default applies
	 * again (zero-migration fall-through).
	 *
	 * @param string $userId The user UID.
	 * @param string $schemaSlug The owning schema's slug.
	 * @param string $notificationKey The notification annotation key.
	 * @param array<string, mixed>|null $override Override body (`enabled`, optional `channels`) or null to clear.
	 *
	 * @return void
	 */
	public function setOverride(string $userId, string $schemaSlug, string $notificationKey, ?array $override): void {
		$key = $this->configKey(schemaSlug: $schemaSlug, notificationKey: $notificationKey);

		if ($override === null) {
			$this->config->deleteUserValue($userId, self::APP_NAME, $key);
			return;
		}

		$clean = ['enabled' => (bool)($override['enabled'] ?? true)];
		if (isset($override['channels']) === true && is_array($override['channels']) === true) {
			$clean['channels'] = array_values(
				array_filter($override['channels'], static fn ($c): bool => is_string($c) === true && $c !== '')
			);
		}

		$this->config->setUserValue($userId, self::APP_NAME, $key, json_encode($clean));
	}//end setOverride()

	/**
	 * Resolve the EFFECTIVE preference for a `(schemaSlug, notificationKey)`
	 * pair as `schema-default ⊕ user-override`. Unknown keys with no stored
	 * override resolve to the schema default without error.
	 *
	 * @param array<string, mixed> $schemaDefault The notification spec block from the schema (provides `enabled`/`channels`).
	 * @param string $userId The user UID.
	 * @param string $schemaSlug The owning schema's slug.
	 * @param string $notificationKey The notification annotation key.
	 *
	 * @return array{enabled: bool, channels: array<int, string>|null, source: string}
	 */
	public function resolveEffective(
		array $schemaDefault,
		string $userId,
		string $schemaSlug,
		string $notificationKey,
	): array {
		$defaultEnabled = (bool)($schemaDefault['enabled'] ?? true);
		$defaultChannels = null;
		if (isset($schemaDefault['channels']) === true && is_array($schemaDefault['channels']) === true) {
			$defaultChannels = array_values($schemaDefault['channels']);
		}

		$override = $this->getOverride(userId: $userId, schemaSlug: $schemaSlug, notificationKey: $notificationKey);
		if ($override === null) {
			return [
				'enabled' => $defaultEnabled,
				'channels' => $defaultChannels,
				'source' => 'schema-default',
			];
		}

		$enabled = (bool)($override['enabled'] ?? $defaultEnabled);
		$channels = $defaultChannels;
		if (isset($override['channels']) === true && is_array($override['channels']) === true) {
			// Channel narrowing: the override may only RESTRICT to a subset
			// of the schema-declared channels, never widen beyond them.
			$narrowed = array_values(array_intersect($override['channels'], ($defaultChannels ?? $override['channels'])));
			$channels = $narrowed;
		}

		return [
			'enabled' => $enabled,
			'channels' => $channels,
			'source' => 'user-override',
		];
	}//end resolveEffective()

	/**
	 * Enumerate the EFFECTIVE notifications for a user: every notification
	 * declared by the user's accessible schemas, merged with that user's
	 * overrides, each tagged with its `source`.
	 *
	 * RBAC + multitenancy on `SchemaMapper::findAll()` already scope the
	 * schemas to those the user may read, so this never leaks notifications
	 * for inaccessible schemas.
	 *
	 * @param string $userId The user UID.
	 *
	 * @return array<int, array<string, mixed>> One entry per (schema, notification) pair.
	 */
	public function getEffectiveForUser(string $userId): array {
		$entries = [];

		try {
			$schemas = $this->schemaMapper->findAll();
		} catch (\Throwable $e) {
			$this->logger->warning(
				'[NotificationPreferenceService] schema enumeration failed: ' . $e->getMessage()
			);
			return [];
		}

		foreach ($schemas as $schema) {
			$config = ($schema->getConfiguration() ?? []);
			$notifications = ($config['x-openregister-notifications'] ?? null);
			if (is_array($notifications) === false) {
				continue;
			}

			$schemaSlug = (string)($schema->getSlug() ?? $schema->getId());
			$schemaTitle = (string)($schema->getTitle() ?? $schemaSlug);
			// Owning app id (e.g. "pipelinq"), set by the register/schema
			// import (ImportHandler::setApplication()) when the schema was
			// seeded from an app's configuration. Null for schemas with no
			// known owning app (e.g. hand-authored/system schemas); the
			// consuming settings UI groups those under an "other" bucket
			// rather than dropping them.
			$application = $schema->getApplication();

			foreach ($notifications as $key => $spec) {
				if (is_array($spec) === false) {
					continue;
				}

				$effective = $this->resolveEffective(
					schemaDefault: $spec,
					userId: $userId,
					schemaSlug: $schemaSlug,
					notificationKey: (string)$key
				);

				$entries[] = [
					'schema' => $schemaSlug,
					'schemaTitle' => $schemaTitle,
					'application' => $application,
					'notification' => (string)$key,
					'enabled' => $effective['enabled'],
					'channels' => $effective['channels'],
					'source' => $effective['source'],
				];
			}//end foreach
		}//end foreach

		return $entries;
	}//end getEffectiveForUser()
}//end class
