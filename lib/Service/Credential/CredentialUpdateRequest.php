<?php

/**
 * CredentialUpdateRequest — what a PUT on a credential is actually asking for.
 *
 * Three questions, and each has a wrong answer that looks reasonable.
 *
 * WHICH METADATA CHANGED. Absent means unchanged, never means empty. A client that
 * PUT a partial object would otherwise silently revoke every app grant on the
 * credential, and nothing would report it: the object would simply come back with an
 * empty `allowedApps` and every brokered call would start failing its second guard.
 *
 * WOULD THIS MOVE THE PINNED HOST. `instanceBaseUrl` is the host-lock of a
 * per-account credential, not a setting. Changing it would re-point an existing
 * credential at another server while keeping its allowedApps, its shares and every
 * credentialRef pointing at it, which is a different credential wearing the old one's
 * name. It is written once, at mint, and refused ever after. An update that merely
 * repeats the stored value is accepted, so a client round-tripping the whole object
 * is not punished for doing so.
 *
 * IS THERE A SECRET TO ROTATE. An empty or whitespace-only value is not a rotation to
 * nothing; it is a client that sent the field without meaning to, and honouring it
 * would break every call on the credential.
 *
 * It lives beside the controller rather than inside it because these are decisions
 * about a request, each with a stated reason, and a controller method that inlined
 * all three read as a list of ifs whose reasons had nowhere to live.
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

use OCP\IRequest;

/**
 * Reads the three decisions an update request carries.
 */
class CredentialUpdateRequest {
	/**
	 * Constructor.
	 *
	 * @param IRequest $request The current request.
	 *
	 * @return void
	 */
	public function __construct(private readonly IRequest $request) {
	}//end __construct()

	/**
	 * Apply the metadata the request offers, leaving the rest of the object alone.
	 *
	 * @param array<string, mixed> $data The stored credential's property bag.
	 *
	 * @return array<string, mixed> The property bag with the request's edits applied.
	 *
	 * @spec openspec/specs/credential-broker/spec.md
	 */
	public function applyTo(array $data): array {
		$name = $this->request->getParam('name');
		if (is_string($name) === true && trim($name) !== '') {
			$data['name'] = trim($name);
		}

		if ($this->request->getParam('allowedApps') !== null) {
			$data['allowedApps'] = $this->allowedApps();
		}

		return $data;
	}//end applyTo()

	/**
	 * Whether this update would move a credential's pinned host.
	 *
	 * @param array<string, mixed> $data The stored credential's property bag.
	 *
	 * @return boolean True when the request proposes a different host.
	 *
	 * @spec openspec/changes/credential-oauth2-token-set/specs/credential-oauth2-token-set/spec.md#requirement-a-per-account-host-is-pinned-at-mint-and-immutable-afterwards
	 */
	public function wouldRepointHost(array $data): bool {
		$proposed = $this->request->getParam('instanceBaseUrl');
		if (is_string($proposed) === false || trim($proposed) === '') {
			return false;
		}

		return trim($proposed) !== (string)($data['instanceBaseUrl'] ?? '');
	}//end wouldRepointHost()

	/**
	 * The rotated secret this request carries, or null when it carries none.
	 *
	 * The trim is not cosmetic. This rotation path writes straight to the vault and
	 * does NOT go through {@see CredentialBrokerService::mint()}, so it needs its own
	 * (credential-broker-upstream-diagnostics D3): a copy-pasted secret with a
	 * trailing newline previously reached the vault byte for byte and then failed
	 * header injection at call time with no usable diagnostic.
	 *
	 * @return string|null The trimmed secret, or null.
	 *
	 * @spec openspec/specs/credential-broker/spec.md
	 */
	public function rotatedSecret(): ?string {
		$secret = $this->request->getParam('secret');
		if (is_string($secret) === false) {
			return null;
		}

		$secret = trim($secret);
		if ($secret === '') {
			return null;
		}

		return $secret;
	}//end rotatedSecret()

	/**
	 * Coerce the request's allowedApps value into a string array.
	 *
	 * @return array<int, string> The sanitised app-id list.
	 *
	 * @spec exclude private request accessor with no behaviour of its own
	 */
	private function allowedApps(): array {
		$value = $this->request->getParam('allowedApps', []);
		if (is_array($value) === false) {
			return [];
		}

		$apps = [];
		foreach ($value as $entry) {
			if (is_string($entry) === true && $entry !== '') {
				$apps[] = $entry;
			}
		}

		return $apps;
	}//end allowedApps()
}//end class
