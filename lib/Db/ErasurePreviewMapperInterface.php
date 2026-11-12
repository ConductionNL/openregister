<?php

/**
 * ErasurePreviewMapperInterface: what callers outside the Db component use of ErasurePreviewMapper.
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
 * The methods of ErasurePreviewMapper that callers outside the Db component use.
 *
 * Callers type against this interface and resolve it from the container; the
 * registrar binds it to ErasurePreviewMapper. Guarded by C04InterfaceBindingsTest.
 */
interface ErasurePreviewMapperInterface {

	/**
	 * Create a preview row, assigning a uuid and timestamps.
	 *
	 * @param array<string, mixed> $data The preview values.
	 *
	 * @return ErasurePreview The persisted preview.
	 */
	public function createFromArray(array $data): ErasurePreview;

	/**
	 * Find a preview by its uuid.
	 *
	 * @param string $uuid The preview uuid.
	 *
	 * @return ErasurePreview The preview.
	 *
	 * @throws DoesNotExistException When no such preview exists.
	 */
	public function findByUuid(string $uuid): ErasurePreview;

	/**
	 * Persist a preview, refreshing the updated timestamp.
	 *
	 * @param ErasurePreview $preview The preview to persist.
	 *
	 * @return ErasurePreview The updated preview.
	 */
	public function save(ErasurePreview $preview): ErasurePreview;
}//end interface
