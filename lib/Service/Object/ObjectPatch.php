<?php

/**
 * OpenRegister ObjectPatch
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

use OCP\IUser;

/**
 * One partial update: the object, the fields that change, where to find it and who acts.
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
final class ObjectPatch {

	/**
	 * Constructor.
	 *
	 * @param string           $objectId    The object's id or UUID, as the caller holds it.
	 * @param array            $data        The fields to merge onto the stored object.
	 * @param ObjectFindTarget $target      The register and schema the caller named.
	 * @param IUser|null       $currentUser Explicit acting user, or null for the session user.
	 *
	 * @phpstan-param array<string, mixed> $data
	 * @psalm-param   array<string, mixed> $data
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function __construct(
		public readonly string $objectId,
		public readonly array $data,
		public readonly ObjectFindTarget $target=new ObjectFindTarget(),
		public readonly ?IUser $currentUser=null,
	) {
	}//end __construct()
}//end class
