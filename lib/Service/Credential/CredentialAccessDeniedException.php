<?php

/**
 * CredentialAccessDeniedException — a broker guard failed closed.
 *
 * Thrown by the credential broker when any of the four ordered guards (owner,
 * allowed-app, allow-rule, host-lock) or a precondition (missing token, missing
 * secret) rejects a request. It carries the REAL reason as its message for
 * server-side logging only — the controller maps every instance to a single
 * static, generic client error (`Request not permitted`, HTTP 403) so the
 * caller can never learn which guard failed (design.md D4, ADR-005 fail-closed).
 *
 * @category Exception
 * @package  OCA\OpenRegister\Service\Credential
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Credential;

use RuntimeException;

/**
 * Signals a fail-closed broker guard rejection (maps to a static 403).
 */
class CredentialAccessDeniedException extends RuntimeException
{
}//end class
