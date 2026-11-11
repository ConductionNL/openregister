<?php

/**
 * OpenRegister Credential — Broker Token Binding
 *
 * The single request a broker token may be bound to: an HTTP method and a path.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Credential
 * @package  OCA\OpenRegister\Service\Credential
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Credential;

/**
 * The call a broker token is bound to, or would be checked against.
 *
 * Bound only when both a method and a path are named: a method without a path
 * (or the reverse) leaves a token unbound, and a bound token checked against a
 * call that names neither does not match.
 *
 * @spec openspec/specs/credential-broker/spec.md
 */
final class BrokerTokenBinding {
	/**
	 * Constructor.
	 *
	 * @param string|null $method The HTTP method, or null.
	 * @param string|null $path   The provider-relative path, or null.
	 */
	public function __construct(
		public readonly ?string $method = null,
		public readonly ?string $path = null,
	) {
	}//end __construct()

	/**
	 * Whether both a method and a path are named.
	 *
	 * @return bool
	 */
	public function isBound(): bool {
		return $this->method !== null && $this->path !== null;
	}//end isBound()

	/**
	 * The request-binding digest of the named call.
	 *
	 * Normalises the method to upper-case and the path to a single leading slash
	 * so the digest is stable across trivially-different representations of the
	 * same call.
	 *
	 * @return string A SHA-256 hex digest of the normalised `METHOD /path`.
	 */
	public function digest(): string {
		return hash('sha256', strtoupper((string)$this->method) . ' /' . ltrim((string)$this->path, '/'));
	}//end digest()
}//end class
