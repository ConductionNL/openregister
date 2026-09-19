<?php

/**
 * Records who is coming, on the record rather than in one person's calendar.
 *
 * An invitation collects its answers in the organiser's calendar, where the
 * case system cannot read them: "who is attending the hoorzitting" then
 * depends on one person being available to look. So for an appointment
 * created from an object, each response is written back onto that object as
 * it arrives, naming the responder and the moment of the answer. A later
 * reader sees the attendance with the record, and the feed publishes each
 * invitee's PARTSTAT from the same place.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Calendar
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/object-dates-as-a-calendar-feed/specs/calendar-provider/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Calendar;

use DateTime;
use DateTimeInterface;
use InvalidArgumentException;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;

/**
 * Reads and writes attendee answers on an object.
 *
 * @spec openspec/specs/calendar-provider/spec.md#requirement-schema-calendar-configuration
 */
class AppointmentAttendeeService {

	/**
	 * The object property the answers are stored under.
	 *
	 * @var string
	 */
	public const RESPONSES_PROPERTY = 'attendeeResponses';

	/**
	 * The invitee has accepted.
	 *
	 * @var string
	 */
	public const STATUS_ACCEPTED = 'ACCEPTED';

	/**
	 * The invitee has declined.
	 *
	 * @var string
	 */
	public const STATUS_DECLINED = 'DECLINED';

	/**
	 * The invitee has answered tentatively.
	 *
	 * @var string
	 */
	public const STATUS_TENTATIVE = 'TENTATIVE';

	/**
	 * The invitee has not answered yet.
	 *
	 * @var string
	 */
	public const STATUS_NEEDS_ACTION = 'NEEDS-ACTION';

	/**
	 * The answers an invitee may give, in iCalendar PARTSTAT spelling.
	 *
	 * @var array<int, string>
	 */
	public const STATUSES = [
		self::STATUS_ACCEPTED,
		self::STATUS_DECLINED,
		self::STATUS_TENTATIVE,
		self::STATUS_NEEDS_ACTION,
	];

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objects The object read and write path.
	 */
	public function __construct(
		private readonly ObjectService $objects,
	) {

	}//end __construct()

	/**
	 * Whether the calling principal may read this object at all.
	 *
	 * The answer comes from an RBAC-checked read, so it is the object rules
	 * deciding and not a second copy of them. Callers use it to refuse before
	 * they read or write, and they refuse with a 404: a 403 on an object the
	 * caller may not see would confirm that the uuid exists.
	 *
	 * Shaped after `CaseAnchorReader::mayRead()`, which is how the rest of the
	 * app asks this question.
	 *
	 * @param string $objectUuid The object the appointment was created from.
	 *
	 * @return boolean True only when the RBAC-checked read succeeds.
	 *
	 * @spec openspec/changes/object-dates-as-a-calendar-feed/specs/calendar-provider/spec.md
	 */
	public function mayRead(string $objectUuid): bool {
		try {
			return $this->objects->find(
				id: $objectUuid,
				_render: false,
				_audit: false
			) !== null;
		} catch (\Throwable $denied) {
			unset($denied);

			return false;
		}
	}//end mayRead()

