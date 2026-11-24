<?php
/**
 * ObjectFileRightsInterface: what the signed-in user may do with an object's files.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts\Core
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts\Core;

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * The file rights of the signed-in user on one object, with the file verbs.
 *
 * Core asks it when it mirrors a sharer's rights onto an object folder; Files
 * decides the rights and binds its answer in its own registrar.
 *
 * @spec openspec/specs/file-actions/spec.md
 */
interface ObjectFileRightsInterface {

	/**
	 * The file verb for reading an object's files.
	 *
	 * @var string
	 */
	public const READ_FILES = 'readFiles';

	/**
	 * The file verb for adding a file or changing one.
	 *
	 * @var string
	 */
	public const ADD_FILES = 'addFiles';

	/**
	 * The file verb for deleting a file.
	 *
	 * @var string
	 */
	public const DELETE_FILES = 'deleteFiles';

	/**
	 * Whether the caller may read an object and its files.
	 *
	 * @param ObjectEntity $object       The object.
	 * @param bool         $multitenancy Whether the organisation filter applies.
	 *
	 * @return bool True when the caller may read it.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) The same read with or without the organisation filter.
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	public function mayRead(ObjectEntity $object, bool $multitenancy = true): bool;

	/**
	 * Whether the caller may change an object's files with this verb.
	 *
	 * @param ObjectEntity $object The object, already known to be readable.
	 * @param string       $verb   addFiles or deleteFiles.
	 *
	 * @return bool True when the caller may make the change.
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	public function mayChangeFiles(ObjectEntity $object, string $verb = self::ADD_FILES): bool;
}//end interface
