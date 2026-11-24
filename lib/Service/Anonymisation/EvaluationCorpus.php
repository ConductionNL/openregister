<?php

/**
 * OpenRegister Anonymisation Evaluation Corpus
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Service\Anonymisation
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://www.OpenRegister.app
 *
 * @spec openspec/changes/anonymisation-discloses-itself/specs/anonymisation-disclosure/spec.md#requirement-precision-and-recall-are-measured-over-a-named-corpus-and-published-with-the-detector-version-req-adi-002
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Anonymisation;

use InvalidArgumentException;

/**
 * A named, versioned set of texts with gold annotations, read from the
 * `corpus.json` of a corpus directory.
 *
 * An annotation is `{ type, value, start, end }`, with `start` and `end` byte
 * offsets into the UTF-8 text, `end` exclusive: the unit the detectors
 * report. A file that does not have that shape is refused when it is read,
 * so a run never scores against half a corpus.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Anonymisation
 *
 * @spec openspec/changes/anonymisation-discloses-itself/specs/anonymisation-disclosure/spec.md#requirement-precision-and-recall-are-measured-over-a-named-corpus-and-published-with-the-detector-version-req-adi-002
 */
final class EvaluationCorpus {

	/**
	 * The file a corpus directory holds.
	 */
	public const FILE = 'corpus.json';

	/**
	 * Constructor.
	 *
	 * @param string $name      The corpus name.
	 * @param string $version   The corpus version.
	 * @param array<int, string> $types     The entity types the corpus annotates.
	 * @param array<int, array<string, mixed>> $documents The documents: id, text and gold (type, value, start, end).
	 *
	 * @spec openspec/changes/anonymisation-discloses-itself/specs/anonymisation-disclosure/spec.md#requirement-precision-and-recall-are-measured-over-a-named-corpus-and-published-with-the-detector-version-req-adi-002
	 */
	public function __construct(
		public readonly string $name,
		public readonly string $version,
		public readonly array $types,
		public readonly array $documents,
	) {
	}//end __construct()

	/**
	 * Read a corpus directory.
	 *
	 * @param string $directory The directory holding corpus.json.
	 *
	 * @return self
	 *
	 * @throws InvalidArgumentException When the file is missing or not a corpus.
	 *
	 * @spec openspec/changes/anonymisation-discloses-itself/specs/anonymisation-disclosure/spec.md#requirement-precision-and-recall-are-measured-over-a-named-corpus-and-published-with-the-detector-version-req-adi-002
	 */
	public static function fromDirectory(string $directory): self {
		$path = rtrim($directory, '/') . '/' . self::FILE;
		if (is_file($path) === false) {
			throw new InvalidArgumentException('No corpus at ' . $path . '.');
		}

		$decoded = json_decode((string) file_get_contents($path), true);
		if (is_array($decoded) === false) {
			throw new InvalidArgumentException($path . ' is not JSON.');
		}

		return self::fromArray(data: $decoded);
	}//end fromDirectory()

	/**
	 * Build a corpus from its decoded file.
	 *
	 * @param array<string, mixed> $data The decoded corpus.json.
	 *
	 * @return self
	 *
	 * @throws InvalidArgumentException When a field is missing or an annotation is malformed.
	 *
	 * @spec openspec/changes/anonymisation-discloses-itself/specs/anonymisation-disclosure/spec.md#requirement-precision-and-recall-are-measured-over-a-named-corpus-and-published-with-the-detector-version-req-adi-002
	 */
	public static function fromArray(array $data): self {
		$name    = (string) ($data['name'] ?? '');
		$version = (string) ($data['version'] ?? '');
		$types   = array_values(array_filter((array) ($data['types'] ?? []), 'is_string'));
		if ($name === '' || $version === '' || $types === []) {
			throw new InvalidArgumentException('A corpus needs a name, a version and its entity types.');
		}

		$documents = [];
		foreach ((array) ($data['documents'] ?? []) as $document) {
			$documents[] = self::document(document: $document, types: $types);
		}

		return new self(name: $name, version: $version, types: $types, documents: $documents);
	}//end fromArray()

	/**
	 * One document, its annotations checked against the corpus types.
	 *
	 * @param mixed $document The decoded document.
	 * @param array<int, string> $types    The corpus types.
	 *
	 * @return array{id: string, text: string, gold: array<int, array{type: string, value: string, start: int, end: int}>}
	 *
	 * @throws InvalidArgumentException When the document or an annotation is malformed.
	 */
	private static function document(mixed $document, array $types): array {
		if (is_array($document) === false || is_string($document['text'] ?? null) === false) {
			throw new InvalidArgumentException('A corpus document needs a text.');
		}

		$gold = [];
		foreach ((array) ($document['gold'] ?? []) as $span) {
			if (is_array($span) === false || in_array($span['type'] ?? null, $types, true) === false
				|| is_int($span['start'] ?? null) === false || is_int($span['end'] ?? null) === false
			) {
				throw new InvalidArgumentException('A gold annotation needs a corpus type and integer offsets.');
			}

			$gold[] = ['type' => $span['type'], 'value' => (string) ($span['value'] ?? ''), 'start' => $span['start'], 'end' => $span['end']];
		}

		return ['id' => (string) ($document['id'] ?? ''), 'text' => $document['text'], 'gold' => $gold];
	}//end document()
}//end class
