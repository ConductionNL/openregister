<?php

/**
 * Records who changed a setting (ledger row Q10.13).
 *
 * Object writes are on the hash-chained audit trail; settings writes were not.
 * The register's note is exact about why that reads as coverage when it is not:
 * "the `audit` hits under `lib/Settings/` are schema fields on data, not a log
 * of configuration changes". So an administrator could point a register at a
 * different source, or switch a guard off, and the only trace was the value
 * itself.
 *
 * 🔑 DOOR-AGNOSTIC ON PURPOSE. The change names
 * `GenericSettingsService::update()` as the place to write from, and that
 * method DOES NOT EXIST YET: the generic service carries only
 * `loadConfiguration()`, and `apphost-settings-plane` still has six open tasks.
 * What does exist today is `AppHostSettingsService::updateSettings()`. Writing
 * the diff logic inside whichever door happens to exist would mean rewriting it
 * when the other one lands, and for a while there would be two write paths and
 * one of them audited. So the rule lives here, takes a before and an after, and
 * every door calls it.
 *
 * 🔴 A SECRET IS RECORDED AS CHANGED WITH BOTH VALUES MASKED, never omitted.
 * Omitting the row would mean the one setting worth auditing most — the
 * credential somebody rotated — is the one the trail does not mention. Masking
 * keeps the fact and drops the value, which is what an auditor needs: that it
 * changed, when, and by whom.
 *
 * 🔴 AND IT NEVER PUTS THE VALUE OF A SECRET IN THE ROW. The audit trail is
 * append-only and readable by auditors, so a secret written into it is a
 * credential that cannot be redacted afterwards. The masking is not
 * presentation; it happens before the row is built.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rbac
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

namespace OCA\OpenRegister\Service\Rbac;

use DateTime;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;
use Throwable;

/**
 * Turns a settings write into per-key audit rows on the existing chain.
 *
 * @spec openspec/changes/settings-change-audit/specs/audit-trail-immutable/spec.md
 */
class SettingsChangeAuditor {

	/**
	 * The action a per-key settings change carries.
	 *
	 * @var string
	 */
	public const ACTION_UPDATED = 'settings.updated';

	/**
	 * The action a configuration import carries.
	 *
	 * @var string
	 */
	public const ACTION_IMPORTED = 'settings.imported';

	/**
	 * What a masked value reads as.
	 *
	 * A fixed token rather than a length-preserving mask: a mask that kept the
	 * length would leak the length, and for a credential that is a fact worth
	 * having if you are guessing one.
	 *
	 * @var string
	 */
	public const MASK = '********';

	/**
	 * The register-configuration key that declares a setting secret.
	 *
	 * @var string
	 */
	public const SECRET_KEY = 'x-openregister-secret';

	/**
	 * Constructor.
	 *
	 * @param AuditTrailMapper $mapper The trail, which owns the chain.
	 * @param IUserSession $userSession Who is making the change.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		private readonly AuditTrailMapper $mapper,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The per-key difference between two settings states.
	 *
	 * Pure, and public, so the rule can be tested against a table rather than
	 * against a database. A key present in one state and absent from the other
	 * counts as a change: added and removed are both things somebody did.
	 *
	 * @param array<string, mixed> $before The stored settings.
	 * @param array<string, mixed> $after The settings being written.
	 * @param array<int, string> $secretKeys Keys declared secret.
	 *
	 * @return array<int, array{key: string, old: mixed, new: mixed, secret: bool}> The changes.
	 *
	 * @spec openspec/changes/settings-change-audit/specs/audit-trail-immutable/spec.md
	 */
	public function diff(array $before, array $after, array $secretKeys = []): array {
		$keys = array_unique(array_merge(array_keys($before), array_keys($after)));
		sort($keys);

		$changes = [];
		foreach ($keys as $key) {
			$key = (string)$key;
			$hadBefore = array_key_exists($key, $before);
			$hasAfter = array_key_exists($key, $after);

			$old = ($before[$key] ?? null);
			$new = ($after[$key] ?? null);

			// A write that sets a key to the value it already had is not a
			// change, and recording it would fill the trail with the noise of
			// every save of a settings form that touched one field.
			if ($hadBefore === true && $hasAfter === true && $this->same(a: $old, b: $new) === true) {
				continue;
			}

			if ($hadBefore === false && $hasAfter === false) {
				continue;
			}

			$secret = in_array($key, $secretKeys, true);
			$changes[] = [
				'key' => $key,
				'old' => ($secret === true ? $this->maskIfPresent(value: $old, present: $hadBefore) : $old),
				'new' => ($secret === true ? $this->maskIfPresent(value: $new, present: $hasAfter) : $new),
				'secret' => $secret,
			];
		}//end foreach

		return $changes;
	}//end diff()

	/**
	 * The keys an app's register configuration declares secret.
	 *
	 * @param array<string, mixed> $configuration The app's register configuration.
	 *
	 * @return array<int, string> The secret keys.
	 *
	 * @spec openspec/changes/settings-change-audit/specs/audit-trail-immutable/spec.md
	 */
	public function secretKeysIn(array $configuration): array {
		$secrets = [];
		foreach ($configuration as $key => $declaration) {
			if (is_array($declaration) === true && ($declaration[self::SECRET_KEY] ?? null) === true) {
				$secrets[] = (string)$key;
			}
		}

		return $secrets;
	}//end secretKeysIn()

