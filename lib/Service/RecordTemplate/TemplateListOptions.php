<?php

/**
 * OpenRegister template list options
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
 * Which register and schema a template listing is narrowed to.
 *
 * @spec openspec/changes/records-saved-templates/specs/record-templates/spec.md#requirement-a-user-can-save-and-share-a-named-record-template
 */
final class TemplateListOptions {
	/**
	 * Constructor.
	 *
	 * @param string|null $register Only templates for this register id.
	 * @param string|null $schema   Only templates for this schema id.
	 */
	public function __construct(
		public readonly ?string $register = null,
		public readonly ?string $schema = null,
	) {
	}//end __construct()
}//end class
