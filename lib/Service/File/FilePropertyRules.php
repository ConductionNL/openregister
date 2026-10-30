<?php

/**
 * A file property's size and type rules, read in one place.
 *
 * The save path ({@see \OCA\OpenRegister\Service\Object\SaveObject\FilePropertyHandler})
 * and the form upload token ({@see \OCA\OpenRegister\Service\Form\FormUploadStore})
 * both enforce these. Two readers of the same keys drift, and the drift shows as
 * a token the save then refuses, so both read them here.
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
 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-upload-tokens-must-hold-bytes-only-and-expire
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\File;

/**
 * Reads `maxSize`, `allowedTypes` and their editor spellings off a file property.
 *
 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-upload-tokens-must-hold-bytes-only-and-expire
 */
class FilePropertyRules {

	/**
	 * The rule block that governs a file: the property itself, or its items for an array of files.
	 *
	 * @param array<string, mixed> $property The schema property.
	 *
	 * @return array<string, mixed>|null The file configuration, or null when the property holds no files.
	 *
	 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-upload-tokens-must-hold-bytes-only-and-expire
	 */
	public function fileConfigOf(array $property): ?array {
		if (($property['type'] ?? null) === 'file') {
			return $property;
		}

		$items = ($property['items'] ?? null);
		if (($property['type'] ?? null) === 'array' && is_array($items) === true && ($items['type'] ?? null) === 'file') {
			return $items;
		}

		return null;
	}//end fileConfigOf()

	/**
	 * The MIME types a file property accepts.
	 *
	 * @param array<string, mixed> $fileConfig The file property configuration.
	 *
	 * @return array<int, string> The accepted types; empty when any type is accepted.
	 *
	 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-upload-tokens-must-hold-bytes-only-and-expire
	 */
	public function allowedTypes(array $fileConfig): array {
		$types = ($fileConfig['allowedTypes'] ?? null);
		if (is_array($types) === false || $types === []) {
			$editorConfig = ($fileConfig['fileConfiguration'] ?? []);
			$types = null;
			if (is_array($editorConfig) === true) {
				$types = ($editorConfig['allowedMimeTypes'] ?? null);
			}
		}

		if (is_array($types) === false) {
			return [];
		}

		return array_values(array_filter($types, 'is_string'));
	}//end allowedTypes()

	/**
	 * The largest upload a file property accepts, in bytes.
	 *
	 * The top-level `maxSize` is in bytes. The editor's
	 * `fileConfiguration.maxSize` is labelled and entered in megabytes, so it
	 * is converted here; a plain rename would turn 10 MB into 10 bytes.
	 *
	 * @param array<string, mixed> $fileConfig The file property configuration.
	 *
	 * @return int The limit in bytes, 0 when there is no limit.
	 *
	 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-upload-tokens-must-hold-bytes-only-and-expire
	 */
	public function maxSizeBytes(array $fileConfig): int {
		$bytes = ($fileConfig['maxSize'] ?? null);
		if (is_numeric($bytes) === true && (float)$bytes > 0) {
			return (int)$bytes;
		}

		$editorConfig = ($fileConfig['fileConfiguration'] ?? []);
		if (is_array($editorConfig) === false) {
			return 0;
		}

		$megabytes = ($editorConfig['maxSize'] ?? null);
		if (is_numeric($megabytes) === true && (float)$megabytes > 0) {
			return (int)round((float)$megabytes * 1024 * 1024);
		}

		return 0;
	}//end maxSizeBytes()
}//end class
