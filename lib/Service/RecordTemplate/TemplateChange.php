<?php

/**
 * OpenRegister template change
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
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\RecordTemplate;

/**
 * A change to one record template: which template, the fields sent and whether only those are set.
 *
 * @spec openspec/changes/records-saved-templates/specs/record-templates/spec.md#requirement-a-user-can-save-and-share-a-named-record-template
 */
final class TemplateChange {
	/**
	 * Constructor.
	 *
	 * @param string               $uuid    The template uuid.
	 * @param array<string, mixed> $data    The fields to set.
	 * @param bool                 $partial True for PATCH (only the fields sent), false for PUT.
	 */
	public function __construct(
		public readonly string $uuid,
		public readonly array $data,
		public readonly bool $partial,
	) {
	}//end __construct()
}//end class
