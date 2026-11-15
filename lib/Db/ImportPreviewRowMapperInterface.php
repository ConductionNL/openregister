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

use OCA\OpenRegister\Kernel\Contracts\ListQuery;

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

	/**
	 * One page of a run's failed rows, in file order: skipped, refused, or a write that failed.
	 *
	 * @param int       $previewId The run's id.
	 * @param ListQuery $query     The page.
	 *
	 * @return ImportPreviewRow[] The rows.
	 */
	public function findFailed(int $previewId, ListQuery $query): array;

	/**
	 * Remove every row of a preview.
	 *
	 * @param int $previewId The preview id.
	 *
	 * @return void
	 */
	public function deleteByPreview(int $previewId): void;
}//end interface
