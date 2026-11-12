<?php

/**
 * OpenRegister file property options.
 *
 * Checks the options a `type: file` property declares: allowed MIME types,
 * maximum size and the two tag lists.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Schemas
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/runtime-schema-api/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Schemas;

use Exception;

/**
 * The save-time rules for the options of a file property.
 *
 * @spec openspec/specs/runtime-schema-api/spec.md
 */
class FilePropertyOptionsValidator {

	/**
	 * A basic type/subtype MIME check for allowedTypes.
	 *
	 * @var string
	 */
	private const MIME_TYPE_PATTERN = '/^[a-zA-Z0-9][a-zA-Z0-9!#$&\-\^_]*\/[a-zA-Z0-9][a-zA-Z0-9!#$&\-\^_.]*$/';

	/**
	 * The largest maxSize a file property may declare: 100MB.
	 *
	 * @var int
	 */
	private const MAX_FILE_SIZE = 104857600;

	/**
	 * Validate file-specific properties
	 *
	 * Validates file property configuration options including allowedTypes,
	 * maxSize, allowedTags, and autoTags
	 *
	 * @param array $property The file property definition to validate
	 * @param string $path The current path in the schema (for error messages)
	 *
	 * @throws Exception If the file property configuration is invalid
	 *
	 * @return true
	 *
	 * @psalm-param array<string, mixed> $property
	 *
	 * @phpstan-param array<string, mixed> $property
	 *
	 * @psalm-return   bool
	 * @phpstan-return bool
	 *
	 * @psalm-suppress UnusedReturnValue
	 *
	 * @spec openspec/specs/runtime-schema-api/spec.md
	 */
	public function validate(array $property, string $path): bool {
		$this->assertAllowedTypes(property: $property, path: $path);
		$this->assertMaxSize(property: $property, path: $path);
		$this->assertTagLists(property: $property, path: $path);

		return true;
	}//end validate()

	/**
	 * Refuse an allowedTypes that is not a list of type/subtype MIME strings.
	 *
	 * @param array  $property The file property definition.
	 * @param string $path     The current path in the schema, for the message.
	 *
	 * @throws Exception When allowedTypes or one of its entries is malformed.
	 *
	 * @return void
	 */
	private function assertAllowedTypes(array $property, string $path): void {
		$types = $this->listOrNull(value: ($property['allowedTypes'] ?? null), label: "'allowedTypes' at '$path'");
		foreach (($types ?? []) as $index => $mimeType) {
			if (is_string($mimeType) === false) {
				throw new Exception("'allowedTypes[$index]' at '$path' must be a string");
			}

			if (preg_match(self::MIME_TYPE_PATTERN, $mimeType) === 0) {
				throw new Exception("'allowedTypes[$index]' at '$path' contains invalid MIME type format: '$mimeType'");
			}
		}
	}//end assertAllowedTypes()

	/**
	 * Refuse a maxSize that is not numeric, is negative, or is above 100MB.
	 *
	 * @param array  $property The file property definition.
	 * @param string $path     The current path in the schema, for the message.
	 *
	 * @throws Exception When maxSize is out of range.
	 *
	 * @return void
	 */
	private function assertMaxSize(array $property, string $path): void {
		if (($property['maxSize'] ?? null) === null) {
			return;
		}

		if (is_numeric($property['maxSize']) === false) {
			throw new Exception("'maxSize' at '$path' must be a numeric value");
		}

		$this->assertMaxSizeRange(maxSize: (int)$property['maxSize'], path: $path);
	}//end assertMaxSize()

	/**
	 * Refuse a negative maxSize, or one above 100MB.
	 *
	 * @param int    $maxSize The declared maximum size in bytes.
	 * @param string $path    The current path in the schema, for the message.
	 *
	 * @throws Exception When the size is out of range.
	 *
	 * @return void
	 */
	private function assertMaxSizeRange(int $maxSize, string $path): void {
		if ($maxSize < 0) {
			throw new Exception("'maxSize' at '$path' must be a positive number");
		}

		if ($maxSize > self::MAX_FILE_SIZE) {
			throw new Exception("'maxSize' at '$path' exceeds maximum allowed size (100MB)");
		}
	}//end assertMaxSizeRange()

	/**
	 * Refuse an allowedTags or autoTags that is not a list of valid tags.
	 *
	 * @param array  $property The file property definition.
	 * @param string $path     The current path in the schema, for the message.
	 *
	 * @throws Exception When a tag list or one of its tags is malformed.
	 *
	 * @return void
	 */
	private function assertTagLists(array $property, string $path): void {
		foreach (['allowedTags', 'autoTags'] as $key) {
			$tags = $this->listOrNull(value: ($property[$key] ?? null), label: "'{$key}' at '$path'");
			foreach (($tags ?? []) as $index => $tag) {
				$this->assertTag(tag: $tag, entry: "'{$key}[$index]' at '$path'");
			}
		}
	}//end assertTagLists()

	/**
	 * Refuse a tag that is not a non-empty string of at most 50 characters.
	 *
	 * @param mixed  $tag   The tag.
	 * @param string $entry The message subject naming the entry.
	 *
	 * @throws Exception When the tag is malformed.
	 *
	 * @return void
	 */
	private function assertTag(mixed $tag, string $entry): void {
		if (is_string($tag) === false) {
			throw new Exception("$entry must be a string");
		}

		if (trim($tag) === '') {
			throw new Exception("$entry cannot be empty");
		}

		if (strlen($tag) > 50) {
			throw new Exception("$entry exceeds maximum length (50 characters)");
		}
	}//end assertTag()

	/**
	 * An optional array option: null when absent, the array when present.
	 *
	 * @param mixed  $value The declared option.
	 * @param string $label The message subject.
	 *
	 * @throws Exception When the option is present and not an array.
	 *
	 * @return array|null The option, or null when it is absent.
	 */
	private function listOrNull(mixed $value, string $label): ?array {
		if ($value !== null && is_array($value) === false) {
			throw new Exception("$label must be an array");
		}

		return $value;
	}//end listOrNull()
}//end class
