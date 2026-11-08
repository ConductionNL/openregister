<?php

/**
 * OpenRegister record template store
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

use OCA\OpenRegister\Db\RecordTemplateMapperInterface;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use Psr\Log\LoggerInterface;

/**
 * Where record templates are stored and their schemas looked up.
 *
 * @spec openspec/changes/records-saved-templates/specs/record-templates/spec.md#requirement-a-user-can-save-and-share-a-named-record-template
 */
final class RecordTemplateStore {
	/**
	 * Bundle the collaborators.
	 *
	 * @param RecordTemplateMapperInterface $mapper  The template store.
	 * @param SchemaMapperInterface         $schemas Reads the template's schema.
	 * @param LoggerInterface               $logger  Logger.
	 */
	public function __construct(
		public readonly RecordTemplateMapperInterface $mapper,
		public readonly SchemaMapperInterface $schemas,
		public readonly LoggerInterface $logger,
	) {
	}//end __construct()
}//end class
