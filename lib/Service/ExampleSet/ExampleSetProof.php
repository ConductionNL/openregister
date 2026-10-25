<?php

/**
 * OpenRegister Example Set Proof
 *
 * Compares what an install sent with what the instance hands back. A write is
 * answered with the object as it was sent, also for a key the schema does not
 * keep, so only a read afterwards shows what arrived.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ExampleSet
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/changes/example-sets/specs/example-sets/spec.md#requirement-an-install-proves-what-arrived-req-exs-006
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ExampleSet;

/**
 * Names the declared paths a stored object did not keep.
 *
 * @spec openspec/changes/example-sets/specs/example-sets/spec.md#requirement-an-install-proves-what-arrived-req-exs-006
 */
class ExampleSetProof {
	/**
	 * Every declared path whose value the stored object does not hold.
	 *
	 * Numbers compare by value and moments by the instant they name, because
	 * a register may store `09:12+02:00` as `07:12Z`.
	 *
	 * @param mixed  $declared What was sent.
	 * @param mixed  $stored   What was read back.
	 * @param string $path     The path so far.
	 *
	 * @return array<int, string> Dot paths, such as `verblijfplaats.postcode`.
	 *
	 * @spec openspec/changes/example-sets/specs/example-sets/spec.md#requirement-an-install-proves-what-arrived-req-exs-006
	 */
	public function lostPaths(mixed $declared, mixed $stored, string $path=''): array {
		// JSON text declared for a field the store answers decoded is compared as what it encodes.
		if (is_string($declared) === true && is_array($stored) === true && is_array($this->structured(stored: $declared)) === true) {
			$declared = $this->structured(stored: $declared);
		}

		if (is_array($declared) === false) {
			if ($this->sameValue(declared: $declared, stored: $stored) === true) {
				return [];
			}

			return [$path];
		}

		$stored = $this->structured(stored: $stored);
		$lost   = [];
		foreach ($declared as $key => $value) {
			$here = ltrim($path.'.'.$key, '.');
			if (is_array($stored) === true && array_key_exists($key, $stored) === true) {
				$lost = array_merge($lost, $this->lostPaths(declared: $value, stored: $stored[$key], path: $here));
				continue;
			}

			if ($value !== [] && $value !== null) {
				$lost[] = $here;
			}
		}

		return $lost;
	}//end lostPaths()

	/**
	 * A stored value, decoded when a schema keeps a structure as JSON text.
	 *
	 * @param mixed $stored What was read back.
	 *
	 * @return mixed
	 */
	private function structured(mixed $stored): mixed {
		if (is_string($stored) === false) {
			return $stored;
		}

		$decoded = json_decode($stored, true);
		if (is_array($decoded) === true) {
			return $decoded;
		}

		return $stored;
	}//end structured()

	/**
	 * Whether two leaf values are the same value: equal numbers, one moment in
	 * two offsets, and an empty text the store keeps as null count as the same.
	 *
	 * @param mixed $declared What was sent.
	 * @param mixed $stored   What was read back.
	 *
	 * @return bool
	 */
	private function sameValue(mixed $declared, mixed $stored): bool {
		if ($declared === $stored || ($declared === '' && $stored === null)) {
			return true;
		}

		if (is_numeric($declared) === true && is_numeric($stored) === true) {
			return (float) $declared === (float) $stored;
		}

		return $this->sameMoment(declared: $declared, stored: $stored);
	}//end sameValue()

	/**
	 * Whether two values are one moment, whatever offset each is written in.
	 *
	 * @param mixed $declared What was sent.
	 * @param mixed $stored   What was read back.
	 *
	 * @return bool
	 */
	private function sameMoment(mixed $declared, mixed $stored): bool {
		if (is_string($declared) === false || is_string($stored) === false || preg_match('/^\d{4}-\d{2}-\d{2}T/', $declared) !== 1) {
			return false;
		}

		$left = strtotime($declared);

		return $left !== false && $left === strtotime($stored);
	}//end sameMoment()
}//end class
