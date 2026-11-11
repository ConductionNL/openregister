<?php

/**
 * CredentialMintOptions — the optional half of a mint: allowed apps, secret, scope and organisation.
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
 * Who may use a new credential, the secret it holds, and whose it is.
 *
 * The defaults are those of {@see CredentialBrokerService::mint()}: no allowed apps,
 * no secret (metadata only), personal scope, no organisation.
 *
 * @spec openspec/specs/credential-broker/spec.md
 */
final class CredentialMintOptions {
	/**
	 * The secrets, keyed by the options that hold them.
	 *
	 * Kept outside the instance so that no dump, export, array cast or
	 * serialisation of the options can reach the secret.
	 *
	 * @var \WeakMap<self, string|null>|null
	 */
	private static ?\WeakMap $secrets = null;

	/**
	 * Constructor.
	 *
	 * @param array<int, string> $allowedApps  The app ids permitted to use this credential (Guard 2 at broker time).
	 * @param string|null        $secret       The raw secret, or null/'' to mint metadata only (no vault write).
	 * @param string             $scope        The ALREADY-RESOLVED scope (`personal`|`organisation`).
	 * @param string|null        $organisation The ALREADY-GATED owning organisation UUID; required for the organisation scope.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly array $allowedApps=[],
		#[\SensitiveParameter] ?string $secret=null,
		public readonly string $scope='personal',
		public readonly ?string $organisation=null,
	) {
		self::$secrets ??= new \WeakMap();
		self::$secrets[$this] = $secret;
	}//end __construct()

	/**
	 * The raw secret, or null to mint metadata only.
	 *
	 * @return string|null The secret.
	 *
	 * @spec exclude ADR-011 value-object plumbing, accessor only
	 */
	public function secret(): ?string {
		return self::$secrets[$this] ?? null;
	}//end secret()

	/**
	 * The options as var_dump() and print_r() show them, with the secret masked.
	 *
	 * @return array<string, mixed> The fields, secret replaced by a marker.
	 *
	 * @spec exclude ADR-011 value-object plumbing, redaction only
	 */
	public function __debugInfo(): array {
		$secret = null;
		if ($this->secret() !== null) {
			$secret = '[redacted]';
		}

		return [
			'allowedApps'  => $this->allowedApps,
			'secret'       => $secret,
			'scope'        => $this->scope,
			'organisation' => $this->organisation,
		];
	}//end __debugInfo()

	/**
	 * Refuse serialisation: the secret cannot travel with the options.
	 *
	 * @return array<string, mixed> Never returns.
	 *
	 * @throws \LogicException Always.
	 *
	 * @spec exclude ADR-011 value-object plumbing, redaction only
	 */
	public function __serialize(): array {
		throw new \LogicException('CredentialMintOptions cannot be serialised.');
	}//end __serialize()
}//end class
