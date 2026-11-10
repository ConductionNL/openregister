<?php

/**
 * MessageDispatcherInterface: what callers outside the Service component use of MessageDispatchProvider.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Integration\Providers
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

namespace OCA\OpenRegister\Service\Integration\Providers;

/**
 * Sends one outbound message through a configured OpenConnector source.
 *
 * @spec openspec/specs/integration-message-dispatch/spec.md
 */
interface MessageDispatcherInterface {

	/**
	 * Dispatch one message.
	 *
	 * @param string               $source  The seeded source slug to send through (one of ALLOWED_SOURCES).
	 * @param array<string,mixed>  $body    The provider-shaped request body.
	 * @param string               $path    Path relative to the source base URL.
	 * @param array<string,string> $headers Optional extra request headers.
	 *
	 * @return array<string,mixed> `{ status: 'sent', response }` on success, or `{ unavailable: true, cause }`.
	 *
	 * @spec openspec/specs/integration-message-dispatch/spec.md
	 */
	public function dispatch(string $source, array $body, string $path, array $headers = []): array;
}//end interface
