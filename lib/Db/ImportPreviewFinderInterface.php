<?php

/**
 * ImportPreviewFinderInterface: what callers outside the Db component ask of ImportPreviewMapper.
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

use OCP\AppFramework\Db\DoesNotExistException;

/**
 * Reading one import preview, as the import preview runner does. Only the method that caller calls.
 */
interface ImportPreviewFinderInterface {

	/**
	 * Find a preview by its numeric id.
	 *
	 * @param int $id The preview id.
	 *
	 * @return ImportPreview The preview.
	 *
	 * @throws DoesNotExistException When no such preview exists.
	 */
	public function find(int $id): ImportPreview;
}//end interface
