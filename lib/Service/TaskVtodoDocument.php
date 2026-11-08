<?php

/**
 * TaskVtodoDocument
 *
 * Writes the iCalendar documents of the tasks TaskService stores: the VTODO a
 * new standalone task becomes, the changes a standalone update applies, and
 * the status a projected VTODO's update requests.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Service
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 *
 * @spec openspec/specs/object-interactions/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use DateTime;
use Exception;
use OCA\OpenRegister\Service\Task\VtodoReader;
use Sabre\VObject\Reader;

/**
 * Composes and edits VTODO documents; it reads no calendar and decides no access.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @spec openspec/specs/object-interactions/spec.md
 */
final class TaskVtodoDocument {

	/**
	 * The UTC timestamp format of DTSTAMP, DUE and COMPLETED.
	 */
	private const UTC = 'Ymd\THis\Z';

	/**
	 * Reads VTODO components into task arrays.
	 *
	 * @var VtodoReader
	 */
	private readonly VtodoReader $reader;

	/**
	 * Constructor.
	 *
	 * @return void
	 */
	public function __construct() {
		$this->reader = new VtodoReader();
	}//end __construct()

	/**
	 * Parse a VTODO iCalendar string into a JSON-friendly array.
	 *
	 * @param string $calendarData The raw iCalendar string
	 * @param string $calendarId   The calendar ID
	 * @param string $uri          The calendar object URI
	 *
	 * @return array|null Task array or null if not a VTODO
	 */
	public function taskOf(string $calendarData, string $calendarId, string $uri): ?array {
		$vtodo = Reader::read($calendarData)->VTODO;
		if ($vtodo === null) {
			return null;
		}

		return $this->reader->taskArray(vtodo: $vtodo, calendarId: $calendarId, uri: $uri);
	}//end taskOf()

	/**
	 * The document of a new standalone task linked to an object.
	 *
	 * @param int    $registerId  The register ID to link
	 * @param int    $schemaId    The schema ID to link
	 * @param string $objectUuid  The object UUID to link
	 * @param string $objectTitle The object title for the LINK label
	 * @param array  $data        Task data: summary, description, priority, due, status, fields
	 *
	 * @return array{uri: string, calendarData: string} The object URI and the iCalendar document.
	 *
	 * @throws Exception When the due date cannot be parsed.
	 */
	public function compose(int $registerId, int $schemaId, string $objectUuid, string $objectTitle, array $data): array {
		$uid = strtoupper(bin2hex(random_bytes(16)));
		$lines = array_merge(
			$this->openingLines(uid: $uid, summary: ($data['summary'] ?? 'Untitled task')),
			$this->detailLines(data: $data),
			$this->linkLines(link: [$registerId, $schemaId, $objectUuid], objectTitle: $objectTitle, fields: ($data['fields'] ?? null))
		);

		return ['uri' => $uid . '.ics', 'calendarData' => implode("\r\n", $lines) . "\r\n"];
	}//end compose()

	/**
	 * A standalone VTODO's stored document with the provided fields applied.
	 *
	 * @param mixed $existing The stored iCalendar data.
	 * @param array $data     Fields to update: summary, description, priority, due, status, fields
	 *
	 * @return string The updated document.
	 *
	 * @throws Exception When the document holds no VTODO or a due date cannot be parsed.
	 */
	public function withChanges(mixed $existing, array $data): string {
		$vcalendar = Reader::read($existing);
		$vtodo = $vcalendar->VTODO;
		if ($vtodo === null) {
			throw new Exception('Calendar object is not a VTODO');
		}

		$this->applyChanges(vtodo: $vtodo, data: $data);

		return $vcalendar->serialize();
	}//end withChanges()

	/**
	 * Apply a standalone update's provided fields to a VTODO, in the order they are stored.
	 *
	 * Linking props (REGISTER/SCHEMA/OBJECT) are left untouched so the link and
	 * scoping survive the update.
	 *
	 * @param mixed $vtodo The VTODO component.
	 * @param array $data  Fields to update: summary, description, priority, due, status, fields
	 *
	 * @return void
	 *
	 * @throws Exception When the due date cannot be parsed.
	 */
	private function applyChanges(mixed $vtodo, array $data): void {
		if (isset($data['summary']) === true) {
			$vtodo->SUMMARY = $data['summary'];
		}

		if (isset($data['description']) === true) {
			$vtodo->DESCRIPTION = $data['description'];
		}

		$this->applyStatus(vtodo: $vtodo, data: $data);
		if (isset($data['priority']) === true) {
			$vtodo->PRIORITY = (int)$data['priority'];
		}

		$this->applyDue(vtodo: $vtodo, data: $data);
		$this->applyFields(vtodo: $vtodo, data: $data);
		$vtodo->DTSTAMP = gmdate(self::UTC);
	}//end applyChanges()

	/**
	 * A projected VTODO's document with the requested status in place of its own.
	 *
	 * @param string $existing The stored document.
	 * @param string $status   The requested status.
	 *
	 * @return string The requested document.
	 *
	 * @throws Exception When the document holds no VTODO.
	 */
	public function withStatus(string $existing, string $status): string {
		$vcalendar = Reader::read($existing);
		$vtodo = ($vcalendar->select('VTODO')[0] ?? null);
		if ($vtodo === null) {
			throw new Exception('Calendar object is not a VTODO');
		}

		$vtodo->remove('STATUS');
		$vtodo->add('STATUS', strtoupper($status));

		return $vcalendar->serialize();
	}//end withStatus()