	/**
	 * Record a settings write, one row per changed key.
	 *
	 * Returns how many rows were written. NEVER THROWS: the setting has
	 * already been stored by the time this is called, so failing here would
	 * turn a missing audit row into a failed save of a change that in fact
	 * happened — the worst of both, because the value moved and the trail
	 * denies it. It is logged at ERROR instead.
	 *
	 * @param string $app The app whose settings changed.
	 * @param array<string, mixed> $before The stored settings.
	 * @param array<string, mixed> $after The settings written.
	 * @param array<int, string> $secretKeys Keys declared secret.
	 *
	 * @return int How many rows were written.
	 *
	 * @spec openspec/changes/settings-change-audit/specs/audit-trail-immutable/spec.md
	 */
	public function recordUpdate(string $app, array $before, array $after, array $secretKeys = []): int {
		$changes = $this->diff(before: $before, after: $after, secretKeys: $secretKeys);
		if ($changes === []) {
			return 0;
		}

		$rows = [];
		foreach ($changes as $change) {
			$rows[] = $this->row(
				action: self::ACTION_UPDATED,
				changed: [
					'app' => $app,
					'key' => $change['key'],
					'old' => $change['old'],
					'new' => $change['new'],
					'secret' => $change['secret'],
				]
			);
		}

		return $this->write(rows: $rows);
	}//end recordUpdate()

	/**
	 * Record a configuration import, as ONE row naming what it overwrote.
	 *
	 * An import can touch every key at once, and a row per key would describe
	 * one administrative act as forty. The act is the import; the count is
	 * what an auditor wants beside it.
	 *
	 * @param string $app The app whose configuration was imported.
	 * @param bool $forced Whether the import was forced over an existing one.
	 * @param int $keysOverwritten How many stored keys the import replaced.
	 *
	 * @return int How many rows were written.
	 *
	 * @spec openspec/changes/settings-change-audit/specs/audit-trail-immutable/spec.md
	 */
	public function recordImport(string $app, bool $forced, int $keysOverwritten): int {
		return $this->write(
			rows: [
				$this->row(
					action: self::ACTION_IMPORTED,
					changed: [
						'app' => $app,
						'forced' => $forced,
						'keysOverwritten' => $keysOverwritten,
					]
				),
			]
		);
	}//end recordImport()

	/**
	 * One row, unsealed; the mapper seals it.
	 *
	 * @param string $action The action.
	 * @param array<string, mixed> $changed What changed.
	 *
	 * @return AuditTrail The row.
	 */
	private function row(string $action, array $changed): AuditTrail {
		$user = $this->userSession->getUser();
		$userId = 'system';
		$userName = 'System';
		if ($user !== null) {
			$userId = $user->getUID();
			$userName = $user->getDisplayName();
		}

		$row = new AuditTrail();
		$row->setUuid(Uuid::v4()->toRfc4122());
		$row->setAction($action);
		$row->setUser($userId);
		$row->setUserName($userName);
		$row->setChanged($changed);
		$row->setCreated(new DateTime());

		return $row;
	}//end row()

	/**
	 * Write the rows through the one path that seals.
	 *
	 * @param array<int, AuditTrail> $rows The rows.
	 *
	 * @return int How many were written.
	 */
	private function write(array $rows): int {
		try {
			$this->mapper->insertAuditTrails(entries: $rows);
		} catch (Throwable $e) {
			$this->logger->error(
				message: '[SettingsChangeAuditor] Could not record a settings change; the setting itself was stored',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'entries' => count($rows),
					'exception' => $e->getMessage(),
				]
			);
			return 0;
		}

		return count($rows);
	}//end write()

	/**
	 * A masked stand-in, or null when the key was not there at all.
	 *
	 * The difference matters: a secret that was ABSENT and is now set is a
	 * credential being introduced, and a secret that was set and is now absent
	 * is one being removed. Masking both to the same token would make those two
	 * read identically.
	 *
	 * @param mixed $value The value.
	 * @param bool $present Whether the key was present.
	 *
	 * @return string|null The mask, or null.
	 */
	private function maskIfPresent(mixed $value, bool $present): ?string {
		if ($present === false) {
			return null;
		}

		return self::MASK;
	}//end maskIfPresent()

	/**
	 * Whether two settings values are the same.
	 *
	 * Compared as STRINGS where both are scalar, because `IAppConfig` stores
	 * everything as a string: a form that posts `"1"` over a stored `1` has
	 * changed nothing, and a strict comparison would record a change on every
	 * save.
	 *
	 * @param mixed $a One value.
	 * @param mixed $b The other.
	 *
	 * @return bool True when they are the same setting.
	 */
	private function same(mixed $a, mixed $b): bool {
		if (is_scalar($a) === true && is_scalar($b) === true) {
			return ((string)$a === (string)$b);
		}

		return ($a === $b);
	}//end same()
}//end class
