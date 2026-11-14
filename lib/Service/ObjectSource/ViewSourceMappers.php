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

use OCA\OpenRegister\Db\SchemaCatalogInterface;
use OCA\OpenRegister\Search\Db\ViewMapperInterface;

/**
 * The backing view, and the catalog its source register, schema and table come from.
 *
 * @spec openspec/specs/saved-search-views/spec.md#requirement-req-qtype-001-a-saved-view-can-back-a-read-only-record-type
 */
final readonly class ViewSourceMappers {

	/**
	 * Bundle the collaborators.
	 *
	 * @param ViewMapperInterface    $viewMapper Reads the backing view.
	 * @param SchemaCatalogInterface $catalog    Resolves the source register and schema and searches its table.
	 */
	public function __construct(
		public ViewMapperInterface $viewMapper,
		public SchemaCatalogInterface $catalog,
	) {
	}//end __construct()
}//end class
