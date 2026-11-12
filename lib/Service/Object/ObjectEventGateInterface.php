<?php

/**
 * ObjectEventGateInterface: what callers outside the Service component ask of ObjectEventGate.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object
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

namespace OCA\OpenRegister\Service\Object;

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * The two draft questions a listener that issues identifiers asks before a
 * write. Dispatching the object events stays on the class.
 *
 * @spec openspec/changes/draft-creation-side-effects/specs/object-events/spec.md#requirement-a-generated-identifier-must-be-issued-when-the-object-leaves-draft
 */
interface ObjectEventGateInterface {

	/**
	 * Whether a write that stores this object for the first time is held from creation work: it is a draft.
	 *
	 * @param ObjectEntity $object The object about to be stored.
	 *
	 * @return bool True for a draft.
	 *
	 * @spec openspec/changes/draft-creation-side-effects/specs/object-events/spec.md#requirement-a-generated-identifier-must-be-issued-when-the-object-leaves-draft
	 */
	public function holdsCreation(ObjectEntity $object): bool;

	/**
	 * Whether a save takes a stored draft out of draft: the moment its held creation work runs.
	 *
	 * @param ObjectEntity      $new The object as it is about to be stored.
	 * @param ObjectEntity|null $old The object as stored, when the caller knows it.
	 *
	 * @return bool True when the stored object is a draft and the new one is not.
	 *
	 * @spec openspec/changes/draft-creation-side-effects/specs/object-events/spec.md#requirement-a-generated-identifier-must-be-issued-when-the-object-leaves-draft
	 */
	public function leavesDraft(ObjectEntity $new, ?ObjectEntity $old): bool;
}//end interface
