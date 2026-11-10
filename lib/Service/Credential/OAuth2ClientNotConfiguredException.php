<?php

/**
 * OAuth2ClientNotConfiguredException — no OAuth2 client is set up for a provider.
 *
 * Thrown when a connection needs the provider's client id and neither the
 * credential nor the instance default carries one. That is a state of this
 * server's configuration, not a fault in it: an administrator has not filed the
 * provider's developer application yet.
 *
 * It EXTENDS {@see CredentialAccessDeniedException} on purpose, like
 * {@see CredentialRelinkRequiredException}: every pre-existing
 * `catch (CredentialAccessDeniedException)` keeps failing closed, while the
 * connect start can catch this type and answer HTTP 409 rather than a 500.
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
 * Signals that the provider has no OAuth2 client configured on this server.
 */
class OAuth2ClientNotConfiguredException extends CredentialAccessDeniedException {
}//end class
