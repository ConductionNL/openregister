<?php

/**
 * Pre-creation ("advisory") locks, keyed on an arbitrary string.
 *
 * A different thing from an object lock, and now in a different class. An
 * object lock lives in the object's own `_locked` column and is what a write
 * guard consults. An advisory lock has no object at all: it exists so a
 * create-then-store flow can reserve a name before the row exists, such as
 * buildiq's wizard holding `createApp:<slug>` while it decides. It is stored
 * in app-config with an expiry.
 *
 * It conflicts for EVERYONE regardless of identity, which is the right
 * behaviour for a name reservation and the reason it needs none of the
 * holder machinery that object locks now carry.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/object-interactions/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

use DateTime;
use OCA\OpenRegister\Exception\LockedException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Kernel\Contracts\Value\LockRequest;
use OCA\OpenRegister\Contract\LockRequest as PublishedLockRequest;

/**
 * The appConfig-backed advisory lock store.
 */
class AdvisoryLockStore {

	/**
	 * Advisory-lock app-config key prefix.
	 *
	 * @var string
	 */
	private const PREFIX = 'advisory_lock_';

	/**
	 * Default advisory lock duration in seconds when none supplied.
	 *
	 * @var int
	 */
	private const DEFAULT_DURATION = 3600;

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig Where advisory locks are stored.
	 * @param IUserSession $userSession Names the holder, for display only.
	 * @param LoggerInterface $logger Reporting.
	 * @param IDBConnection|null $db Makes taking a lock atomic: one insert-if-absent or
	 *                               conditional UPDATE of the stored row. Without it the
	 *                               lock is read and written through the app config.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
		private readonly ?IDBConnection $db = null,
	) {

	}//end __construct()

	/**
	 * Build the app-config key used to store an advisory lock.
	 *
	 * @param string $identifier The arbitrary advisory-lock identifier.
	 *
	 * @return string The namespaced app-config key.
	 */
	private function key(string $identifier): string {
		// NOSEMGREP: php.lang.security.weak-crypto.weak-crypto -- The hash only builds a cache, lock or settings key, not a security value.
		return self::PREFIX . md5($identifier);
	}//end key()

	/**
	 * Acquire an advisory lock for an identifier that does not (yet) resolve
	 * to a stored object.
	 *
	 * A still-valid lock raises LockedException; an expired one is silently
	 * overwritten.
	 *
	 * @param PublishedLockRequest $request The identifier, the process tag and the duration in seconds.
	 *
	 * @return array{uuid: string, locked: array<string, mixed>} Advisory lock result.
	 *
	 * @throws LockedException If a non-expired advisory lock already exists.
	 *
	 * @spec openspec/specs/object-interactions/spec.md
	 */
	public function acquire(PublishedLockRequest $request): array {
		$now = new DateTime();
		$lock = $this->payload(request: $request, now: $now, ownerId: $this->userSession->getUser()?->getUID());

		if ($this->db === null) {
			$this->assertNotHeld(identifier: $request->identifier, now: $now);
			$this->appConfig->setValueString('openregister', $this->key(identifier: $request->identifier), json_encode($lock));
		} else {
			// Atomic: decide on the row as stored, then write only while it is unchanged.
			$stored = $this->storedRaw(key: $this->key(identifier: $request->identifier));
			if ($this->isLive(lock: self::decoded(raw: $stored), now: $now) === true
				|| $this->swap(key: $this->key(identifier: $request->identifier), previous: $stored, next: json_encode($lock)) === false
			) {
				throw new LockedException(message: "Advisory lock '{$request->identifier}' is already held");
			}
		}

		$this->logAcquired(request: $request);

		return ['uuid' => $request->identifier, 'locked' => $lock];
	}//end acquire()

	/**
	 * Claim an advisory lock for an explicit owner, atomically, without a session.
	 *
	 * For background jobs and workers. The claim succeeds when no lock is
	 * stored, the stored one has expired, or the same owner holds it; of two
	 * racing claimers only one gets true.
	 *
	 * @param LockRequest $request The identifier, the process tag and the duration in seconds.
	 * @param string      $ownerId The owner the lock is taken for.
	 *
	 * @return bool True when this caller now holds the lock.
	 *
	 * @spec openspec/specs/object-interactions/spec.md
	 */
	public function claim(LockRequest $request, string $ownerId): bool {
		if (trim($ownerId) === '') {
			return false;
		}

		$now = new DateTime();
		$key = $this->key(identifier: $request->identifier);
		$stored = $this->configRaw(key: $key);
		if ($this->db !== null) {
			$stored = $this->storedRaw(key: $key);
		}
		$existing = self::decoded(raw: $stored);
		if ($this->isLive(lock: $existing, now: $now) === true && ($existing['user'] ?? null) !== $ownerId) {
			return false;
		}

		$lock = $this->payload(request: $request, now: $now, ownerId: $ownerId);
		if ($this->db === null) {
			$this->appConfig->setValueString('openregister', $key, json_encode($lock));
		} elseif ($this->swap(key: $key, previous: $stored, next: json_encode($lock)) === false) {
			return false;
		}

		$this->logAcquired(request: $request);

		return true;
	}//end claim()

	/**
	 * The stored lock row's value read fresh from the table, or null when there is none.
	 *
	 * @param string $key The app-config key.
	 *
	 * @return string|null The stored value.
	 */
	private function storedRaw(string $key): ?string {
		$qb = $this->db->getQueryBuilder();
		$qb->select('configvalue')
			->from('appconfig')
			->where($qb->expr()->eq('appid', $qb->createNamedParameter('openregister', IQueryBuilder::PARAM_STR)))
			->andWhere($qb->expr()->eq('configkey', $qb->createNamedParameter($key, IQueryBuilder::PARAM_STR)));
		$result = $qb->executeQuery();
		$raw = $result->fetchOne();
		$result->closeCursor();

		if (is_string($raw) === false) {
			return null;
		}

		return $raw;
	}//end storedRaw()

	/**
	 * The stored lock through the app config, or null when there is none.
	 *
	 * @param string $key The app-config key.
	 *
	 * @return string|null The stored value.
	 */
	private function configRaw(string $key): ?string {
		$raw = $this->appConfig->getValueString('openregister', $key, '');

		if ($raw === '') {
			return null;
		}

		return $raw;
	}//end configRaw()

	/**
	 * Store the new lock only while the row still holds the previous value.
	 *
	 * @param string      $key      The app-config key.
	 * @param string|null $previous The value read; null when no row existed.
	 * @param string      $next     The lock to store.
	 *
	 * @return bool True when exactly this write took the lock.
	 */
	private function swap(string $key, ?string $previous, string $next): bool {
		if ($previous === null) {
			$changed = $this->db->insertIfNotExist(
				'*PREFIX*appconfig',
				['appid' => 'openregister', 'configkey' => $key, 'configvalue' => $next],
				['appid', 'configkey']
			);
		} else {
			$qb = $this->db->getQueryBuilder();
			$qb->update('appconfig')
				->set('configvalue', $qb->createNamedParameter($next, IQueryBuilder::PARAM_STR))
				->where($qb->expr()->eq('appid', $qb->createNamedParameter('openregister', IQueryBuilder::PARAM_STR)))
				->andWhere($qb->expr()->eq('configkey', $qb->createNamedParameter($key, IQueryBuilder::PARAM_STR)))
				->andWhere($qb->expr()->eq('configvalue', $qb->createNamedParameter($previous, IQueryBuilder::PARAM_STR)));
			$changed = $qb->executeStatement();
		}

		if ($changed !== 1) {
			return false;
		}

		// The row was written past the app config: let it read the table again.
		$this->appConfig->clearCache();

		return true;
	}//end swap()

	/**
	 * A stored lock value decoded, or null when there is none or it is unreadable.
	 *
	 * @param string|null $raw The stored value.
	 *
	 * @return array<string, mixed>|null The lock.
	 */
	private static function decoded(?string $raw): ?array {
		if ($raw === null || $raw === '') {
			return null;
		}

		$lock = json_decode($raw, true);

		if (is_array($lock) === false) {
			return null;
		}

		return $lock;
	}//end decoded()

	/**
	 * Whether a stored lock still holds: it has an expiration that is still ahead.
	 *
	 * @param array<string, mixed>|null $lock The lock.
	 * @param DateTime                  $now  The moment of the attempt.
	 *
	 * @return bool
	 */
	private function isLive(?array $lock, DateTime $now): bool {
		return is_array($lock) === true && isset($lock['expiration']) === true && new DateTime($lock['expiration']) > $now;
	}//end isLive()

	/**
	 * Log an acquired advisory lock.
	 *
	 * @param PublishedLockRequest $request The lock request.
	 *
	 * @return void
	 */
	private function logAcquired(PublishedLockRequest $request): void {
		$this->logger->info(
			message: '[AdvisoryLockStore] Advisory (pre-creation) lock acquired',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'identifier' => $request->identifier,
				'process' => $request->process,
			]
		);
	}//end logAcquired()

	/**
	 * The stored payload of an advisory lock taken now, for the default duration when none was asked.
	 *
	 * @param PublishedLockRequest $request The process tag and the duration in seconds.
	 * @param DateTime             $now     The moment the lock is taken.
	 * @param string|null          $ownerId The holder.
	 *
	 * @return array<string, mixed> The lock payload.
	 */
	private function payload(PublishedLockRequest $request, DateTime $now, ?string $ownerId): array {
		$duration = ($request->duration ?? self::DEFAULT_DURATION);
		$expiration = (clone $now)->modify("+{$duration} seconds");

		return [
			'user' => $ownerId,
			'process' => $request->process,
			'created' => $now->format(DateTime::ATOM),
			'duration' => $duration,
			'expiration' => $expiration->format(DateTime::ATOM),
			'advisory' => true,
		];
	}//end payload()

	/**
	 * Refuse while a stored advisory lock has not expired; an unreadable one does not hold.
	 *
	 * @param string   $identifier The locked identifier.
	 * @param DateTime $now        The moment of the attempt.
	 *
	 * @return void
	 *
	 * @throws LockedException When the lock is held.
	 *
	 * @spec openspec/specs/object-interactions/spec.md
	 */
	private function assertNotHeld(string $identifier, DateTime $now): void {
		$existingRaw = $this->appConfig->getValueString('openregister', $this->key(identifier: $identifier), '');
		if ($existingRaw === '') {
			return;
		}

		$existing = json_decode($existingRaw, true);
		if (is_array($existing) === true && isset($existing['expiration']) === true
			&& new DateTime($existing['expiration']) > $now
		) {
			throw new LockedException(message: "Advisory lock '{$identifier}' is already held");
		}
	}//end assertNotHeld()

	/**
	 * Release an advisory lock if one exists for the identifier.
	 *
	 * @param string $identifier The arbitrary advisory-lock identifier.
	 *
	 * @return bool True if an advisory lock was found and removed.
	 *
	 * @spec openspec/specs/object-interactions/spec.md
	 */
	public function release(string $identifier): bool {
		$key = $this->key(identifier: $identifier);
		if ($this->appConfig->getValueString('openregister', $key, '') === '') {
			return false;
		}

		$this->appConfig->deleteKey('openregister', $key);
		$this->logger->info(
			message: '[AdvisoryLockStore] Advisory (pre-creation) lock released',
			context: ['file' => __FILE__, 'line' => __LINE__, 'identifier' => $identifier]
		);

		return true;
	}//end release()
}//end class
