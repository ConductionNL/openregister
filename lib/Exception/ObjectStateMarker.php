<?php

/**
 * OpenRegister ObjectStateMarker
 *
 * Reads an archive or freeze marker and words the refusal it causes.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Exception
 * @package  OCA\OpenRegister\Exception
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Exception;

/**
 * One stored state marker (`by`, `at`, `reason`, and for a freeze `state`).
 *
 * A value that is not a non-empty string reads as absent, so a half-written
 * marker still produces a readable sentence instead of a type error.
 */
final class ObjectStateMarker {

	/**
	 * The stored marker.
	 *
	 * @var array<string, mixed>
	 */
	private array $marker;

	/**
	 * Wrap one stored marker.
	 *
	 * @param array<string, mixed> $marker The marker as stored on the object.
	 */
	public function __construct(array $marker) {
		$this->marker = $marker;
	}//end __construct()

	/**
	 * One marker field, or null when it is missing, empty or not a string.
	 *
	 * @param string $key The field name.
	 *
	 * @return string|null The field's value.
	 */
	public function field(string $key): ?string {
		$value = ($this->marker[$key] ?? null);
		if (is_string($value) === false || $value === '') {
			return null;
		}

		return $value;
	}//end field()

	/**
	 * The refusal sentence of an archived object.
	 *
	 * @return string The sentence.
	 */
	public function archivedMessage(): string {
		return $this->sentence(state: 'archived', because: '') . 'Restore it from the archive before changing it.';
	}//end archivedMessage()

	/**
	 * The refusal sentence of a frozen object, naming the state it froze on.
	 *
	 * @return string The sentence.
	 */
	public function frozenMessage(): string {
		$because = '';
		$entered = $this->field(key: 'state');
		if ($entered !== null) {
			$because = ' on entering the state "' . $entered . '"';
		}

		return $this->sentence(state: 'frozen', because: $because) . 'Unfreeze it before changing it.';
	}//end frozenMessage()

	/**
	 * The shared opening of both refusals, up to and including its full stop.
	 *
	 * @param string $state The state word.
	 * @param string $because The optional clause after the date.
	 *
	 * @return string The opening.
	 */
	/**
	 * The sentence a preserved object's content-write refusal carries.
	 *
	 * @return string The message.
	 *
	 * @spec openspec/changes/archiving-as-a-process-with-sign-off/specs/object-lifecycle/spec.md#requirement-a-preservation-regime-sits-between-active-and-transferred-req-aps-006
	 */
	public function preservedMessage(): string {
		return 'Cannot write to this object\'s content: it is in the preservation (statisch) regime. '
			. 'Its references still resolve and it stays readable; change the retention regime before editing it.';
	}//end preservedMessage()

	private function sentence(string $state, string $because): string {
		return 'Cannot write to this object: it was ' . $state . ' by '
			. ($this->field(key: 'by') ?? 'an unknown user') . ' on ' . ($this->field(key: 'at') ?? 'an unrecorded date')
			. $because . '. ';
	}//end sentence()
}//end class
