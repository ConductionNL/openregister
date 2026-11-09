<?php

/**
 * The mappers a view-backed type resolves its view and source through.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ObjectSource
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ObjectSource;

use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Search\Db\ViewMapperInterface;

/**
 * The backing view, and the register and schema its rows come from.
 *
 * @spec openspec/changes/modelling-query-backed-type/specs/saved-search-views/spec.md#requirement-req-qtype-001-a-saved-view-can-back-a-read-only-record-type
 */
final readonly class ViewSourceMappers {

	/**
	 * Bundle the collaborators.
	 *
	 * @param ViewMapperInterface     $viewMapper     Reads the backing view.
	 * @param RegisterMapperInterface $registerMapper Resolves the source register.
	 * @param SchemaMapperInterface   $schemaMapper   Resolves the source schema.
	 */
	public function __construct(
		public ViewMapperInterface $viewMapper,
		public RegisterMapperInterface $registerMapper,
		public SchemaMapperInterface $schemaMapper,
	) {
	}//end __construct()
}//end class
