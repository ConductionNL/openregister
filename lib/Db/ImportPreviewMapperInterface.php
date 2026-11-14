<?php

/**
 * ImportPreviewMapperInterface: what callers outside the Db component use of ImportPreviewMapper.
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

use DateTime;
use OCA\OpenRegister\Kernel\Contracts\ListQuery;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * The methods of ImportPreviewMapper that callers outside the Db component use.
 *
 * Callers type against this interface and resolve it from the container; the
 * registrar binds it to ImportPreviewMapper. Guarded by C04InterfaceBindingsTest.
 */
interface ImportPreviewMapperInterface {

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

	/**
	 * List the previews of one actor, newest first.
	 *
	 * @param string    $createdBy The actor's uid.
	 * @param ListQuery $query     The page (unbounded for every preview) and the optional `state` filter.
	 *
	 * @return ImportPreview[] The actor's previews.
	 */
	public function findByActor(string $createdBy, ListQuery $query=new ListQuery(limit: ListQuery::UNBOUNDED)): array;

	/**
	 * Persist a preview, moving its updated timestamp.
	 *
	 * @param ImportPreview $preview The preview to persist.
	 *
	 * @return ImportPreview The persisted preview.
	 */
	public function persist(ImportPreview $preview): ImportPreview;

	/**
	 * Find a preview by its uuid, which is the run identity.
	 *
	 * @param string $uuid The preview uuid.
	 *
	 * @return ImportPreview The preview.
	 *
	 * @throws DoesNotExistException When no such preview exists.
	 */
	public function findByUuid(string $uuid): ImportPreview;

	/**
	 * Runs created before a moment whose row detail has not been pruned yet, oldest first.
	 *
	 * @param DateTime $before The moment.
	 * @param int      $limit  At most this many.
	 *
	 * @return ImportPreview[] The runs.
	 */
	public function findUnprunedBefore(DateTime $before, int $limit): array;
}//end interface
