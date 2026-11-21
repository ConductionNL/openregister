<?php

/**
 * BrokerCallRun — the state of one brokered outbound call while its guards run.
 *
 * @category Service
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
 * One {@see CredentialBrokerService::request()}: what the caller asked for, and
 * what the guards established about the credential. Built by the broker and
 * never handed out (ADR-011 rule 3).
 *
 * @spec openspec/specs/credential-broker/spec.md
 */
final class BrokerCallRun {

	/**
	 * The `credential` object UUID.
	 *
	 * @var string
	 */
	public string $credentialId = '';

	/**
	 * The authenticated calling app id.
	 *
	 * @var string
	 */
	public string $appId = '';

	/**
	 * The upper-cased HTTP method.
	 *
	 * @var string
	 */
	public string $method = '';

	/**
	 * The provider-relative path as the caller gave it.
	 *
	 * @var string
	 */
	public string $path = '';

	/**
	 * The normalised path the allow-rules are matched against.
	 *
	 * @var string
	 */
	public string $matchPath = '';

	/**
	 * The caller's extra request headers.
	 *
	 * @var array<string, string>
	 */
	public array $headers = [];

	/**
	 * The raw request body, if any.
	 *
	 * @var string|null
	 */
	public ?string $body = null;

	/**
	 * The asserted user for a sessionless in-process caller, if any.
	 *
	 * @var string|null
	 */
	public ?string $actingUserId = null;

	/**
	 * The admitted credential's stored document.
	 *
	 * @var array<string, mixed>
	 */
	public array $data = [];

	/**
	 * The credential's scope.
	 *
	 * @var string
	 */
	public string $scope = '';

	/**
	 * The resolved catalogue provider entry.
	 *
	 * @var array<string, mixed>
	 */
	public array $provider = [];
}//end class
