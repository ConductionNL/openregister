<?php

/**
 * ObjectActionListInterface: what callers outside the Service component ask of ObjectActionList.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ObjectAction
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

namespace OCA\OpenRegister\Service\ObjectAction;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Exception\NotAuthorizedException;

/**
 * The actions offered on an object or a selection, as the object-actions
 * controller lists them. Only the methods that caller calls.
 */
interface ObjectActionListInterface {

	/**
	 * The actions offered on one object.
	 *
	 * @param ObjectEntity $object The object.
	 * @param Schema       $schema Its schema.
	 *
	 * @return list<array<string, mixed>> The entries.
	 *
	 * @throws NotAuthorizedException When the caller may not read the object.
	 */
	public function forObject(ObjectEntity $object, Schema $schema): array;

	/**
	 * The actions offered on a selection of a schema's objects.
	 *
	 * @param Schema          $schema   The schema.
	 * @param int|string|null $register The register, for inherited actions.
	 *
	 * @return list<array<string, mixed>> The entries.
	 */
	public function forSelection(Schema $schema, int|string|null $register): array;
}//end interface
