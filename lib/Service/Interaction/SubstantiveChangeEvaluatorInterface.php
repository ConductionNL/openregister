<?php

/**
 * SubstantiveChangeEvaluatorInterface: what callers outside the component use of SubstantiveChangeEvaluator.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Interaction
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Interaction;

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * Whether a save changed an object substantively.
 *
 * Declares only what callers outside the component use; the rest of the
 * class stays its own.
 */
interface SubstantiveChangeEvaluatorInterface {

	/**
	 * Whether one write gave other readers something new to see.
	 *
	 * A write with no previous state is a creation, which is substantive by
	 * definition: nobody has seen it, and there is no read state to invalidate
	 * anyway.
	 *
	 * @param ObjectEntity $new The object as it now stands.
	 * @param ObjectEntity|null $old The object as it stood before, when known.
	 *
	 * @return boolean True when the change should make the object unread again.
	 *
	 * @spec openspec/specs/object-read-state/spec.md#requirement-an-object-carries-a-read-state-per-user-req-ors-001
	 */
	public function isSubstantive(ObjectEntity $new, ?ObjectEntity $old = null): bool;
}//end interface
