<?php

/**
 * ObjectHandlerAccessInterface: the object operations plus the handlers a controller reads from them.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
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

namespace OCA\OpenRegister\Service;

use OCA\OpenRegister\Dto\DeletionAnalysis;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\Object\CacheHandlerInterface;
use OCA\OpenRegister\Service\Object\DeleteObject;
use OCA\OpenRegister\Service\Object\PermissionHandlerInterface;

/**
 * What ObjectsController and TagsController ask of ObjectService: the
 * operations on ObjectOperationsInterface, which stays unchanged, plus the
 * handlers they read from it and the readable-deletion-analysis filter.
 *
 * Bound to ObjectService in ContractBindings, so it resolves to the same
 * shared instance as ObjectOperationsInterface.
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
interface ObjectHandlerAccessInterface extends ObjectOperationsInterface {

	/**
	 * Get the cache handler.
	 *
	 * @return CacheHandlerInterface The cache handler.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function getCacheHandler(): CacheHandlerInterface;

	/**
	 * Get the delete handler.
	 *
	 * @return DeleteObject The delete handler.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function getDeleteHandler(): DeleteObject;

	/**
	 * A deletion analysis without the objects the signed-in caller cannot read.
	 *
	 * @param DeletionAnalysis $analysis The analysis of the unscoped integrity walk.
	 *
	 * @return DeletionAnalysis The analysis the caller may see.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function readableDeletionAnalysis(DeletionAnalysis $analysis): DeletionAnalysis;

	/**
	 * Get the permission handler.
	 *
	 * @return PermissionHandlerInterface The permission handler.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function getPermissionHandler(): PermissionHandlerInterface;
}//end interface
