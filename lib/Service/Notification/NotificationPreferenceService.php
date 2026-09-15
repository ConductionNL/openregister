<?php

/**
 * OpenRegister NotificationPreferenceService
 *
 * Override-only resolver for notification preferences. Rules and their on/off
 * (and channel) defaults live ONLY in the schema annotation
 * `x-openregister-notifications`; everything stored here is an OVERRIDE, so:
 *   - absence of a stored value falls through to the layer below, and
 *   - adding a NEW schema or a NEW notification to an existing schema keeps
 *     working with zero migration (no row needs to pre-exist).
 *
 * Three layers decide, narrowest party last:
 *   schema default → group default → the user's own value.
 * A group default is a team's decision, set by someone who administers that
 * group, and it lives in app config because it belongs to the group rather
 * than to whoever set it. The user's own value always wins over it.
 *
 * Each layer may also be pinned to a SCOPE — one register, one schema, or a
 * domain a leaf app declares — and within a layer a scoped value beats that
 * same party's global one. That is how "loud for vergunningen, quiet for
 * meldingen" is expressed without a second hierarchy.
 *
 * Key shapes (NB: Nextcloud's `configkey` column is 64 chars, so a long
 * slug + key is deterministically compressed; typical ones fit unchanged):
 *   notification_pref/<schemaSlug>/<notificationKey>            user, global
 *   notif_pref_s/<scope>/<schemaSlug>/<notificationKey>         user, scoped
 *   notif_grp_pref/<gid>/<scope>/<schemaSlug>/<notificationKey> group
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
use OCP\IGroupManager;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * Resolves and stores override-only notification preferences.
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Three layers times four scopes, each with its
 * own key shape and its own fall-through; the branches are the resolution order itself.
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
	 * Prefix for a SCOPED preference, user or group.
	 *
	 * Distinct from {@see KEY_PREFIX} on purpose: every preference stored before
	 * scopes existed is a global one, and it keeps the key it has. A scoped
	 * value is a new key, so nothing needs migrating and an instance that never
	 * uses a scope stores nothing extra.
	 */
	private const SCOPED_KEY_PREFIX = 'notif_pref_s/';

	/**
	 * Prefix for a group default. App config, not user config: the value
	 * belongs to the group, not to whoever happened to set it.
	 */
	private const GROUP_KEY_PREFIX = 'notif_grp_pref/';

	/**
	 * The scope kinds a preference may be pinned to, most specific first.
	 *
	 * The order IS the resolution order. A schema is narrower than a domain,
	 * which is narrower than a register, and anything scoped beats the same
	 * party's global value. Reordering this reorders which preference wins.
	 *
	 * @var array<int, string>
	 */
	public const SCOPE_KINDS = ['schema', 'domain', 'register'];

	/**
	 * Maximum length of a Nextcloud `oc_preferences.configkey` value.
	 */
	private const MAX_KEY_LENGTH = 64;

	/**
	 * Per-request cache of each user's group ids.
	 *
	 * One dispatch evaluates every rule on a schema, and the same recipient
	 * turns up in several of them. Without this, "which groups is Anna in" is
	 * asked once per rule for the same answer (ADR-009). Only successful reads
	 * are cached: a transient backend failure is not evidence that somebody has
	 * no groups, and caching it would leave them with no team default for the
	 * rest of the request.
	 *
	 * @var array<string, array<int, string>>
	 */
	private array $groupCache = [];

	/**
	 * Constructor.
	 *
	 * @param IConfig $config Nextcloud config for per-user values.
	 * @param SchemaMapper $schemaMapper Mapper used to enumerate accessible schemas.
	 * @param LoggerInterface $logger Logger for diagnostics.
	 * @param IGroupManager|null $groupManager Group resolver; null leaves the group layer empty.
	 * @param IUserManager|null $userManager User resolver, needed to read a user's groups.
	 */
	public function __construct(
		private readonly IConfig $config,
		private readonly SchemaMapper $schemaMapper,
		private readonly LoggerInterface $logger,
		private readonly ?IGroupManager $groupManager = null,
		private readonly ?IUserManager $userManager = null,
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
	 * Build the config key for a SCOPED preference.
	 *
	 * The scope is part of the key rather than part of the value, so an unset
	 * scope is an absent row and falls through to the global value with nothing
	 * to migrate — the same property that makes adding a schema free today.
	 *
	 * @param string $scope The scope, spelled `<kind>:<id>` (e.g. `schema:zaak`).
	 * @param string $schemaSlug The owning schema's slug.
	 * @param string $notificationKey The notification annotation key.
	 * @param string|null $groupId The group the default belongs to, or null for a user's own value.
	 *
	 * @return string The namespaced config key (<= 64 chars).
	 *
	 * @spec openspec/changes/notification-routing-per-group-and-scope/specs/notificatie-engine/spec.md#requirement-a-preference-may-be-scoped-to-a-register-a-schema-or-a-declared-domain-req-nrg-003
	 */
	public function scopedConfigKey(
		string $scope,
		string $schemaSlug,
		string $notificationKey,
		?string $groupId = null,
	): string {
		$prefix = self::SCOPED_KEY_PREFIX;
		$tail = $scope . '/' . $schemaSlug . '/' . $notificationKey;
		if ($groupId !== null) {
			$prefix = self::GROUP_KEY_PREFIX;
			$tail = $groupId . '/' . $tail;
		}

		$key = $prefix . $tail;
		if (strlen($key) <= self::MAX_KEY_LENGTH) {
			return $key;
		}

		// Same deterministic compression as the unscoped key: a readable head
		// plus a stable hash of the whole tail, so two distinct scopes can
		// never land on one row.
		$hash = substr(hash('sha256', $tail), 0, 16);
		$budget = (self::MAX_KEY_LENGTH - strlen($prefix) - 1 - strlen($hash));
		$readable = substr($tail, 0, max($budget, 0));
		return $prefix . $readable . '~' . $hash;
	}//end scopedConfigKey()

	/**
	 * The key a GROUP default is stored under, global or scoped.
	 *
	 * @param string $groupId The group id.
	 * @param string $schemaSlug The owning schema's slug.
	 * @param string $notificationKey The notification annotation key.
	 * @param string|null $scope The scope, or null for the group's global default.
	 *
	 * @return string The app-config key.
	 *
	 * @spec openspec/changes/notification-routing-per-group-and-scope/specs/notificatie-engine/spec.md#requirement-the-effective-preference-merges-schema-group-and-user-and-names-the-layer-req-nrg-002
	 */
	public function groupConfigKey(
		string $groupId,
		string $schemaSlug,
		string $notificationKey,
		?string $scope = null,
	): string {
		return $this->scopedConfigKey(
			scope: ($scope ?? 'global'),
			schemaSlug: $schemaSlug,
			notificationKey: $notificationKey,
			groupId: $groupId
		);
	}//end groupConfigKey()

	/**
	 * Read a group's stored default, or null when the group has none.
	 *
	 * @param string $groupId The group id.
	 * @param string $schemaSlug The owning schema's slug.
	 * @param string $notificationKey The notification annotation key.
	 * @param string|null $scope The scope, or null for the group's global default.
	 *
	 * @return array<string, mixed>|null The decoded default, or null when none/invalid.
	 *
	 * @spec openspec/changes/notification-routing-per-group-and-scope/specs/notificatie-engine/spec.md#requirement-the-effective-preference-merges-schema-group-and-user-and-names-the-layer-req-nrg-002
	 */
	public function getGroupDefault(
		string $groupId,
		string $schemaSlug,
		string $notificationKey,
		?string $scope = null,
	): ?array {
		$raw = $this->config->getAppValue(
			self::APP_NAME,
			$this->groupConfigKey(
				groupId: $groupId,
				schemaSlug: $schemaSlug,
				notificationKey: $notificationKey,
				scope: $scope
			),
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
	}//end getGroupDefault()

	/**
	 * Write or clear a group's default.
	 *
	 * Passing null clears it, so the layer below — the schema default — applies
	 * again. The caller is responsible for having established that the writer
	 * administers this group; this method stores what it is given.
	 *
	 * @param string $groupId The group id.
	 * @param string $schemaSlug The owning schema's slug.
	 * @param string $notificationKey The notification annotation key.
	 * @param array<string, mixed>|null $default The default (`enabled`, optional `channels`) or null to clear.
	 * @param string|null $scope The scope, or null for the group's global default.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/notification-routing-per-group-and-scope/specs/notificatie-engine/spec.md#requirement-the-effective-preference-merges-schema-group-and-user-and-names-the-layer-req-nrg-002
	 */
	public function setGroupDefault(
		string $groupId,
		string $schemaSlug,
		string $notificationKey,
		?array $default,
		?string $scope = null,
	): void {
		$key = $this->groupConfigKey(
			groupId: $groupId,
			schemaSlug: $schemaSlug,
			notificationKey: $notificationKey,
			scope: $scope
		);

		if ($default === null) {
			$this->config->deleteAppValue(self::APP_NAME, $key);
			return;
		}

		$this->config->setAppValue(self::APP_NAME, $key, json_encode($this->cleanValue(value: $default)));
	}//end setGroupDefault()

	/**
	 * The groups a user belongs to, or an empty list when they cannot be read.
	 *
	 * Failing to an EMPTY list rather than throwing keeps the merge working
	 * when the group backend is briefly unavailable: the user's own value and
	 * the schema default still decide, which is the same answer they got before
	 * group defaults existed.
	 *
	 * @param string $userId The user UID.
	 *
	 * @return array<int, string> The group ids.
	 *
	 * @spec openspec/changes/notification-routing-per-group-and-scope/specs/notificatie-engine/spec.md#requirement-the-effective-preference-merges-schema-group-and-user-and-names-the-layer-req-nrg-002
	 */
	public function groupsOf(string $userId): array {
		if ($this->groupManager === null || $this->userManager === null) {
			return [];
		}

		if (isset($this->groupCache[$userId]) === true) {
			return $this->groupCache[$userId];
		}

		try {
			$user = $this->userManager->get($userId);
			if ($user === null) {
				return [];
			}

			$this->groupCache[$userId] = array_values($this->groupManager->getUserGroupIds($user));
			return $this->groupCache[$userId];
		} catch (\Throwable $e) {
			$this->logger->debug(
				'[NotificationPreferenceService] group membership read failed: ' . $e->getMessage()
			);
			return [];
		}
	}//end groupsOf()

	/**
	 * Normalise a stored preference body to the two keys that are read back.
	 *
	 * @param array<string, mixed> $value The raw body.
	 *
	 * @return array<string, mixed> The cleaned body.
	 */
	private function cleanValue(array $value): array {
		$clean = ['enabled' => (bool)($value['enabled'] ?? true)];
		if (isset($value['channels']) === true && is_array($value['channels']) === true) {
			$clean['channels'] = array_values(
				array_filter($value['channels'], static fn ($c): bool => is_string($c) === true && $c !== '')
			);
		}

		return $clean;
	}//end cleanValue()

	/**
	 * Read a user's stored override for a `(schemaSlug, notificationKey)`
	 * pair. Returns null when no override is stored (fall through to the
	 * schema default) — never throws for an unknown key.
	 *
	 * @param string $userId The user UID.
	 * @param string $schemaSlug The owning schema's slug.
	 * @param string $notificationKey The notification annotation key.
	 * @param string|null $scope The scope, or null for the user's global value.
	 *
	 * @return array<string, mixed>|null The decoded override, or null when none/invalid.
	 *
	 * @spec openspec/changes/notification-routing-per-group-and-scope/specs/notificatie-engine/spec.md#requirement-a-preference-may-be-scoped-to-a-register-a-schema-or-a-declared-domain-req-nrg-003
	 */
	public function getOverride(
		string $userId,
		string $schemaSlug,
		string $notificationKey,
		?string $scope = null,
	): ?array {
		$key = $this->configKey(schemaSlug: $schemaSlug, notificationKey: $notificationKey);
		if ($scope !== null) {
			$key = $this->scopedConfigKey(
				scope: $scope,
				schemaSlug: $schemaSlug,
				notificationKey: $notificationKey
			);
		}

		$raw = $this->config->getUserValue(
			$userId,
			self::APP_NAME,
			$key,
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
	 * @param string|null $scope The scope, or null for the user's global value.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/notification-routing-per-group-and-scope/specs/notificatie-engine/spec.md#requirement-a-preference-may-be-scoped-to-a-register-a-schema-or-a-declared-domain-req-nrg-003
	 */
	public function setOverride(
		string $userId,
		string $schemaSlug,
		string $notificationKey,
		?array $override,
		?string $scope = null,
	): void {
		$key = $this->configKey(schemaSlug: $schemaSlug, notificationKey: $notificationKey);
		if ($scope !== null) {
			$key = $this->scopedConfigKey(
				scope: $scope,
				schemaSlug: $schemaSlug,
				notificationKey: $notificationKey
			);
		}

		if ($override === null) {
			$this->config->deleteUserValue($userId, self::APP_NAME, $key);
			return;
		}

		$this->config->setUserValue($userId, self::APP_NAME, $key, json_encode($this->cleanValue(value: $override)));
	}//end setOverride()

	/**
	 * Resolve the EFFECTIVE preference for a `(schemaSlug, notificationKey)`
	 * pair as `schema-default ⊕ group-default ⊕ user-override`.
	 *
	 * Three layers, narrowest party last: the schema says what the rule does by
	 * default, the groups the user belongs to may change it for the team, and
	 * the user's own value always wins. Each layer may be pinned to a scope,
	 * and within a layer a scoped value beats that same party's global one, in
	 * the order {@see SCOPE_KINDS} declares.
	 *
	 * `source` names the layer that decided, which is the answer to "why am I
	 * not getting this": without it the question has three possible answers and
	 * the read offers none of them. `scope` names how narrowly that layer was
	 * pinned, and `layers` carries the full trace for a support screen.
	 *
	 * Unknown keys with no stored value at any layer resolve to the schema
	 * default without error, exactly as before.
	 *
	 * @param array<string, mixed> $schemaDefault The notification spec block from the schema (provides `enabled`/`channels`).
	 * @param string $userId The user UID.
	 * @param string $schemaSlug The owning schema's slug.
	 * @param string $notificationKey The notification annotation key.
	 * @param array<int, string> $scopes Candidate scopes for this dispatch, narrowest first (e.g. `['schema:zaak', 'register:7']`).
	 * @param array<int, string>|null $groupIds The user's groups; read from the group manager when null.
	 *
	 * @return array{enabled: bool, channels: array<int, string>|null, source: string, scope: string, layers: array<int, array<string, mixed>>}
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList) The merge's inputs: the default, the party, the scope.
	 *
	 * @spec openspec/changes/notification-routing-per-group-and-scope/specs/notificatie-engine/spec.md#requirement-the-effective-preference-merges-schema-group-and-user-and-names-the-layer-req-nrg-002
	 */
	public function resolveEffective(
		array $schemaDefault,
		string $userId,
		string $schemaSlug,
		string $notificationKey,
		array $scopes = [],
		?array $groupIds = null,
	): array {
		$defaultEnabled = (bool)($schemaDefault['enabled'] ?? true);
		$defaultChannels = null;
		if (isset($schemaDefault['channels']) === true && is_array($schemaDefault['channels']) === true) {
			$defaultChannels = array_values($schemaDefault['channels']);
		}

		$layers = [
			[
				'layer' => 'schema-default',
				'scope' => 'global',
				'enabled' => $defaultEnabled,
				'channels' => $defaultChannels,
			],
		];

		$winner = [
			'enabled' => $defaultEnabled,
			'channels' => $defaultChannels,
			'source' => 'schema-default',
			'scope' => 'global',
		];

		// The group layer. Several groups may hold a default for the same kind;
		// the narrowest scope wins, and among equally scoped groups the first
		// one the group manager returns does. Deliberately not "the strictest
		// wins": a team default is a team's decision, not a vote.
		$groups = ($groupIds ?? $this->groupsOf(userId: $userId));
		foreach ($groups as $groupId) {
			$found = $this->firstScopedValue(
				scopes: $scopes,
				schemaSlug: $schemaSlug,
				notificationKey: $notificationKey,
				userId: null,
				groupId: (string)$groupId
			);
			if ($found === null) {
				continue;
			}

			$winner = $this->applyLayer(
				base: $winner,
				value: $found['value'],
				defaultChannels: $defaultChannels,
				layer: 'group-default',
				scope: $found['scope']
			);
			$layers[] = [
				'layer' => 'group-default',
				'group' => (string)$groupId,
				'scope' => $found['scope'],
				'enabled' => $winner['enabled'],
				'channels' => $winner['channels'],
			];
			break;
		}//end foreach

		// The user's own value, which always wins when it is there.
		$own = $this->firstScopedValue(
			scopes: $scopes,
			schemaSlug: $schemaSlug,
			notificationKey: $notificationKey,
			userId: $userId,
			groupId: null
		);
		if ($own !== null) {
			$winner = $this->applyLayer(
				base: $winner,
				value: $own['value'],
				defaultChannels: $defaultChannels,
				layer: 'user-override',
				scope: $own['scope']
			);
			$layers[] = [
				'layer' => 'user-override',
				'scope' => $own['scope'],
				'enabled' => $winner['enabled'],
				'channels' => $winner['channels'],
			];
		}

		$winner['layers'] = $layers;
		return $winner;
	}//end resolveEffective()

	/**
	 * Find one party's value, trying each scope in order before the global one.
	 *
	 * @param array<int, string> $scopes Candidate scopes, narrowest first.
	 * @param string $schemaSlug The owning schema's slug.
	 * @param string $notificationKey The notification annotation key.
	 * @param string|null $userId The user whose own value to read, or null for a group.
	 * @param string|null $groupId The group whose default to read, or null for a user.
	 *
	 * @return array{value: array<string, mixed>, scope: string}|null The value and the scope it came from, or null.
	 *
	 * @spec openspec/changes/notification-routing-per-group-and-scope/specs/notificatie-engine/spec.md#requirement-a-preference-may-be-scoped-to-a-register-a-schema-or-a-declared-domain-req-nrg-003
	 */
	private function firstScopedValue(
		array $scopes,
		string $schemaSlug,
		string $notificationKey,
		?string $userId,
		?string $groupId,
	): ?array {
		foreach ($scopes as $scope) {
			$value = $this->readValue(
				schemaSlug: $schemaSlug,
				notificationKey: $notificationKey,
				userId: $userId,
				groupId: $groupId,
				scope: (string)$scope
			);
			if ($value !== null) {
				return ['value' => $value, 'scope' => (string)$scope];
			}
		}

		// No scoped value: the party's global one, and an absent one there is
		// the fall-through this whole design is built on.
		$value = $this->readValue(
			schemaSlug: $schemaSlug,
			notificationKey: $notificationKey,
			userId: $userId,
			groupId: $groupId,
			scope: null
		);
		if ($value === null) {
			return null;
		}

		return ['value' => $value, 'scope' => 'global'];
	}//end firstScopedValue()

	/**
	 * Read one stored value for one party at one scope.
	 *
	 * @param string $schemaSlug The owning schema's slug.
	 * @param string $notificationKey The notification annotation key.
	 * @param string|null $userId The user, or null for a group.
	 * @param string|null $groupId The group, or null for a user.
	 * @param string|null $scope The scope, or null for the global value.
	 *
	 * @return array<string, mixed>|null The stored value, or null when absent.
	 */
	private function readValue(
		string $schemaSlug,
		string $notificationKey,
		?string $userId,
		?string $groupId,
		?string $scope,
	): ?array {
		if ($groupId !== null) {
			return $this->getGroupDefault(
				groupId: $groupId,
				schemaSlug: $schemaSlug,
				notificationKey: $notificationKey,
				scope: $scope
			);
		}

		if ($userId === null) {
			return null;
		}

		return $this->getOverride(
			userId: $userId,
			schemaSlug: $schemaSlug,
			notificationKey: $notificationKey,
			scope: $scope
		);
	}//end readValue()

	/**
	 * Fold one layer's stored value over the answer so far.
	 *
	 * Channel narrowing is preserved from the two-layer version: a layer may
	 * only RESTRICT to a subset of the channels the schema declared, never
	 * widen beyond them. A group cannot give its members a channel the rule
	 * does not have, and neither can a user.
	 *
	 * @param array{enabled: bool, channels: array<int, string>|null, source: string, scope: string} $base The answer so far.
	 * @param array<string, mixed> $value The layer's stored value.
	 * @param array<int, string>|null $defaultChannels The channels the schema declared.
	 * @param string $layer The layer's name, recorded as the source when it decides.
	 * @param string $scope The scope this value was pinned to.
	 *
	 * @return array{enabled: bool, channels: array<int, string>|null, source: string, scope: string}
	 */
	private function applyLayer(
		array $base,
		array $value,
		?array $defaultChannels,
		string $layer,
		string $scope,
	): array {
		$enabled = (bool)($value['enabled'] ?? $base['enabled']);
		$channels = $base['channels'];
		if (isset($value['channels']) === true && is_array($value['channels']) === true) {
			$channels = array_values(
				array_intersect($value['channels'], ($defaultChannels ?? $value['channels']))
			);
		}

		return [
			'enabled' => $enabled,
			'channels' => $channels,
			'source' => $layer,
			'scope' => $scope,
		];
	}//end applyLayer()

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
	 *
	 * @spec openspec/changes/notification-routing-per-group-and-scope/specs/notificatie-engine/spec.md#requirement-the-effective-preference-merges-schema-group-and-user-and-names-the-layer-req-nrg-002
	 */
	public function getEffectiveForUser(string $userId): array {
		$entries = [];

		// Read once for the whole enumeration rather than once per rule: a
		// membership lookup per (schema, notification) pair is the same answer
		// fetched hundreds of times (ADR-009).
		$groupIds = $this->groupsOf(userId: $userId);

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
					notificationKey: (string)$key,
					scopes: [],
					groupIds: $groupIds
				);

				$entries[] = [
					'schema' => $schemaSlug,
					'schemaTitle' => $schemaTitle,
					'application' => $application,
					'notification' => (string)$key,
					'enabled' => $effective['enabled'],
					'channels' => $effective['channels'],
					'source' => $effective['source'],
					// Which layer decided, and how narrowly it was pinned. The
					// list is the screen a person reads when they want to know
					// why they are or are not being told something.
					'scope' => $effective['scope'],
					'layers' => $effective['layers'],
				];
			}//end foreach
		}//end foreach

		return $entries;
	}//end getEffectiveForUser()
}//end class
