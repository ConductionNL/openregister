<?php

/**
 * Who has an object open, on a heartbeat rather than a connection.
 *
 * 🔴 notify_push TELLS THE SERVER NOTHING ABOUT WHO IS LOOKING AT WHAT (D-1).
 * It is a delivery channel, not a session register: a socket can be open while
 * the tab showing this object was closed ten minutes ago, and a socket can drop
 * while the reader is still there. So presence is a heartbeat the client sends
 * every 30 seconds and a window the server expires after 90, which is what Deck
 * does and what survives a lost socket. Missing two beats reads as gone; a
 * shorter window would make a slow network look like a departure.
 *
 * 🔴 A PUSH ON EVERY BEAT WOULD BE THE FEATURE PAYING FOR ITSELF IN NOISE
 * (D-2). Twenty readers on one page is twenty writes a minute, which is
 * nothing, and would be twenty pushes a minute to twenty clients, which is not.
 * So the push fires on ARRIVAL, on DEPARTURE and on EXPIRY, and a renewal that
 * changes nothing is silent. {@see heartbeat()} returns whether it was an
 * arrival precisely so the caller cannot get that wrong.
 *
 * 🔴 PRESENCE NEVER REVEALS AN OBJECT (D-3). The list is served to whoever may
 * read the object and the push goes to the users `PermissionHandler` resolves
 * for it, which is the same rule the object itself is under. A presence list
 * that answered more widely than the object would be a way to learn that an
 * object exists, and who is interested in it, without being allowed to read it.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/object-presence/specs/realtime-updates/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\OpenRegister\Db\ObjectPresence;
use OCA\OpenRegister\Db\ObjectPresenceMapper;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Record, expire and list who has an object open.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/object-presence/specs/realtime-updates/spec.md
 */
class PresenceService {

	/**
	 * How often a client is expected to say it is still there, in seconds.
	 *
	 * @var int
	 */
	public const BEAT_SECONDS = 30;

	/**
	 * How long a reader is believed after their last beat, in seconds.
	 *
	 * 🔑 THREE BEATS, NOT TWO. Ninety seconds means a reader survives ONE lost
	 * beat and goes after two, which is the difference between a tab that
	 * flickers off the list on every hiccup and one that leaves when it leaves.
	 * The window is written down HERE and read from here by the list, the sweep
	 * and the tests, so there is one number rather than three that drift.
	 *
	 * @var int
	 */
	public const WINDOW_SECONDS = 90;

	/**
	 * Constructor.
	 *
	 * @param ObjectPresenceMapper $presence The presence rows.
	 * @param LoggerInterface      $logger   The logger.
	 */
	public function __construct(
		private readonly ObjectPresenceMapper $presence,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Say that a reader is still looking at an object.
	 *
	 * @param string                 $userId     The reader.
	 * @param string                 $objectUuid The object.
	 * @param DateTimeInterface|null $now        The clock, for tests.
	 *
	 * @return array{arrived: bool, presence: ObjectPresence|null} Whether this was an ARRIVAL.
	 *
	 * @spec openspec/changes/object-presence/specs/realtime-updates/spec.md#requirement-an-object-knows-who-has-it-open
	 */
	public function heartbeat(string $userId, string $objectUuid, ?DateTimeInterface $now = null): array {
		$userId = trim($userId);
		$objectUuid = trim($objectUuid);
		if ($userId === '' || $objectUuid === '') {
			return ['arrived' => false, 'presence' => null];
		}

		$now = ($now ?? new DateTimeImmutable());
		$existing = $this->presence->findOne(userId: $userId, objectUuid: $objectUuid);

		// 🔴 AN EXPIRED ROW IS AN ARRIVAL, NOT A RENEWAL. A reader whose laptop
		// slept for an hour comes back as somebody arriving, because to every
		// other reader on the page that is exactly what happened: they had gone
		// from the list, and they are now on it again. Treating it as a renewal
		// would leave them permanently invisible to everybody who was pushed
		// their departure.
		$arrived = ($existing === null || $this->isStale(row: $existing, now: $now) === true);

		$row = ($existing ?? new ObjectPresence());
		$row->setUserId($userId);
		$row->setObjectUuid($objectUuid);
		if ($arrived === true) {
			$row->setArrivedAt($this->asMutable(moment: $now));
		}

		$row->setLastSeen($this->asMutable(moment: $now));

		try {
			$saved = (($existing === null) ? $this->presence->insert($row) : $this->presence->update($row));
		} catch (Throwable $e) {
			// A beat that could not be written is not worth failing a page
			// over: the reader simply drops off the list in 90 seconds, which
			// is the same outcome as a lost network. Said out loud so a table
			// that is refusing every write is visible.
			$this->logger->warning(
				message: '[PresenceService] a heartbeat could not be written: ' . $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__, 'object' => $objectUuid]
			);

			return ['arrived' => false, 'presence' => null];
		}

		return ['arrived' => $arrived, 'presence' => $saved];
	}//end heartbeat()

