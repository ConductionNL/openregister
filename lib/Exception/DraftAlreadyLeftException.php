<?php

/**
 * The draft a save was meant to make definitive is no longer a draft.
 *
 * Two requests on one draft (a double click, a client retry) can both pass the
 * "is it still a draft" read before either commits. The second save then loads
 * the object already `active`. It is refused with this exception, so it writes
 * nothing and announces nothing a second time.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Exception
 * @package  OCA\OpenRegister\Exception
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/draft-creation-side-effects/specs/object-events/spec.md#requirement-creation-side-effects-must-be-held-for-a-draft-and-run-once-when-it-leaves-draft
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Exception;

/**
 * A promotion was allowed for an object that has already left draft.
 *
 * @spec openspec/changes/draft-creation-side-effects/specs/object-events/spec.md#requirement-creation-side-effects-must-be-held-for-a-draft-and-run-once-when-it-leaves-draft
 */
class DraftAlreadyLeftException extends ValidationException {
}//end class
