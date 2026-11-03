<?php

/**
 * OpenRegister RecordTemplateInput
 *
 * The checks a record template's fields pass before they are stored, and how
 * checked fields land on the entity.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\RecordTemplate
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/records-saved-templates/specs/record-templates/spec.md#requirement-a-user-can-save-and-share-a-named-record-template
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\RecordTemplate;

use OCA\OpenRegister\Db\RecordTemplate;
use OCA\OpenRegister\Exception\RecordTemplateRefused;

/**
 * Checks and applies the writable fields of a record template.
 *
 * @spec openspec/changes/records-saved-templates/specs/record-templates/spec.md#requirement-a-user-can-save-and-share-a-named-record-template
 */
class RecordTemplateInput {

	/**
	 * The longest name a template carries.
	 */
	public const NAME_MAX = 255;

	/**
	 * The fields a caller may set.
	 */
	public const WRITABLE = ['name', 'description', 'register', 'schema', 'values', 'isPublic', 'sharedWith'];

	/**
	 * The fields a full write must carry.
	 */
	public const REQUIRED = ['name', 'register', 'schema'];

	/**
	 * The writable fields of a request, checked.
	 *
	 * @param array<string, mixed> $data          The request body.
	 * @param bool                 $partial       True when absent fields stay as they are (PATCH).
	 * @param callable(mixed): array<int, array{code: string, message: string}> $shareFindings Checks `sharedWith`.
	 *
	 * @return array<string, mixed> The fields to set, text fields trimmed.
	 *
	 * @throws RecordTemplateRefused 400 naming every finding.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) PUT and PATCH differ only in this.
	 *
	 * @spec openspec/changes/records-saved-templates/specs/record-templates/spec.md#requirement-a-user-can-save-and-share-a-named-record-template
	 */
	public function validated(array $data, bool $partial, callable $shareFindings): array {
		$fields = array_intersect_key($data, array_flip(self::WRITABLE));
		$findings = [];

		foreach (self::REQUIRED as $required) {
			if ($partial === true && array_key_exists($required, $fields) === false) {
				continue;
			}

			$text = $this->text(value: ($fields[$required] ?? null));
			if ($text === '') {
				$findings[] = ['code' => $required . '.required', 'message' => $required . ' is required.'];
				continue;
			}

			$fields[$required] = $text;
		}

		$findings = array_merge($findings, $this->shapeFindings(fields: $fields));
		if (array_key_exists('sharedWith', $fields) === true) {
			$findings = array_merge($findings, $shareFindings($fields['sharedWith']));
		}

		if ($findings !== []) {
			throw new RecordTemplateRefused(status: 400, message: 'The record template is not valid.', findings: $findings);
		}

		return $fields;
	}//end validated()

	/**
	 * Set the checked fields on the template.
	 *
	 * @param RecordTemplate       $template The template.
	 * @param array<string, mixed> $fields   The checked fields.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/records-saved-templates/specs/record-templates/spec.md#requirement-a-user-can-save-and-share-a-named-record-template
	 */
	public function apply(RecordTemplate $template, array $fields): void {
		$setters = [
			'name' => fn (mixed $value) => $template->setName((string)$value),
			'register' => fn (mixed $value) => $template->setRegister((string)$value),
			'schema' => fn (mixed $value) => $template->setSchema((string)$value),
			'description' => fn (mixed $value) => $template->setDescription($this->text(value: $value)),
			'values' => fn (mixed $value) => $template->setValues((array)$value),
			'isPublic' => fn (mixed $value) => $template->setIsPublic((bool)$value),
			'sharedWith' => fn (mixed $value) => $template->setSharedWith((array)($value ?? [])),
		];

		foreach ($setters as $field => $set) {
			if (array_key_exists($field, $fields) === true) {
				$set($fields[$field]);
			}
		}
	}//end apply()

	/**
	 * What is wrong with the name length, the values and the public flag.
	 *
	 * @param array<string, mixed> $fields The fields sent.
	 *
	 * @return array<int, array{code: string, message: string}> The findings.
	 */
	private function shapeFindings(array $fields): array {
		$findings = [];
		if (mb_strlen($this->text(value: ($fields['name'] ?? null))) > self::NAME_MAX) {
			$findings[] = ['code' => 'name.too-long', 'message' => 'name is longer than ' . self::NAME_MAX . ' characters.'];
		}

		if (array_key_exists('values', $fields) === true && $this->isObject(value: $fields['values']) === false) {
			$findings[] = ['code' => 'values.not-an-object', 'message' => 'values must be an object keyed by property name.'];
		}

		if (array_key_exists('isPublic', $fields) === true && is_bool($fields['isPublic']) === false) {
			$findings[] = ['code' => 'isPublic.not-a-boolean', 'message' => 'isPublic must be true or false.'];
		}

		return $findings;
	}//end shapeFindings()

	/**
	 * Whether a value is a JSON object (an empty one included), not a list.
	 *
	 * @param mixed $value The value.
	 *
	 * @return bool True for an object keyed by name.
	 */
	private function isObject(mixed $value): bool {
		return is_array($value) === true && ($value === [] || array_is_list($value) === false);
	}//end isObject()

	/**
	 * A scalar as trimmed text, anything else as ''.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string The text.
	 */
	private function text(mixed $value): string {
		if (is_string($value) === false && is_int($value) === false) {
			return '';
		}

		return trim((string)$value);
	}//end text()
}//end class
