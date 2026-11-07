<?php

/**
 * OpenRegister Example Set Written Value
 *
 * How a change compares a value read back with the value it wrote or
 * replaced, and how the record keeps a written value: a value filled from an
 * access link's address only as its fingerprint, since the address carries
 * the link's secret anchor.
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
 * @spec openspec/specs/example-sets/spec.md#requirement-a-set-may-change-an-object-it-did-not-create-and-undo-exactly-that-req-exs-011
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ExampleSet;

/**
 * Compares and records the values a change writes.
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-a-set-may-change-an-object-it-did-not-create-and-undo-exactly-that-req-exs-011
 */
class ExampleSetWrittenValue {
	/**
	 * The prefix of a recorded fingerprint: the record keeps an access link's address only as its hash.
	 */
	private const SECRET = 'example-set-secret:sha256:';

	/**
	 * Constructor.
	 *
	 * @param ExampleSetProof $proof Tells whether a value read back is the value written.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ExampleSetProof $proof=new ExampleSetProof(),
	) {
	}//end __construct()

	/**
	 * Whether a stored value is the given value as the store keeps it: the same keys and values in any key order,
	 * JSON text read as its structure, and nothing more or less on either side.
	 *
	 * @param mixed $stored The value as read back.
	 * @param mixed $value  The value a declaration or the record names.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-set-may-change-an-object-it-did-not-create-and-undo-exactly-that-req-exs-011
	 */
	public function sameAs(mixed $stored, mixed $value): bool {
		if ($this->isFingerprint(value: $value) === true) {
			return $this->fingerprints(stored: $stored, fingerprint: $value);
		}

		return $this->proof->lostPaths(declared: $value, stored: $stored) === []
			&& $this->proof->lostPaths(declared: $this->asStructure(stored: $stored), stored: $value) === [];
	}//end sameAs()

	/**
	 * Whether a path still holds what a change wrote, in whatever form the
	 * store keeps it (a moment in UTC, an empty text as null).
	 *
	 * @param array{found: bool, value: mixed} $held    The path as read back.
	 * @param mixed                            $written The value the change wrote.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-set-may-change-an-object-it-did-not-create-and-undo-exactly-that-req-exs-011
	 */
	public function stillHolds(array $held, mixed $written): bool {
		if ($this->isFingerprint(value: $written) === true) {
			return $held['found'] === true && $this->fingerprints(stored: $held['value'], fingerprint: $written);
		}

		return $held['found'] === true && $this->proof->lostPaths(declared: $written, stored: $held['value']) === [];
	}//end stillHolds()

	/**
	 * Whether a held field is back at what the change replaced. A field that reads back null was never set, as one
	 * the object did not have.
	 *
	 * @param array{found: bool, value: mixed} $held The field as read back.
	 * @param array<string, mixed>             $set  The record's entry for the field: `had`, `old` and `new`.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-field-that-reads-back-null-is-not-set
	 */
	public function undone(array $held, array $set): bool {
		$isSet  = ($held['found'] === true && $held['value'] !== null);
		$hadSet = ((bool) $set['had'] === true && $set['old'] !== null);

		return $isSet === $hadSet && $this->sameAs(stored: $held['value'], value: $set['old']) === true;
	}//end undone()

	/**
	 * Record rows with each written value as the record keeps it.
	 *
	 * @param array<string, mixed>             $change The declared change.
	 * @param array<int, array<string, mixed>> $rows   Record rows with a `path` and the `new` value written.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-set-may-change-an-object-it-did-not-create-and-undo-exactly-that-req-exs-011
	 */
	public function recordedRows(array $change, array $rows): array {
		foreach ($rows as $index => $row) {
			$rows[$index]['new'] = $this->recorded(change: $change, row: $row);
		}

		return $rows;
	}//end recordedRows()

	/**
	 * The value the record keeps for a field a change wrote: a value filled from an access link's address
	 * (`{{accessLink:key.url}}`) as a fingerprint, since the address carries the link's secret anchor; any other
	 * value as it is.
	 *
	 * @param array<string, mixed> $change The declared change.
	 * @param array<string, mixed> $row    The field's `path` and the `new` value written.
	 *
	 * @return mixed
	 *
	 * @spec openspec/changes/example-set-access-links-and-grants/specs/example-sets/spec.md#requirement-a-change-may-quote-an-access-link-of-its-fragment
	 */
	public function recorded(array $change, array $row): mixed {
		$value    = $row['new'];
		$declared = ($change['set'][(string) $row['path']] ?? null);
		if (is_string($value) === false || is_string($declared) === false
			|| preg_match('/\{\{\s*accessLink:[^}]*\.url\s*\}\}/', $declared) !== 1
		) {
			return $value;
		}

		return self::SECRET.hash('sha256', $value);
	}//end recorded()

	/**
	 * A stored value with JSON text that holds a structure read as that structure.
	 *
	 * @param mixed $stored The value as read back.
	 *
	 * @return mixed
	 */
	private function asStructure(mixed $stored): mixed {
		if (is_string($stored) === false) {
			return $stored;
		}

		$decoded = json_decode($stored, true);
		if (is_array($decoded) === true) {
			return $decoded;
		}

		return $stored;
	}//end asStructure()

	/**
	 * Whether a stored value is the text a recorded fingerprint was made from.
	 *
	 * @param mixed  $stored      The value as read back.
	 * @param string $fingerprint The recorded fingerprint.
	 *
	 * @return bool
	 */
	private function fingerprints(mixed $stored, string $fingerprint): bool {
		return is_string($stored) === true && hash_equals($fingerprint, self::SECRET.hash('sha256', $stored));
	}//end fingerprints()

	/**
	 * Whether a recorded value is the fingerprint of an access link's address.
	 *
	 * @param mixed $value A recorded value.
	 *
	 * @return bool
	 */
	private function isFingerprint(mixed $value): bool {
		return is_string($value) === true && str_starts_with($value, self::SECRET) === true;
	}//end isFingerprint()
}//end class
