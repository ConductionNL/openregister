<?php

/**
 * AnalyticsSeriesMapperInterface: what callers outside the Db component use of AnalyticsSeriesMapper.
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

use OCP\AppFramework\Db\Entity;

/**
 * The methods of AnalyticsSeriesMapper that callers outside the Db component use.
 *
 * Callers type against this interface and resolve it from the container; the
 * registrar binds it to AnalyticsSeriesMapper. Guarded by C04InterfaceBindingsTest.
 */
interface AnalyticsSeriesMapperInterface {

	/**
	 * Find a series by its stable key.
	 *
	 * @param string $seriesKey The series key.
	 *
	 * @return AnalyticsSeries|null The series, or null when unknown.
	 */
	public function findByKey(string $seriesKey): ?AnalyticsSeries;

	/**
	 * Insert one row.
	 *
	 * @param Entity $entity The row to insert.
	 *
	 * @return AnalyticsSeries The inserted row, with its id.
	 */
	public function insert(Entity $entity): Entity;

	/**
	 * Update one row.
	 *
	 * @param Entity $entity The row to update.
	 *
	 * @return AnalyticsSeries The updated row.
	 */
	public function update(Entity $entity): Entity;
}//end interface
