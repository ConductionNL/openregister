<?php

/**
 * ImportPreviewRowMapperInterface: what callers outside the Db component use of ImportPreviewRowMapper.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

/**
 * The methods of ImportPreviewRowMapper that callers outside the Db component use.
 *
 * Callers type against this interface and resolve it from the container; the
 * registrar binds it to ImportPreviewRowMapper. Guarded by C04InterfaceBindingsTest.
 */
interface ImportPreviewRowMapperInterface {

	/**
	 * One page of a preview's rows, in file order.
	 *
	 * @param int       $previewId The preview id.
	 * @param ListQuery $query     The page (unbounded for every row) and an optional `decision` filter.
	 *
	 * @return ImportPreviewRow[] The rows.
	 */
	public function findByPreview(int $previewId, ListQuery $query): array;
}//end interface