	/**
	 * Record one invitee's answer on the object.
	 *
	 * The write goes through the ordinary object save path with RBAC on, so a
	 * caller who may not write the object cannot record an answer on it.
	 * A second answer from the same invitee replaces the first: an attendance
	 * list is a current state, not a log of everyone's changes of mind.
	 *
	 * @param string $objectUuid The object the appointment was created from.
	 * @param string $attendee The invitee identifier, as the object stores it.
	 * @param string $status One of STATUSES.
	 * @param string|null $respondedBy The principal that submitted the answer.
	 * @param DateTimeInterface|null $respondedAt When the answer arrived; defaults to now.
	 *
	 * @return array<int, array<string, mixed>> The full response list after the write.
	 *
	 * @throws InvalidArgumentException When the status is not an answer, or the object is unknown.
	 *
	 * @spec openspec/changes/object-dates-as-a-calendar-feed/specs/calendar-provider/spec.md
	 */
	public function recordResponse(
		string $objectUuid,
		string $attendee,
		string $status,
		?string $respondedBy = null,
		?DateTimeInterface $respondedAt = null,
	): array {
		$attendee = trim($attendee);
		if ($attendee === '') {
			throw new InvalidArgumentException('An attendee response must name the attendee.');
		}

		$status = strtoupper(trim($status));
		if (in_array($status, self::STATUSES, true) === false) {
			throw new InvalidArgumentException(
				sprintf(
					"'%s' is not an attendee answer: use %s.",
					$status,
					implode(', ', self::STATUSES)
				)
			);
		}

		$object = $this->objects->find(id: $objectUuid);
		if ($object === null) {
			throw new InvalidArgumentException(
				sprintf("No object '%s' to record an attendee response on.", $objectUuid)
			);
		}

		$moment = $respondedAt;
		if ($moment === null) {
			$moment = new DateTime();
		}

		$data = $object->getObject();
		$responses = $this->normalise(value: ($data[self::RESPONSES_PROPERTY] ?? null));

		$responses = array_values(
			array_filter(
				$responses,
				static function (array $response) use ($attendee): bool {
					return ($response['attendee'] ?? null) !== $attendee;
				}
			)
		);

		$responses[] = [
			'attendee' => $attendee,
			'status' => $status,
			'respondedBy' => $respondedBy,
			'respondedAt' => $moment->format('c'),
		];

		$data[self::RESPONSES_PROPERTY] = $responses;

		$this->objects->saveObject(
			object: $data,
			uuid: (string)$object->getUuid(),
			register: $object->getRegister(),
			schema: $object->getSchema()
		);

		return $responses;
	}//end recordResponse()

	/**
	 * The answers recorded on an object, as stored.
	 *
	 * @param string $objectUuid The object.
	 *
	 * @return array<int, array<string, mixed>> The responses, possibly empty.
	 *
	 * @throws InvalidArgumentException When the object is unknown.
	 *
	 * @spec openspec/changes/object-dates-as-a-calendar-feed/specs/calendar-provider/spec.md
	 */
	public function responsesFor(string $objectUuid): array {
		$object = $this->objects->find(id: $objectUuid);

		if ($object === null) {
			throw new InvalidArgumentException(
				sprintf("No object '%s' to read attendee responses from.", $objectUuid)
			);
		}

		return $this->responsesOn(object: $object);
	}//end responsesFor()

	/**
	 * The answers recorded on an object already in hand.
	 *
	 * @param ObjectEntity $object The object.
	 *
	 * @return array<int, array<string, mixed>> The responses.
	 *
	 * @spec openspec/changes/object-dates-as-a-calendar-feed/specs/calendar-provider/spec.md
	 */
	public function responsesOn(ObjectEntity $object): array {
		$data = $object->getObject();

		return $this->normalise(value: ($data[self::RESPONSES_PROPERTY] ?? null));
	}//end responsesOn()

	/**
	 * The answers on an object, keyed by attendee, for a PARTSTAT lookup.
	 *
	 * @param ObjectEntity $object The object.
	 *
	 * @return array<string, array<string, mixed>> The responses by attendee.
	 *
	 * @spec openspec/changes/object-dates-as-a-calendar-feed/specs/calendar-provider/spec.md
	 */
	public function responsesByAttendee(ObjectEntity $object): array {
		$keyed = [];

		foreach ($this->responsesOn(object: $object) as $response) {
			$attendee = ($response['attendee'] ?? null);
			if (is_string($attendee) === false || $attendee === '') {
				continue;
			}

			$keyed[$attendee] = $response;
		}

		return $keyed;
	}//end responsesByAttendee()

	/**
	 * Keep only the entries that are readable responses.
	 *
	 * @param mixed $value The stored value.
	 *
	 * @return array<int, array<string, mixed>> The responses.
	 */
	private function normalise(mixed $value): array {
		if (is_array($value) === false) {
			return [];
		}

		$responses = [];
		foreach ($value as $entry) {
			if (is_array($entry) === false) {
				continue;
			}

			$attendee = ($entry['attendee'] ?? null);
			if (is_string($attendee) === false || trim($attendee) === '') {
				continue;
			}

			$responses[] = $entry;
		}

		return $responses;
	}//end normalise()
}//end class