	/**
	 * The calendar, component and identity lines a new task opens with.
	 *
	 * @param string $uid     The task UID.
	 * @param string $summary The unescaped summary.
	 *
	 * @return list<string> The iCalendar lines.
	 */
	private function openingLines(string $uid, string $summary): array {
		$opening = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//OpenRegister//Tasks//EN', 'BEGIN:VTODO'];
		$opening[] = 'UID:' . $uid;
		$opening[] = 'DTSTAMP:' . gmdate(self::UTC);
		$opening[] = 'SUMMARY:' . $this->escape(text: $summary);

		return $opening;
	}//end openingLines()

	/**
	 * The DESCRIPTION, STATUS, PRIORITY and DUE lines of a new task.
	 *
	 * @param array $data Task data.
	 *
	 * @return list<string> The iCalendar lines.
	 *
	 * @throws Exception When the due date cannot be parsed.
	 */
	private function detailLines(array $data): array {
		$details = [];
		if (empty($data['description']) === false) {
			$details[] = 'DESCRIPTION:' . $this->escape(text: $data['description']);
		}

		$details[] = 'STATUS:' . strtoupper($data['status'] ?? 'NEEDS-ACTION');
		$details[] = 'PRIORITY:' . (int)($data['priority'] ?? 0);
		if (empty($data['due']) === false) {
			$details[] = 'DUE:' . (new DateTime($data['due']))->format(self::UTC);
		}

		return $details;
	}//end detailLines()

	/**
	 * The X-OPENREGISTER-* properties, the RFC 9253 LINK and the closing lines of a new task.
	 *
	 * Non-core schema fields (e.g. assignee) are round-tripped as a single
	 * base64-encoded JSON blob so any field survives the VTODO projection
	 * (object-source-providers) without iCal escaping concerns.
	 *
	 * @param array{0: int, 1: int, 2: string} $link        Register ID, schema ID and object UUID.
	 * @param string                           $objectTitle The object title for the LINK label.
	 * @param mixed                            $fields      The non-core schema fields.
	 *
	 * @return list<string> The iCalendar lines.
	 */
	private function linkLines(array $link, string $objectTitle, mixed $fields): array {
		$path = implode('/', $link);
		$tail = ['X-OPENREGISTER-REGISTER:' . $link[0], 'X-OPENREGISTER-SCHEMA:' . $link[1], 'X-OPENREGISTER-OBJECT:' . $link[2]];
		if (empty($fields) === false && is_array($fields) === true) {
			$tail[] = 'X-OPENREGISTER-DATA:' . base64_encode((string)json_encode($fields));
		}

		$tail[] = 'LINK;LINKREL="related";LABEL="' . $this->escape(text: $objectTitle) . '";VALUE=URI:/apps/openregister/api/objects/' . $path;
		$tail[] = 'END:VTODO';
		$tail[] = 'END:VCALENDAR';

		return $tail;
	}//end linkLines()

	/**
	 * Set STATUS, and COMPLETED when the status completes the task.
	 *
	 * @param mixed $vtodo The VTODO component.
	 * @param array $data  The requested changes.
	 *
	 * @return void
	 */
	private function applyStatus(mixed $vtodo, array $data): void {
		if (isset($data['status']) === false) {
			return;
		}

		$vtodo->STATUS = strtoupper($data['status']);
		if (strtoupper($data['status']) === 'COMPLETED') {
			$vtodo->COMPLETED = gmdate(self::UTC);
		}
	}//end applyStatus()

	/**
	 * Clear DUE on an empty due, or set it from a given one.
	 *
	 * @param mixed $vtodo The VTODO component.
	 * @param array $data  The requested changes.
	 *
	 * @return void
	 *
	 * @throws Exception When the due date cannot be parsed.
	 */
	private function applyDue(mixed $vtodo, array $data): void {
		if (isset($data['due']) === false) {
			return;
		}

		if (empty($data['due']) === true) {
			unset($vtodo->DUE);
			return;
		}

		$vtodo->DUE = new DateTime($data['due']);
	}//end applyDue()

	/**
	 * Replace the X-OPENREGISTER-DATA blob when fields are given as an array.
	 *
	 * So a projected object's non-core fields (e.g. assignee, taskStatus)
	 * update faithfully (object-source-providers).
	 *
	 * @param mixed $vtodo The VTODO component.
	 * @param array $data  The requested changes.
	 *
	 * @return void
	 */
	private function applyFields(mixed $vtodo, array $data): void {
		if (isset($data['fields']) === false || is_array($data['fields']) === false) {
			return;
		}

		unset($vtodo->{'X-OPENREGISTER-DATA'});
		if (empty($data['fields']) === false) {
			$vtodo->add('X-OPENREGISTER-DATA', base64_encode((string)json_encode($data['fields'])));
		}
	}//end applyFields()

	/**
	 * Escape text for use in iCalendar property values.
	 *
	 * @param string $text The text to escape
	 *
	 * @return string The escaped text
	 */
	private function escape(string $text): string {
		return strtr($text, ['\\' => '\\\\', "\n" => '\\n', ',' => '\\,', ';' => '\\;']);
	}//end escape()
}//end class