	/**
	 * Say that a reader has closed the object.
	 *
	 * @param string $userId     The reader.
	 * @param string $objectUuid The object.
	 *
	 * @return boolean True when they were present and are now not.
	 *
	 * @spec openspec/changes/object-presence/specs/realtime-updates/spec.md#requirement-an-object-knows-who-has-it-open
	 */
	public function depart(string $userId, string $objectUuid): bool {
		if (trim($userId) === '' || trim($objectUuid) === '') {
			return false;
		}

		try {
			return $this->presence->removeOne(userId: $userId, objectUuid: $objectUuid);
		} catch (Throwable $e) {
			$this->logger->warning(
				message: '[PresenceService] a departure could not be written: ' . $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__, 'object' => $objectUuid]
			);

			return false;
		}
	}//end depart()

	/**
	 * Who is present on an object right now.
	 *
	 * @param string                 $objectUuid The object.
	 * @param string                 $exceptUser A reader to leave out, usually the caller.
	 * @param DateTimeInterface|null $now        The clock, for tests.
	 *
	 * @return array<int, array<string, mixed>> The readers, oldest arrival first.
	 *
	 * @spec openspec/changes/object-presence/specs/realtime-updates/spec.md#requirement-an-object-knows-who-has-it-open
	 */
	public function present(string $objectUuid, string $exceptUser = '', ?DateTimeInterface $now = null): array {
		$now = ($now ?? new DateTimeImmutable());

		try {
			$rows = $this->presence->findPresent(
				objectUuid: $objectUuid,
				notBefore: $this->asMutable(moment: $this->cutoff(now: $now))
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				message: '[PresenceService] the presence of an object could not be read: ' . $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__, 'object' => $objectUuid]
			);

			return [];
		}

		$present = [];
		foreach ($rows as $row) {
			if ($exceptUser !== '' && (string)$row->getUserId() === $exceptUser) {
				continue;
			}

			$present[] = $row->jsonSerialize();
		}

		return $present;
	}//end present()

	/**
	 * Drop every reader whose beats stopped, answering who went.
	 *
	 * The stale rows are READ before they are deleted, because a departure has
	 * to be pushed and a row already gone cannot say who to push about. That is
	 * the whole reason this is not a one-line DELETE.
	 *
	 * @param DateTimeInterface|null $now The clock, for tests.
	 *
	 * @return array<int, array<string, mixed>> The readers who expired, with their objects.
	 *
	 * @spec openspec/changes/object-presence/specs/realtime-updates/spec.md#requirement-presence-changes-are-pushed-not-polled
	 */
	public function expire(?DateTimeInterface $now = null): array {
		$now = ($now ?? new DateTimeImmutable());
		$cutoff = $this->asMutable(moment: $this->cutoff(now: $now));

		try {
			$stale = $this->presence->findStale(before: $cutoff);
			if ($stale === []) {
				return [];
			}

			$this->presence->pruneStale(before: $cutoff);
		} catch (Throwable $e) {
			$this->logger->warning(
				message: '[PresenceService] stale presence could not be swept: ' . $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__]
			);

			return [];
		}

		$gone = [];
		foreach ($stale as $row) {
			$gone[] = ['user' => (string)$row->getUserId(), 'object' => (string)$row->getObjectUuid()];
		}

		return $gone;
	}//end expire()

	/**
	 * The oldest heartbeat still believed.
	 *
	 * @param DateTimeInterface $now The clock.
	 *
	 * @return DateTimeImmutable The cutoff.
	 */
	public function cutoff(DateTimeInterface $now): DateTimeImmutable {
		return (new DateTimeImmutable('@' . $now->getTimestamp()))
			->modify('-' . self::WINDOW_SECONDS . ' seconds');
	}//end cutoff()

	/**
	 * Whether a row's last beat is outside the window.
	 *
	 * @param ObjectPresence    $row The row.
	 * @param DateTimeInterface $now The clock.
	 *
	 * @return boolean True when it is stale.
	 */
	private function isStale(ObjectPresence $row, DateTimeInterface $now): bool {
		$lastSeen = $row->getLastSeen();
		if ($lastSeen === null) {
			return true;
		}

		return ($lastSeen->getTimestamp() < $this->cutoff(now: $now)->getTimestamp());
	}//end isStale()

	/**
	 * A mutable DateTime, which is what the entity's type and the query builder take.
	 *
	 * @param DateTimeInterface $moment The moment.
	 *
	 * @return \DateTime The same instant.
	 */
	private function asMutable(DateTimeInterface $moment): \DateTime {
		return (new \DateTime())->setTimestamp($moment->getTimestamp());
	}//end asMutable()
}//end class
