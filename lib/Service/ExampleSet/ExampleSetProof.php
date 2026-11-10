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
 * @spec openspec/specs/example-sets/spec.md#requirement-an-install-proves-what-arrived-req-exs-006
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ExampleSet;

/**
 * Names the declared paths a stored object did not keep.
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-an-install-proves-what-arrived-req-exs-006
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
	 * @spec openspec/specs/example-sets/spec.md#requirement-an-install-proves-what-arrived-req-exs-006
	 */
	public function lostPaths(mixed $declared, mixed $stored, string $path=''): array {
		$declared = $this->asDeclared(declared: $declared, stored: $stored);
		$place    = new ExampleSetProofPlace(stored: $stored, path: $path);
		if (is_array($declared) === false) {
			return $this->lostLeaf(declared: $declared, place: $place);
		}

		if ($this->readInOneLanguage(declared: $declared, stored: $stored) === true) {
			return [];
		}

		return $this->lostInMap(declared: $declared, place: $place);
	}//end lostPaths()

	/**
	 * The path of a leaf whose stored value is not the declared one; none when it is.
	 *
	 * @param mixed                $declared What was sent.
	 * @param ExampleSetProofPlace $place    What was read back there, and the path.
	 *
	 * @return array<int, string>
	 */
	private function lostLeaf(mixed $declared, ExampleSetProofPlace $place): array {
		if ($this->sameValue(declared: $declared, stored: $place->stored) === true) {
			return [];
		}

		return [$place->path];
	}//end lostLeaf()

	/**
	 * Every path below a declared map whose value the stored value does not hold.
	 *
	 * @param array<int|string, mixed> $declared What was sent.
	 * @param ExampleSetProofPlace     $place    What was read back there, and the path.
	 *
	 * @return array<int, string>
	 */
	private function lostInMap(array $declared, ExampleSetProofPlace $place): array {
		$stored = $this->structured(stored: $place->stored);
		$lost   = [];
		foreach ($declared as $key => $value) {
			if (is_array($stored) === true && array_key_exists($key, $stored) === true) {
				$lost = array_merge($lost, $this->lostPaths(declared: $value, stored: $stored[$key], path: $place->pathOf(key: $key)));
				continue;
			}

			$lost = array_merge($lost, $this->missing(value: $value, path: $place->pathOf(key: $key)));
		}

		return $lost;
	}//end lostInMap()

	/**
	 * The path of a declared key the stored value lacks, unless what was declared there is empty.
	 *
	 * @param mixed  $value What was declared at the path.
	 * @param string $path  The path.
	 *
	 * @return array<int, string>
	 */
	private function missing(mixed $value, string $path): array {
		if ($value !== [] && $value !== null) {
			return [$path];
		}

		return [];
	}//end missing()

	/**
	 * Whether a declared language map reads back as one of its texts.
	 *
	 * A translatable property keeps `{nl: …, en: …}` and a read answers it in
	 * the reader's language, so the map arrived when the read is one of its values.
	 *
	 * @param array<int|string, mixed> $declared What was sent.
	 * @param mixed                    $stored   What was read back.
	 *
	 * @return bool
	 */
	private function readInOneLanguage(array $declared, mixed $stored): bool {
		if (is_string($stored) === false || $declared === [] || is_array($this->structured(stored: $stored)) === true) {
			return false;
		}

		return $this->isLanguageMap(declared: $declared) === true && in_array($stored, $declared, true);
	}//end readInOneLanguage()

	/**
	 * Whether every key of a map is a language code and every value a text.
	 *
	 * @param array<int|string, mixed> $declared What was sent.
	 *
	 * @return bool
	 */
	private function isLanguageMap(array $declared): bool {
		foreach ($declared as $language => $text) {
			if (is_string($language) === false || preg_match('/^[a-z]{2}(?:[-_][A-Za-z]{2})?$/', $language) !== 1 || is_string($text) === false) {
				return false;
			}
		}

		return true;
	}//end isLanguageMap()

	/**
	 * JSON text declared for a field the store answers decoded, as what it encodes; any other value as it is.
	 *
	 * @param mixed $declared What was sent.
	 * @param mixed $stored   What was read back.
	 *
	 * @return mixed
	 */
	private function asDeclared(mixed $declared, mixed $stored): mixed {
		if (is_string($declared) === false || is_array($stored) === false) {
			return $declared;
		}

		$decoded = $this->structured(stored: $declared);
		if (is_array($decoded) === true) {
			return $decoded;
		}

		return $declared;
	}//end asDeclared()

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

		return $this->sameNumber(declared: $declared, stored: $stored) === true || $this->sameMoment(declared: $declared, stored: $stored) === true;
	}//end sameValue()

	/**
	 * Whether two values are both numbers and equal as numbers.
	 *
	 * A number never reads as a moment (sameMoment() wants text that starts with a date), so two unequal numbers
	 * are not the same value either way.
	 *
	 * @param mixed $declared What was sent.
	 * @param mixed $stored   What was read back.
	 *
	 * @return bool
	 */
	private function sameNumber(mixed $declared, mixed $stored): bool {
		return is_numeric($declared) === true && is_numeric($stored) === true && (float) $declared === (float) $stored;
	}//end sameNumber()

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
