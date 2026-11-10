<?php

/**
 * OAuth2RegistrationFailedException — the account's own server refused a client.
 *
 * Thrown when registering an OAuth2 application at a per-instance provider's
 * server (a Mastodon instance) fails: the server could not be reached, refused
 * the registration, or answered without a client id. The fault is upstream, so
 * the connect start answers HTTP 502 rather than a 500.
 *
 * It extends RuntimeException, which the registration threw before, so any
 * existing `catch (RuntimeException)` still catches it.
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
 * Signals that a per-instance provider's server did not register a client.
 */
class OAuth2RegistrationFailedException extends RuntimeException {
}//end class
