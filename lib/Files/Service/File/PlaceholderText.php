<?php

/**
 * The text of a placeholder, with the exception grounds it carries.
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
 * @spec openspec/changes/anonymisation-placeholder-id-scope/specs/entity-relation-grondslagen/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Files\Service\File;

/**
 * Writes and reads `[<TYPE>: <n>]` and `[<TYPE>: <n>; <ground>, ...]`.
 *
 * A ground is the identifier stored in an entity relation's `bases`, printed
 * as it is stored, without lookup. A masked value carries its grounds after
 * it as ` [<ground>, ...]`.
 *
 * @spec openspec/changes/anonymisation-placeholder-id-scope/specs/entity-relation-grondslagen/spec.md
 */
final class PlaceholderText {

	/**
	 * A placeholder, with its grounds after a semicolon when there are any.
	 *
	 * @param string             $label   The (localised) entity type label.
	 * @param string             $number  The scope-local number or key.
	 * @param array<int, string> $grounds The grounds, in stored order.
	 *
	 * @return string E.g. `[PERSOON: 1]` or `[PERSOON: 1; 5.1.2e]`.
	 *
	 * @spec openspec/changes/anonymisation-placeholder-id-scope/specs/entity-relation-grondslagen/spec.md#requirement-the-placeholder-carries-the-exception-ground-where-it-applies-req-pis-011
	 */
	public static function placeholder(string $label, string $number, array $grounds): string {
		if ($grounds === []) {
			return '['.$label.': '.$number.']';
		}

		return '['.$label.': '.$number.'; '.implode(', ', $grounds).']';
	}//end placeholder()

	/**
	 * A masked value, followed by its grounds in brackets when there are any.
	 *
	 * @param string             $masked  The masked value.
	 * @param array<int, string> $grounds The grounds, in stored order.
	 *
	 * @return string E.g. `**************4300 [5.1.2e]`.
	 *
	 * @spec openspec/changes/anonymisation-placeholder-id-scope/specs/entity-relation-grondslagen/spec.md#requirement-the-placeholder-carries-the-exception-ground-where-it-applies-req-pis-011
	 */
	public static function masked(string $masked, array $grounds): string {
		if ($grounds === []) {
			return $masked;
		}

		return $masked.' ['.implode(', ', $grounds).']';
	}//end masked()

	/**
	 * Read a placeholder back into its type, number and grounds.
	 *
	 * @param string $placeholder The placeholder text.
	 *
	 * @return array{type: string, id: string, grounds: array<int, string>}|null The parts, or null when it is no placeholder.
	 *
	 * @spec openspec/changes/anonymisation-placeholder-id-scope/specs/entity-relation-grondslagen/spec.md#requirement-the-placeholder-carries-the-exception-ground-where-it-applies-req-pis-011
	 */
	public static function parse(string $placeholder): ?array {
		if (preg_match('/^\[([^:\]]+):\s*([^\];]*)(?:;\s*([^\]]*))?\]$/', $placeholder, $parts) !== 1) {
			return null;
		}

		$grounds = [];
		if (isset($parts[3]) === true && trim($parts[3]) !== '') {
			$grounds = array_map('trim', explode(',', $parts[3]));
		}

		return ['type' => trim($parts[1]), 'id' => trim($parts[2]), 'grounds' => $grounds];
	}//end parse()

	/**
	 * The grounds of a relation's stored `bases`: its non-empty strings in
	 * stored order, each once, with the characters that would end or split the
	 * placeholder taken out.
	 *
	 * @param mixed $bases The stored bases: a list, its JSON text, or null.
	 *
	 * @return array<int, string> The grounds.
	 *
	 * @spec openspec/changes/anonymisation-placeholder-id-scope/specs/entity-relation-grondslagen/spec.md#requirement-the-placeholder-carries-the-exception-ground-where-it-applies-req-pis-011
	 */
	public static function groundsOf(mixed $bases): array {
		if (is_string($bases) === true) {
			$bases = json_decode($bases, true);
		}

		if (is_array($bases) === false) {
			return [];
		}

		$grounds = [];
		foreach ($bases as $base) {
			if (is_string($base) === false) {
				continue;
			}

			$ground = trim(str_replace(['[', ']', ';', ',', "\n", "\r"], ' ', $base));
			if ($ground !== '' && in_array($ground, $grounds, true) === false) {
				$grounds[] = $ground;
			}
		}

		return $grounds;
	}//end groundsOf()
}//end class
