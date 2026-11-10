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
 * read the object and the push goes to the users `PermissionHandlerInterface` resolves
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

namespace OCA\OpenRegister\Notifications\Service;

use DateTime;
use DateTimeImmutable;
use DateTimeInterface;
use OCA\OpenRegister\Notifications\Db\ObjectPresence;
use OCA\OpenRegister\Notifications\Db\ObjectPresenceMapper;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Record, expire and list who has an object open.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/object-presence/specs/realtime-updates/spec.md
 */
class PresenceService implements PresenceServiceInterface {

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
	 * @param PresenceBeat $beat The reader, the object and the moment.
	 *
	 * @return array{arrived: bool, presence: ObjectPresence|null} Whether this was an ARRIVAL.
	 *
	 * @spec openspec/changes/object-presence/specs/realtime-updates/spec.md#requirement-an-object-knows-who-has-it-open
	 */
	public function heartbeat(PresenceBeat $beat): array {
		$beat = $beat->trimmed();
		if ($beat->userId === '' || $beat->objectUuid === '') {
			return ['arrived' => false, 'presence' => null];
		}

		return $this->recordBeat(beat: $beat);
	}//end heartbeat()

	/**
	 * Record one beat: renew the reader's row, or start it when they arrive.
	 *
	 * @param PresenceBeat $beat The reader, the object and the moment.
	 *
	 * @return array{arrived: bool, presence: ObjectPresence|null} Whether this was an ARRIVAL.
	 */
	private function recordBeat(PresenceBeat $beat): array {
		$existing = $this->presence->findOne(userId: $beat->userId, objectUuid: $beat->objectUuid);

		// 🔴 AN EXPIRED ROW IS AN ARRIVAL, NOT A RENEWAL. A reader whose laptop
		// slept for an hour comes back as somebody arriving, because to every
		// other reader on the page that is exactly what happened: they had gone
		// from the list, and they are now on it again. Treating it as a renewal
		// would leave them permanently invisible to everybody who was pushed
		// their departure.
		$arrived = ($existing === null || $this->isStale(row: $existing, now: $beat->now) === true);

		$row = $this->stamped(beat: $beat, row: ($existing ?? new ObjectPresence()));
		if ($arrived === true) {
			$row->setArrivedAt($this->asMutable(moment: $beat->now));
		}

		$saved = $this->storeBeat(row: $row, isNew: ($existing === null));
		if ($saved === null) {
			return ['arrived' => false, 'presence' => null];
		}

		return ['arrived' => $arrived, 'presence' => $saved];
	}//end recordBeat()

	/**
	 * The row with the beat's reader, object and moment of last sight on it.
	 *
	 * @param PresenceBeat   $beat The reader, the object and the moment.
	 * @param ObjectPresence $row  The reader's row, stored or new.
	 *
	 * @return ObjectPresence The same row.
	 */
	private function stamped(PresenceBeat $beat, ObjectPresence $row): ObjectPresence {
		$row->setUserId($beat->userId);
		$row->setObjectUuid($beat->objectUuid);
		$row->setLastSeen($this->asMutable(moment: $beat->now));

		return $row;
	}//end stamped()

	/**
	 * Write a beat's row, or report that it could not be written.
	 *
	 * @param ObjectPresence $row   The row to write.
	 * @param boolean        $isNew Whether there was no row before.
	 *
	 * @return ObjectPresence|null The stored row, or null when the write failed.
	 */
	private function storeBeat(ObjectPresence $row, bool $isNew): ?ObjectPresence {
		try {
			return $this->store(row: $row, isNew: $isNew);
		} catch (Throwable $e) {
			// A beat that could not be written is not worth failing a page
			// over: the reader simply drops off the list in 90 seconds, which
			// is the same outcome as a lost network. Said out loud so a table
			// that is refusing every write is visible.
			$this->logger->warning(
				message: '[PresenceService] a heartbeat could not be written: ' . $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__, 'object' => $row->getObjectUuid()]
			);

			return null;
		}
	}//end storeBeat()

	/**
	 * Write the row, as an insert or an update.
	 *
	 * Split out so the choice is a return rather than a branch assigning the
	 * same variable twice: the mapper has two methods and one of them has to
	 * be picked, and doing it here keeps the heartbeat readable.
	 *
	 * @param ObjectPresence $row   The row to write.
	 * @param boolean        $isNew Whether there was no row before.
	 *
	 * @return ObjectPresence The stored row.
	 *
	 * @spec openspec/changes/object-presence/specs/realtime-updates/spec.md#requirement-an-object-knows-who-has-it-open
	 */
	private function store(ObjectPresence $row, bool $isNew): ObjectPresence {
		if ($isNew === true) {
			return $this->presence->insert($row);
		}

		return $this->presence->update($row);
	}//end store()

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
	 * @param PresenceLookup $lookup The object, a reader to leave out, and the clock for tests.
	 *
	 * @return array<int, array<string, mixed>> The readers, oldest arrival first.
	 *
	 * @spec openspec/changes/object-presence/specs/realtime-updates/spec.md#requirement-an-object-knows-who-has-it-open
	 */
	public function present(PresenceLookup $lookup): array {
		$rows = $this->presentRows(objectUuid: $lookup->objectUuid, now: ($lookup->now ?? new DateTimeImmutable()));

		return $this->readersOtherThan(rows: $rows, exceptUser: $lookup->exceptUser);
	}//end present()

	/**
	 * The rows of the readers whose last beat is inside the window.
	 *
	 * @param string            $objectUuid The object.
	 * @param DateTimeInterface $now        The clock.
	 *
	 * @return array<int, ObjectPresence> The rows, or none when they could not be read.
	 */
	private function presentRows(string $objectUuid, DateTimeInterface $now): array {
		try {
			return $this->presence->findPresent(
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
	}//end presentRows()

	/**
	 * The rows as readers, leaving one reader out.
	 *
	 * @param array<int, ObjectPresence> $rows       The rows.
	 * @param string                     $exceptUser A reader to leave out, or '' for none.
	 *
	 * @return array<int, array<string, mixed>> The readers, in row order.
	 */
	private function readersOtherThan(array $rows, string $exceptUser): array {
		$present = [];
		foreach ($rows as $row) {
			if ($exceptUser !== '' && (string)$row->getUserId() === $exceptUser) {
				continue;
			}

			$present[] = $row->jsonSerialize();
		}

		return $present;
	}//end readersOtherThan()

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
	 *
	 * @spec openspec/changes/object-presence/specs/realtime-updates/spec.md
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
	 * @return DateTime The same instant.
	 */
	private function asMutable(DateTimeInterface $moment): DateTime {
		return (new DateTime())->setTimestamp($moment->getTimestamp());
	}//end asMutable()
}//end class
