<?php

/**
 * OpenRegister LifecycleProviderException
 *
 * Raised when a provider-mode lifecycle cannot be read: the declared tag
 * resolves to nothing, resolves to the wrong type, or the provider itself
 * throws while answering.
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
 * A provider-mode lifecycle could not be read.
 *
 * Extends `RuntimeException` so every existing lifecycle catch site keeps
 * behaving as it did, and is a distinct type so the one caller that must
 * tell the two apart can: `TransitionController::availableActions()` maps
 * an ordinary `RuntimeException` to 404 "no such object" and this to 502
 * "the provider could not answer". Collapsing the second into an empty
 * action list is the bug this class exists to prevent — a client cannot
 * tell a silent failure from a state with genuinely no moves.
 *
 * Distinct from `ProviderUnavailableException`, which classifies external
 * integration failures with its own `CAUSE_*` vocabulary and maps to 503.
 */
class LifecycleProviderException extends RuntimeException {
}//end class
