<?php

/**
 * CredentialUpstreamException — the brokered outbound call could not complete.
 *
 * Thrown by the credential broker when, AFTER all four guards passed, the
 * outbound HTTP call to the provider host fails at the transport level (DNS,
 * TLS, timeout, connection reset). A non-2xx HTTP status from the provider is
 * NOT this exception — that is a completed call and is returned verbatim. This
 * exception maps to a single static client error (`Upstream request failed`,
 * HTTP 502); the real cause is logged server-side with the secret redacted
 * (design.md D4).
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
 * Signals a transport-level failure of the brokered call (maps to a static 502).
 */
class CredentialUpstreamException extends RuntimeException
{
}//end class
