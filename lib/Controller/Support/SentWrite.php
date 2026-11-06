<?php

/**
 * OpenRegister SentWrite
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller\Support;


/**
 * What a caller is writing, as the version check needs it.
 *
 * @spec openspec/changes/a-conflicting-save-shows-the-other-value/specs/objects-crud/spec.md#requirement-every-write-path-asserts-the-expected-version-req-cso-003
 */
final class SentWrite {
	/**
	 * Hold the data and the schema.
	 *
	 * @param array<string, mixed> $data   What the caller is writing.
	 * @param mixed                $schema The schema, for the field filter.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/a-conflicting-save-shows-the-other-value/specs/objects-crud/spec.md#requirement-every-write-path-asserts-the-expected-version-req-cso-003
	 */
	public function __construct(
		public readonly array $data,
		public readonly mixed $schema = null,
	) {
	}//end __construct()
}//end class
