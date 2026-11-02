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

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Kernel\Contracts\NotificationPreferencesInterface;
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
class NotificationPreferenceService implements NotificationPreferencesInterface {
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
	 * The channels a rule delivers on when it declares none.
	 */
	public const DEFAULT_CHANNELS = ['nc-notification'];

	/**
	 * The channels a person can switch per notification. Webhook and Talk are
	 * broadcast once per rule, so nobody can opt in or out of them.
	 */
	public const PER_RECIPIENT_CHANNELS = ['nc-notification', 'email', 'activity', 'web-push'];

	/**
	 * The opt-in channels a rule declares: delivered only to someone who
	 * switched them on for that notification. A channel that is also in
	 * `channels` is on by default and so not opt-in.
	 *
	 * @param array<string, mixed> $spec The notification spec block.
	 *
	 * @return array<int, string> The opt-in channels.
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md#requirement-a-rule-can-declare-a-channel-that-is-off-until-someone-switches-it-on
	 */
	public static function optInChannelsOf(array $spec): array {
		$declared = (array)($spec['channels'] ?? self::DEFAULT_CHANNELS);
		$optIn = [];
		foreach ((array)($spec['optInChannels'] ?? []) as $channel) {
			if (is_string($channel) === true
				&& in_array($channel, self::PER_RECIPIENT_CHANNELS, true) === true
				&& in_array($channel, $declared, true) === false
				&& in_array($channel, $optIn, true) === false
			) {
				$optIn[] = $channel;
			}
		}

		return $optIn;
	}//end optInChannelsOf()

	/**
	 * Every channel a rule can deliver on: its default channels, then its
	 * opt-in ones.
	 *
	 * @param array<string, mixed> $spec The notification spec block.
	 *
	 * @return array<int, string> The channels.
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md#requirement-a-rule-can-declare-a-channel-that-is-off-until-someone-switches-it-on
	 */
	public static function availableChannelsOf(array $spec): array {
		$declared = array_values((array)($spec['channels'] ?? self::DEFAULT_CHANNELS));
		return array_values(array_merge($declared, self::optInChannelsOf(spec: $spec)));
	}//end availableChannelsOf()

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
	 * @spec openspec/specs/notificatie-engine/spec.md#requirement-a-preference-may-be-scoped-to-a-register-a-schema-or-a-declared-domain-req-nrg-003
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
	 * @spec openspec/specs/notificatie-engine/spec.md#requirement-the-effective-preference-merges-schema-group-and-user-and-names-the-layer-req-nrg-002
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
	 * @spec openspec/specs/notificatie-engine/spec.md#requirement-the-effective-preference-merges-schema-group-and-user-and-names-the-layer-req-nrg-002
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
	 * @spec openspec/specs/notificatie-engine/spec.md#requirement-the-effective-preference-merges-schema-group-and-user-and-names-the-layer-req-nrg-002
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
	 * @spec openspec/specs/notificatie-engine/spec.md#requirement-the-effective-preference-merges-schema-group-and-user-and-names-the-layer-req-nrg-002
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
	 * @spec openspec/specs/notificatie-engine/spec.md#requirement-a-preference-may-be-scoped-to-a-register-a-schema-or-a-declared-domain-req-nrg-003
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
	 * @spec openspec/specs/notificatie-engine/spec.md#requirement-a-preference-may-be-scoped-to-a-register-a-schema-or-a-declared-domain-req-nrg-003
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
	 * @spec openspec/specs/notificatie-engine/spec.md#requirement-the-effective-preference-merges-schema-group-and-user-and-names-the-layer-req-nrg-002
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
		[$defaultChannels, $allowedChannels] = self::declaredChannels(schemaDefault: $schemaDefault);
		$lookup = new PreferenceLookup(
			schemaSlug: $schemaSlug,
			notificationKey: $notificationKey,
			scopes: $scopes,
			allowedChannels: $allowedChannels
		);

		$winner = [
			'enabled' => $defaultEnabled,
			'channels' => $defaultChannels,
			'source' => 'schema-default',
			'scope' => 'global',
		];
		$layers = [self::layerRecord(winner: $winner, found: ['layer' => 'schema-default', 'scope' => 'global'])];

		$group = $this->firstGroupValue(lookup: $lookup, groupIds: ($groupIds ?? $this->groupsOf(userId: $userId)));

		// The user's own value, which always wins when it is there.
		$own = $this->firstScopedValue(
			lookup: $lookup,
			read: fn (?string $scope): ?array => $this->getOverride(
				userId: $userId,
				schemaSlug: $schemaSlug,
				notificationKey: $notificationKey,
				scope: $scope
			)
		);
		if ($own !== null) {
			$own['layer'] = 'user-override';
		}

		foreach ([$group, $own] as $found) {
			if ($found !== null) {
				$winner = $lookup->applyLayer(base: $winner, found: $found);
				$layers[] = self::layerRecord(winner: $winner, found: $found);
			}
		}

