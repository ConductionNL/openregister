<?php

/**
 * VtodoReader
 *
 * Turns a parsed VTODO component into the JSON-friendly task array TaskService returns.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Service\Task
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 *
 * @spec openspec/specs/object-interactions/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Task;

/**
 * Reads the standard fields and the X-OPENREGISTER-* properties of a VTODO.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Task
 *
 * @spec openspec/specs/object-interactions/spec.md
 */
final class VtodoReader {

	/**
	 * The task array of a VTODO.
	 *
	 * @param mixed  $vtodo      The VTODO component from the parsed iCalendar.
	 * @param string $calendarId The calendar ID.
	 * @param string $uri        The calendar object URI.
	 *
	 * @return array<string, mixed> The task.
	 *
	 * @spec openspec/specs/object-interactions/spec.md
	 */
	public function taskArray(mixed $vtodo, string $calendarId, string $uri): array {
		$identity = ['id' => $uri, 'uid' => $this->text(vtodo: $vtodo, property: 'UID'), 'calendarId' => $calendarId];

		return array_merge($identity, $this->vtodoFields(vtodo: $vtodo), $this->openRegisterProperties(vtodo: $vtodo));
	}//end taskArray()

	/**
	 * The standard VTODO fields after the uid, in the order the task array lists them.
	 *
	 * STATUS is lower-cased (NEEDS-ACTION -> needs-action) and defaults to needs-action.
	 *
	 * @param mixed $vtodo The VTODO component.
	 *
	 * @return array{summary: string, description: string, status: string, priority: int,
	 *     due: string|null, completed: string|null, created: string|null}
	 */
	private function vtodoFields(mixed $vtodo): array {
		return [
			'summary' => ($this->text(vtodo: $vtodo, property: 'SUMMARY') ?? ''),
			'description' => ($this->text(vtodo: $vtodo, property: 'DESCRIPTION') ?? ''),
			'status' => strtolower(($this->text(vtodo: $vtodo, property: 'STATUS') ?? 'needs-action')),
			'priority' => (int)($this->text(vtodo: $vtodo, property: 'PRIORITY') ?? 0),
			'due' => $this->date(vtodo: $vtodo, property: 'DUE'),
			'completed' => $this->date(vtodo: $vtodo, property: 'COMPLETED'),
			'created' => $this->date(vtodo: $vtodo, property: 'CREATED'),
		];
	}//end vtodoFields()

	/**
	 * Extract X-OPENREGISTER-* properties from a VTODO component.
	 *
	 * @param mixed $vtodo The VTODO component.
	 *
	 * @return array{objectUuid: string|null, registerId: int|null, schemaId: int|null, fields: array<string, mixed>}
	 */
	private function openRegisterProperties(mixed $vtodo): array {
		return [
			'objectUuid' => $this->text(vtodo: $vtodo, property: 'X-OPENREGISTER-OBJECT'),
			'registerId' => $this->integer(vtodo: $vtodo, property: 'X-OPENREGISTER-REGISTER'),
			'schemaId' => $this->integer(vtodo: $vtodo, property: 'X-OPENREGISTER-SCHEMA'),
			'fields' => $this->dataFields(vtodo: $vtodo),
		];
	}//end openRegisterProperties()

	/**
	 * The non-core schema fields round-tripped in X-OPENREGISTER-DATA.
	 *
	 * They travel as a single base64-encoded JSON blob so any field survives
	 * the VTODO projection without iCal-escaping pitfalls. Decoded best-effort:
	 * anything but a base64 JSON object or list gives no fields.
	 *
	 * @param mixed $vtodo The VTODO component.
	 *
	 * @return array<string, mixed> The fields.
	 */
	private function dataFields(mixed $vtodo): array {
		// Invalid base64 decodes to false, which json_decode() reads as no JSON at all.
		$raw = base64_decode(($this->text(vtodo: $vtodo, property: 'X-OPENREGISTER-DATA') ?? ''), true);
		$decoded = json_decode((string)$raw, true);
		if (is_array($decoded) === true) {
			return $decoded;
		}

		return [];
	}//end dataFields()

	/**
	 * The text of a property, or null when the VTODO does not have it.
	 *
	 * @param mixed  $vtodo    The VTODO component.
	 * @param string $property The property name.
	 *
	 * @return string|null
	 */
	private function text(mixed $vtodo, string $property): ?string {
		if (isset($vtodo->$property) === false) {
			return null;
		}

		return (string)$vtodo->$property;
	}//end text()

	/**
	 * A property as an integer, or null when the VTODO does not have it.
	 *
	 * @param mixed  $vtodo    The VTODO component.
	 * @param string $property The property name.
	 *
	 * @return int|null
	 */
	private function integer(mixed $vtodo, string $property): ?int {
		$text = $this->text(vtodo: $vtodo, property: $property);
		if ($text === null) {
			return null;
		}

		return (int)$text;
	}//end integer()

	/**
	 * A date-time property as an ISO 8601 string, or null when the VTODO does not have it.
	 *
	 * @param mixed  $vtodo    The VTODO component.
	 * @param string $property The property name.
	 *
	 * @return string|null
	 */
	private function date(mixed $vtodo, string $property): ?string {
		if (isset($vtodo->$property) === false) {
			return null;
		}

		return $vtodo->$property->getDateTime()->format('c');
	}//end date()
}//end class
