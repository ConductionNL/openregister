<?php

/**
 * OpenRegister EntriesClosedException
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Exception
 * @package  OCA\OpenRegister\Exception
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/object-archive-state/specs/object-lifecycle/spec.md#requirement-a-record-closes-to-new-entries-and-an-entry-can-be-withdrawn-or-locked-req-oas-006
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Exception;

/**
 * A new timeline entry or note refused because the object is closed to new entries.
 *
 * A 409, like the other state refusals, so a client keeps one code for "the record's state says no".
 *
 * @spec openspec/changes/object-archive-state/specs/object-lifecycle/spec.md#requirement-a-record-closes-to-new-entries-and-an-entry-can-be-withdrawn-or-locked-req-oas-006
 */
class EntriesClosedException extends NoteWriteRefusedException {

	/**
	 * The HTTP status this refusal maps to.
	 *
	 * @var integer
	 */
	public const HTTP_STATUS = 409;

	/**
	 * The closure that refused the entry.
	 *
	 * @var array{by: string, at: string|null, reason: string}
	 */
	private array $closure;

	/**
	 * Name the closure in the message.
	 *
	 * @param array{by: string, at: string|null, reason: string} $closure Who closed the object, when and why.
	 *
	 * @spec openspec/changes/object-archive-state/specs/object-lifecycle/spec.md#requirement-a-record-closes-to-new-entries-and-an-entry-can-be-withdrawn-or-locked-req-oas-006
	 */
	public function __construct(array $closure) {
		$this->closure = $closure;
		parent::__construct(
			message: sprintf(
				'This record is closed to new entries since %s by %s: %s',
				(string)($closure['at'] ?? ''),
				(string)($closure['by'] ?? ''),
				(string)($closure['reason'] ?? '')
			)
		);
	}//end __construct()

	/**
	 * The answer body: the message, the state and the closure.
	 *
	 * @return array{error: string, state: string, by: string, at: string|null, reason: string}
	 *
	 * @spec openspec/changes/object-archive-state/specs/object-lifecycle/spec.md#requirement-a-record-closes-to-new-entries-and-an-entry-can-be-withdrawn-or-locked-req-oas-006
	 */
	public function toResponseBody(): array {
		return [
			'error'  => $this->getMessage(),
			'state'  => 'closed',
			'by'     => $this->closure['by'],
			'at'     => $this->closure['at'],
			'reason' => $this->closure['reason'],
		];
	}//end toResponseBody()
}//end class