		$winner['layers'] = $layers;
		return $winner;
	}//end resolveEffective()

	/**
	 * The entry a layer leaves in the resolution's `layers` trail.
	 *
	 * @param array<string, mixed> $winner The answer after this layer.
	 * @param array<string, mixed> $found  The layer's name, scope and, for a group, its id.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md
	 */
	private static function layerRecord(array $winner, array $found): array {
		$record = ['layer' => $found['layer']];
		if (isset($found['group']) === true) {
			$record['group'] = $found['group'];
		}

		return $record + [
			'scope' => $found['scope'],
			'enabled' => $winner['enabled'],
			'channels' => $winner['channels'],
		];
	}//end layerRecord()

	/**
	 * The default channels a rule declares, and the channels a layer may choose among.
	 *
	 * An opt-in channel is off by default and on for whoever chose it, so the
	 * default needs naming even when the rule leaves `channels` out.
	 *
	 * @param array<string, mixed> $schemaDefault The rule's `{enabled, channels, optInChannels}` block.
	 *
	 * @return array{0: array<int, string>|null, 1: array<int, string>|null} The default and the allowed channels.
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md
	 */
	private static function declaredChannels(array $schemaDefault): array {
		$defaultChannels = null;
		if (isset($schemaDefault['channels']) === true && is_array($schemaDefault['channels']) === true) {
			$defaultChannels = array_values($schemaDefault['channels']);
		}

		if (self::optInChannelsOf(spec: $schemaDefault) === []) {
			return [$defaultChannels, $defaultChannels];
		}

		return [($defaultChannels ?? self::DEFAULT_CHANNELS), self::availableChannelsOf(spec: $schemaDefault)];
	}//end declaredChannels()

	/**
	 * The default of the first group that holds one, at its narrowest scope.
	 *
	 * Several groups may hold a default for the same kind; the narrowest scope
	 * wins, and among equally scoped groups the first one the group manager
	 * returns does. Deliberately not "the strictest wins": a team default is a
	 * team's decision, not a vote.
	 *
	 * @param PreferenceLookup $lookup   The kind, scopes and channels.
	 * @param array            $groupIds The user's group ids, in group-manager order.
	 *
	 * @return array{value: array<string, mixed>, scope: string, layer: string, group: string}|null
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md#requirement-a-preference-may-be-scoped-to-a-register-a-schema-or-a-declared-domain-req-nrg-003
	 */
	private function firstGroupValue(PreferenceLookup $lookup, array $groupIds): ?array {
		foreach ($groupIds as $groupId) {
			$found = $this->firstScopedValue(
				lookup: $lookup,
				read: fn (?string $scope): ?array => $this->getGroupDefault(
					groupId: (string)$groupId,
					schemaSlug: $lookup->schemaSlug,
					notificationKey: $lookup->notificationKey,
					scope: $scope
				)
			);
			if ($found !== null) {
				return $found + ['layer' => 'group-default', 'group' => (string)$groupId];
			}
		}

		return null;
	}//end firstGroupValue()

	/**
	 * Find one party's value, trying each scope in order before the global one.
	 *
	 * @param PreferenceLookup $lookup The kind and its candidate scopes, narrowest first.
	 * @param \Closure         $read   Reads the party's value at a scope, or at null for the global one.
	 *
	 * @return array{value: array<string, mixed>, scope: string}|null The value and the scope it came from, or null.
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md#requirement-a-preference-may-be-scoped-to-a-register-a-schema-or-a-declared-domain-req-nrg-003
	 */
	private function firstScopedValue(PreferenceLookup $lookup, \Closure $read): ?array {
		foreach ($lookup->scopes as $scope) {
			$value = $read((string)$scope);
			if ($value !== null) {
				return ['value' => $value, 'scope' => (string)$scope];
			}
		}

		// No scoped value: the party's global one, and an absent one there is
		// the fall-through this whole design is built on.
		$value = $read(null);
		if ($value === null) {
			return null;
		}

		return ['value' => $value, 'scope' => 'global'];
	}//end firstScopedValue()

	/**
	 * One schema per owning app and slug, the highest version.
	 *
	 * A preference is keyed by the schema slug, so two rows with one slug (an
	 * older version an import left behind) could only list every rule twice.
	 * The highest version wins, then the highest id; another app's schema with
	 * the same slug stays its own row.
	 *
	 * @param iterable<Schema> $schemas The schemas.
	 *
	 * @return array<int, Schema> The schemas to list.
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md#requirement-the-preference-list-names-each-rule-once
	 */
	private function onePerSlug(iterable $schemas): array {
		$kept = [];
		foreach ($schemas as $schema) {
			$key = (string)($schema->getApplication() ?? '').'|'.(string)($schema->getSlug() ?? $schema->getId());
			if (isset($kept[$key]) === false || $this->isNewer(schema: $schema, than: $kept[$key]) === true) {
				$kept[$key] = $schema;
			}
		}

		return array_values($kept);
	}//end onePerSlug()

	/**
	 * Whether a schema row is a later version of another with its slug.
	 *
	 * @param Schema $schema The candidate.
	 * @param Schema $than   The row kept so far.
	 *
	 * @return bool
	 */
	private function isNewer(Schema $schema, Schema $than): bool {
		$order = version_compare((string)($schema->getVersion() ?? '0'), (string)($than->getVersion() ?? '0'));
		if ($order !== 0) {
			return $order > 0;
		}

		return (int)$schema->getId() > (int)$than->getId();
	}//end isNewer()

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
	 * @param array<int, string> $scopes Candidate scopes to answer for, narrowest first; empty for the global answer.
	 *
	 * @return array<int, array<string, mixed>> One entry per (schema, notification) pair.
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md#requirement-the-effective-preference-merges-schema-group-and-user-and-names-the-layer-req-nrg-002
	 */
	public function getEffectiveForUser(string $userId, array $scopes = []): array {
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

		foreach ($this->onePerSlug(schemas: $schemas) as $schema) {
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
					scopes: $scopes,
					groupIds: $groupIds
				);

				$entries[] = [
					'schema' => $schemaSlug,
					'schemaTitle' => $schemaTitle,
					'application' => $application,
					'notification' => (string)$key,
					'enabled' => $effective['enabled'],
					'channels' => $effective['channels'],
					// What a person can choose from: the default channels and
					// the opt-in ones, so a screen can draw one column each.
					'availableChannels' => self::availableChannelsOf(spec: $spec),
					'optInChannels' => self::optInChannelsOf(spec: $spec),
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
