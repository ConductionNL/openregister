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
use OCA\OpenRegister\Kernel\Contracts\LinkedTaskDraft;
use OCA\OpenRegister\Kernel\Contracts\ObjectRef;
use OCA\OpenRegister\Kernel\Contracts\TaskLocation;
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
	 * @param string       $calendarData The raw iCalendar string
	 * @param TaskLocation $at           The calendar and URI of the calendar object
	 *
	 * @return array|null Task array or null if not a VTODO
	 */
	public function taskOf(string $calendarData, TaskLocation $at): ?array {
		$vtodo = Reader::read($calendarData)->VTODO;
		if ($vtodo === null) {
			return null;
		}

		return $this->reader->taskArray(vtodo: $vtodo, at: $at);
	}//end taskOf()

	/**
	 * The document of a new standalone task linked to an object.
	 *
	 * @param ObjectRef       $ref   The register ID, schema ID and UUID of the object to link.
	 * @param LinkedTaskDraft $draft The object title for the LINK label, and the task data.
	 *
	 * @return array{uri: string, calendarData: string} The object URI and the iCalendar document.
	 *
	 * @throws Exception When the due date cannot be parsed.
	 *
	 * @spec openspec/specs/object-interactions/spec.md
	 */
	public function composeFor(ObjectRef $ref, LinkedTaskDraft $draft): array {
		$uid = strtoupper(bin2hex(random_bytes(16)));
		$link = [$ref->register, $ref->schema, $ref->id];
		return ['uri' => $uid . '.ics', 'calendarData' => implode("\r\n", [
			...$this->openingLines(uid: $uid, summary: ($draft->data['summary'] ?? 'Untitled task')),
			...$this->detailLines(data: $draft->data),
			...$this->propertyLines(link: $link, fields: ($draft->data['fields'] ?? null)),
			...$this->closingLines(link: $link, objectTitle: $draft->objectTitle),
		]) . "\r\n"];
	}//end composeFor()

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
	 * The X-OPENREGISTER-* properties of a new task.
	 *
	 * Non-core schema fields (e.g. assignee) are round-tripped as a single
	 * base64-encoded JSON blob so any field survives the VTODO projection
	 * (object-source-providers) without iCal escaping concerns.
	 *
	 * @param array{0: int, 1: int, 2: string} $link   Register ID, schema ID and object UUID.
	 * @param mixed                            $fields The non-core schema fields.
	 *
	 * @return list<string> The iCalendar lines.
	 */
	private function propertyLines(array $link, mixed $fields): array {
		$lines = ['X-OPENREGISTER-REGISTER:' . $link[0], 'X-OPENREGISTER-SCHEMA:' . $link[1], 'X-OPENREGISTER-OBJECT:' . $link[2]];
		if (empty($fields) === false && is_array($fields) === true) {
			$lines[] = 'X-OPENREGISTER-DATA:' . base64_encode((string)json_encode($fields));
		}

		return $lines;
	}//end propertyLines()

	/**
	 * The RFC 9253 LINK and the closing lines of a new task.
	 *
	 * @param array{0: int, 1: int, 2: string} $link        Register ID, schema ID and object UUID.
	 * @param string                           $objectTitle The object title for the LINK label.
	 *
	 * @return list<string> The iCalendar lines.
	 */
	private function closingLines(array $link, string $objectTitle): array {
		return [
			'LINK;LINKREL="related";LABEL="' . $this->escape(text: $objectTitle) . '";VALUE=URI:/apps/openregister/api/objects/' . implode('/', $link),
			'END:VTODO',
			'END:VCALENDAR',
		];
	}//end closingLines()

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
