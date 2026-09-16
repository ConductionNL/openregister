<?php

/**
 * ExportProfileValidator — what a submitted profile has to say before it is one.
 *
 * Split out of `ExportProfileService` for the same reason the renderer was
 * split out of the writer: administering a profile and judging a submission are
 * different jobs. This one refuses, in sentences an administrator can act on,
 * and knows nothing about persistence.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Service\Export
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Export;

use InvalidArgumentException;
use OCA\OpenRegister\Db\ExportProfile;

/**
 * Refuses a submitted export profile that does not make sense.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Export
 *
 * @spec openspec/changes/export-as-its-own-right/specs/data-import-export/spec.md
 */
class ExportProfileValidator {

	/**
	 * Validate a submitted profile.
	 *
	 * @param array<string, mixed> $data    The submission.
	 * @param bool                 $partial Whether absent keys are allowed.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When the submission does not make sense.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) A partial update validates
	 *     the same rules over fewer keys, which is one rule set, not two.
	 *
	 * @spec openspec/changes/export-as-its-own-right/specs/data-import-export/spec.md
	 */
	public function validate(array $data, bool $partial): void {
		if ($partial === false) {
			$this->validateRequired(data: $data);
		}

		$this->validateFields(data: $data);
		$this->validateChoices(data: $data);
	}//end validate()

	/**
	 * Every key a new profile cannot be created without.
	 *
	 * @param array<string, mixed> $data The submission.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When one is missing.
	 */
	private function validateRequired(array $data): void {
		foreach (['name', 'registerId', 'fields'] as $required) {
			if (isset($data[$required]) === false) {
				throw new InvalidArgumentException('An export profile needs a ' . $required . '.');
			}
		}
	}//end validateRequired()

	/**
	 * The field set: a non-empty list of non-empty names.
	 *
	 * @param array<string, mixed> $data The submission.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When the field set does not make sense.
	 */
	private function validateFields(array $data): void {
		if (isset($data['fields']) === false) {
			return;
		}

		if (is_array($data['fields']) === false || $data['fields'] === []) {
			throw new InvalidArgumentException('An export profile needs at least one field.');
		}

		foreach ($data['fields'] as $field) {
			if (is_string($field) === false || trim($field) === '') {
				throw new InvalidArgumentException('Every field in an export profile is a non-empty name.');
			}
		}
	}//end validateFields()

	/**
	 * The value mode, the format and the shape of the filter.
	 *
	 * @param array<string, mixed> $data The submission.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When one of the three is not a value this writes.
	 */
	private function validateChoices(array $data): void {
		if (isset($data['valueMode']) === true
			&& in_array($data['valueMode'], ExportProfile::MODES, true) === false
		) {
			throw new InvalidArgumentException(
				'An export profile writes stored values or rendered ones, and names which.'
			);
		}

		if (isset($data['format']) === true
			&& in_array($data['format'], ExportProfile::FORMATS, true) === false
		) {
			throw new InvalidArgumentException(
				'An export profile writes ' . implode(' or ', ExportProfile::FORMATS) . '.'
			);
		}

		if (isset($data['filters']) === true && is_array($data['filters']) === false) {
			throw new InvalidArgumentException('The filter of an export profile is a map.');
		}
	}//end validateChoices()
}//end class
