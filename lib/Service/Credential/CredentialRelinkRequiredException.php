<?php

/**
 * CredentialRelinkRequiredException — the grant behind a credential is gone.
 *
 * Thrown when an `oauth2-token-set` credential can no longer be refreshed because
 * the provider rejected the refresh with `invalid_grant`, and on every later call
 * against a credential already in `relink_needed` or `disabled`. It is a terminal
 * state, not a transient failure: retrying cannot fix it, and only a person
 * re-authorising can.
 *
 * It EXTENDS {@see CredentialAccessDeniedException} on purpose. A caller that wants
 * to offer a reconnect can catch this type specifically, while every pre-existing
 * `catch (CredentialAccessDeniedException)` in the tree keeps failing closed rather
 * than letting a new exception type fall through to an untyped error path. The
 * controller maps it to a static HTTP 409, which tells a client to reconnect
 * without telling it which guard or which provider error produced the state.
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

/**
 * Signals that a connection must be re-authorised before it can be used again.
 */
class CredentialRelinkRequiredException extends CredentialAccessDeniedException {
}//end class
