<?php

/**
 * OpenRegister LifecycleSubjectNotFoundException
 *
 * Raised when the object a lifecycle call names does not exist.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Exception
 * @package  OCA\OpenRegister\Exception
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

namespace OCA\OpenRegister\Exception;

use RuntimeException;

/**
 * The object a transition was asked for does not exist.
 *
 * Extends `RuntimeException` so REQ-007's "throw `RuntimeException` if not
 * found" still holds literally and every existing catch site behaves as it
 * did. It is a distinct type for one reason: the transition endpoint has
 * three failures a client must tell apart, and until this class existed two
 * of them shared a status code.
 *
 * - A move the object cannot take is a REFUSAL — HTTP 422, `RuntimeException`.
 * - A provider that could not run at all is a BREAKAGE — HTTP 502,
 *   {@see LifecycleProviderException}.
 * - An object that is not there is MISSING — HTTP 404, this class.
 *
 * The third used to answer 422 alongside the first, so "someone deleted this
 * case" and "this case cannot move yet" reached a handler as the same colour
 * of error. `TransitionController::availableActions()` already answered 404
 * for the read of a missing object; this is the write half of the same
 * sentence.
 */
class LifecycleSubjectNotFoundException extends RuntimeException {
}//end class
