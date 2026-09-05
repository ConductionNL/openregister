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
use OCP\IAppConfig;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

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
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
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
		return self::PREFIX . md5($identifier);
	}//end key()

	/**
	 * Acquire an advisory lock for an identifier that does not (yet) resolve
	 * to a stored object.
	 *
	 * A still-valid lock raises LockedException; an expired one is silently
	 * overwritten.
	 *
	 * @param string $identifier The arbitrary advisory-lock identifier.
	 * @param string|null $process Optional process tag (who holds the lock).
	 * @param int|null $duration Lock duration in seconds.
	 *
	 * @return array{uuid: string, locked: array<string, mixed>} Advisory lock result.
	 *
	 * @throws LockedException If a non-expired advisory lock already exists.
	 *
	 * @spec openspec/specs/object-interactions/spec.md
	 */
	public function acquire(string $identifier, ?string $process = null, ?int $duration = null): array {
		$duration = ($duration ?? self::DEFAULT_DURATION);
		$key = $this->key(identifier: $identifier);
		$now = new DateTime();

		$existingRaw = $this->appConfig->getValueString('openregister', $key, '');
		if ($existingRaw !== '') {
			$existing = json_decode($existingRaw, true);
			if (is_array($existing) === true && isset($existing['expiration']) === true) {
				$expiration = new DateTime($existing['expiration']);
				if ($expiration > $now) {
					throw new LockedException(message: "Advisory lock '{$identifier}' is already held");
				}
			}
		}

		$expiration = (clone $now)->modify("+{$duration} seconds");
		$lock = [
			'user' => $this->userSession->getUser()?->getUID(),
			'process' => $process,
			'created' => $now->format(DateTime::ATOM),
			'duration' => $duration,
			'expiration' => $expiration->format(DateTime::ATOM),
			'advisory' => true,
		];

		$this->appConfig->setValueString('openregister', $key, json_encode($lock));

		$this->logger->info(
			message: '[AdvisoryLockStore] Advisory (pre-creation) lock acquired',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'identifier' => $identifier,
				'process' => $process,
			]
		);

		return ['uuid' => $identifier, 'locked' => $lock];
	}//end acquire()

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
