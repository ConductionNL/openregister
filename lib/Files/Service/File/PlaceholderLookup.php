<?php

/**
 * What one anonymisation run looks up per entity: the values the operator
 * chose to skip, the stable entity rows of the file, and the scope-local
 * placeholder numbering.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\File
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/file-actions/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Files\Service\File;

/**
 * The per-run lookups behind an anonymisation's placeholders.
 *
 * @spec openspec/specs/file-actions/spec.md
 */
final class PlaceholderLookup {

	/**
	 * Constructor.
	 *
	 * @param array<int, string>                         $skippedValues Entity values never to redact.
	 * @param array<string, array{id: int, type: string}> $entityRows    The file's entity rows, keyed by value.
	 * @param PlaceholderIdTranslator                    $translator    Turns an entity id into a scope-local number.
	 * @param array<string, array<int, string>>          $grounds       The exception grounds of each value, in stored order.
	 * @param array<string, array{form: string, n?: int}> $maskForms     The mask form per entity type.
	 */
	public function __construct(
		public readonly array $skippedValues,
		public readonly array $entityRows,
		public readonly PlaceholderIdTranslator $translator,
		public readonly array $grounds=[],
		public readonly array $maskForms=[],
	) {
	}//end __construct()

	/**
	 * Whether the operator chose to keep this value.
	 *
	 * @param mixed $text The entity value as detected.
	 *
	 * @return bool True when the value must not be redacted.
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	public function isSkipped(mixed $text): bool {
		return in_array($text, $this->skippedValues, true);
	}//end isSkipped()

	/**
	 * The file's stable entity row for a value, if there is one.
	 *
	 * @param int|string $text The entity value as detected.
	 *
	 * @return array{id: int, type: string}|null The row, or null.
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	public function rowFor(int|string $text): ?array {
		if (isset($this->entityRows[$text]) === false) {
			return null;
		}

		return $this->entityRows[$text];
	}//end rowFor()

	/**
	 * The exception grounds stored for a value's relations on the file.
	 *
	 * @param int|string $text The entity value as detected.
	 *
	 * @return array<int, string> The grounds, in stored order; empty when there are none.
	 *
	 * @spec openspec/changes/anonymisation-placeholder-id-scope/specs/entity-relation-grondslagen/spec.md#requirement-the-placeholder-carries-the-exception-ground-where-it-applies-req-pis-011
	 */
	public function groundsFor(int|string $text): array {
		return ($this->grounds[trim((string) $text)] ?? []);
	}//end groundsFor()

	/**
	 * The mask form set for an entity type, or null for the placeholder.
	 *
	 * @param string $entityType The raw entity type.
	 *
	 * @return array{form: string, n?: int}|null The form.
	 *
	 * @spec openspec/changes/anonymisation-placeholder-id-scope/specs/entity-relation-grondslagen/spec.md#requirement-a-detection-may-be-masked-in-part-with-the-form-set-per-entity-type-req-pis-010
	 */
	public function maskFormFor(string $entityType): ?array {
		return ($this->maskForms[$entityType] ?? null);
	}//end maskFormFor()
}//end class
