<?php

/**
 * The token identity resolved for the current request.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Audit
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/audit-trail-shipped-and-purpose-bound/specs/enhanced-audit-trail/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Audit;

/**
 * Carries the calling token for the length of one request.
 *
 * The same request-scoped shape as {@see PurposeContext}, and for the same
 * reason: the audit writer runs deep inside the save path with no access to
 * the authorisation layer that knows who is calling, and threading the caller
 * through every save signature is the change nobody makes.
 *
 * Resolution is LAZY AND CACHED. A write path produces many audit rows per
 * request and the resolution costs a token lookup; doing it once per request
 * rather than once per row is the difference between a bulk import that
 * finishes and one that does not. The cache holds the ABSENCE too, so a
 * browser session does not re-ask on every row.
 *
 * `claim()` exists for the authorisation layer, which knows something the
 * resolver cannot work out on its own: which registered consumer presented
 * the credential. A claim always wins over resolution.
 *
 * @spec openspec/changes/audit-trail-shipped-and-purpose-bound/specs/enhanced-audit-trail/spec.md
 */
class TokenContext {
	/**
	 * The identity the authorisation layer claimed for this request.
	 *
	 * @var TokenIdentity|null
	 */
	private ?TokenIdentity $claimed = null;

	/**
	 * The identity the resolver worked out, once it has been asked.
	 *
	 * @var TokenIdentity|null
	 */
	private ?TokenIdentity $resolved = null;

	/**
	 * Whether the resolver has run for this request.
	 *
	 * Separate from `$resolved` being null, which is a legitimate answer.
	 *
	 * @var boolean
	 */
	private bool $hasResolved = false;

	/**
	 * Constructor.
	 *
	 * @param TokenResolver $resolver Works out the calling token from the session.
	 */
	public function __construct(
		private readonly TokenResolver $resolver,
	) {
	}//end __construct()

	/**
	 * Record the identity the authorisation layer established.
	 *
	 * @param TokenIdentity|null $identity The identity, or null to clear.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/audit-trail-shipped-and-purpose-bound/specs/enhanced-audit-trail/spec.md
	 */
	public function claim(?TokenIdentity $identity): void {
		$this->claimed = $identity;
	}//end claim()

	/**
	 * The token identity an audit row written now should name.
	 *
	 * @return TokenIdentity|null The identity, or null when no token made this call.
	 *
	 * @spec openspec/changes/audit-trail-shipped-and-purpose-bound/specs/enhanced-audit-trail/spec.md
	 */
	public function identity(): ?TokenIdentity {
		if ($this->claimed !== null) {
			return $this->claimed;
		}

		if ($this->hasResolved === false) {
			$this->resolved = $this->resolver->resolve();
			$this->hasResolved = true;
		}

		return $this->resolved;
	}//end identity()

	/**
	 * Forget both the claim and the cached resolution.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/audit-trail-shipped-and-purpose-bound/specs/enhanced-audit-trail/spec.md
	 */
	public function clear(): void {
		$this->claimed = null;
		$this->resolved = null;
		$this->hasResolved = false;
	}//end clear()
}//end class
