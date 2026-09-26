<?php

/**
 * What a grant must be before it may be issued (task 1.1, C40.1).
 *
 * Every refusal here exists because the alternative is a token that is wider
 * than it looks:
 *
 * 🔴 NO VERBS AT ALL. An empty list is the shape that turns a filter into an
 * unconditional grant when somebody later writes the intersection as "if the
 * list is empty, do not narrow". Refused at issue, and `TokenGrant::permits()`
 * answers false for it anyway, so the trap is closed twice.
 *
 * 🔴 `manage`. It changes the access rules themselves, so a token holding it
 * can widen its own audience (D-3).
 *
 * 🔴 A VERB THE ISSUER DOES NOT HOLD. A grant is a filter over the issuer's
 * rights, so minting one wider than their own is not narrowing, it is
 * escalation with extra steps.
 *
 * 🔴 NO END DATE. An optional expiry is an expiry nobody sets, and the
 * six-week migration token kept for six years is the finding that follows
 * (C-access-and-privacy-43, a `must` and a matrix hole).
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/scoped-api-tokens/specs/auth-system/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rbac;

use DateTimeInterface;

/**
 * Refuses a grant that cannot be issued, naming why.
 *
 * @spec openspec/changes/scoped-api-tokens/specs/auth-system/spec.md
 */
class TokenGrantValidator {

	/**
	 * Constructor.
	 *
	 * @param PermissionCatalogue $catalogue The canonical verb vocabulary.
	 */
	public function __construct(
		private readonly PermissionCatalogue $catalogue,
	) {
	}//end __construct()

	/**
	 * Why a grant may not be issued, or null when it may.
	 *
	 * @param array<string, mixed> $grant       The submitted grant.
	 * @param array<int, string>   $issuerVerbs The verbs the issuer themselves holds.
	 * @param DateTimeInterface    $now         The moment of issue.
	 *
	 * @return string|null The reason, or null.
	 *
	 * @spec openspec/changes/scoped-api-tokens/specs/auth-system/spec.md
	 */
	public function refusalFor(array $grant, array $issuerVerbs, DateTimeInterface $now): ?string {
		// The four checks run in the ORDER they used to, and each returns the
		// same sentence it used to, because the first refusal is the one the
		// caller shows and reordering them would change which one that is.
		return ($this->verbRefusal(verbs: ($grant['verbs'] ?? null), issuerVerbs: $issuerVerbs)
			?? $this->expiryRefusal(grant: $grant, now: $now)
			?? $this->axisRefusal(grant: $grant)
			?? $this->rateLimitRefusal(rateLimit: ($grant['rateLimit'] ?? null)));
	}//end refusalFor()

	/**
	 * Why the grant's verb list may not be issued, or null when it may.
	 *
	 * @param mixed              $verbs       The submitted verb list, unchecked.
	 * @param array<int, string> $issuerVerbs The verbs the issuer themselves holds.
	 *
	 * @return string|null The reason, or null.
	 *
	 * @spec openspec/changes/scoped-api-tokens/specs/auth-system/spec.md
	 */
	private function verbRefusal(mixed $verbs, array $issuerVerbs): ?string {
		if (is_array($verbs) === false || $verbs === []) {
			return 'a grant must name at least one verb; an empty list is not "every verb", it is a filter that filters nothing';
		}

		$known = $this->catalogue->verbs();
		foreach ($verbs as $verb) {
			$verb = (string)$verb;

			if ($verb === TokenGrant::UNGRANTABLE) {
				return sprintf('"%s" cannot be granted to a token: it changes the access rules themselves', $verb);
			}

			if (in_array($verb, $known, true) === false) {
				return sprintf('"%s" is not a permission verb this instance knows', $verb);
			}

			if (in_array($verb, $issuerVerbs, true) === false) {
				return sprintf('"%s" is wider than the issuer\'s own rights; a token narrows, it never mints', $verb);
			}
		}

		return null;
	}//end verbRefusal()

	/**
	 * Why the grant's end date may not be issued, or null when it may.
	 *
	 * @param array<string, mixed> $grant The submitted grant.
	 * @param DateTimeInterface    $now   The moment of issue.
	 *
	 * @return string|null The reason, or null.
	 *
	 * @spec openspec/changes/scoped-api-tokens/specs/auth-system/spec.md
	 */
	private function expiryRefusal(array $grant, DateTimeInterface $now): ?string {
		$expiresAt = ($grant['expiresAt'] ?? null);
		if (is_string($expiresAt) === false || $expiresAt === '') {
			return 'a grant must carry an end date; a token without one is not issued';
		}

		$parsed = TokenGrant::fromStored(stored: $grant);
		if ($parsed === null || $parsed->expiresAt === null) {
			return sprintf('the end date "%s" could not be read as a date', $expiresAt);
		}

		if ($parsed->isExpired(now: $now) === true) {
			return 'the end date is in the past, so the token would be issued already lapsed';
		}

		return null;
	}//end expiryRefusal()

	/**
	 * Why the grant's register and schema axes may not be issued.
	 *
	 * @param array<string, mixed> $grant The submitted grant.
	 *
	 * @return string|null The reason, or null.
	 *
	 * @spec openspec/changes/scoped-api-tokens/specs/auth-system/spec.md
	 */
	private function axisRefusal(array $grant): ?string {
		foreach (['registers', 'schemas'] as $axis) {
			if (array_key_exists($axis, $grant) === false) {
				continue;
			}

			if (is_array($grant[$axis]) === false) {
				return sprintf('"%s" must be a list of slugs when it is present at all', $axis);
			}

			// A present-but-empty axis is refused rather than silently read as
			// "every one": the two readings are opposite, and the empty list is
			// the one a form produces when nobody chose anything.
			if ($grant[$axis] === []) {
				return sprintf(
					'"%s" is present but empty; leave it out to mean "not scoped by %s", because an empty list reads as both "none" and "all"',
					$axis,
					$axis
				);
			}
		}

		return null;
	}//end axisRefusal()

	/**
	 * Why the grant's rate limit may not be issued, or null when it may.
	 *
	 * @param mixed $rateLimit The submitted rate limit, unchecked.
	 *
	 * @return string|null The reason, or null.
	 *
	 * @spec openspec/changes/scoped-api-tokens/specs/auth-system/spec.md
	 */
	private function rateLimitRefusal(mixed $rateLimit): ?string {
		if ($rateLimit !== null && (is_numeric($rateLimit) === false || (int)$rateLimit < 1)) {
			return 'a rate limit must be a positive number of calls per minute';
		}

		return null;
	}//end rateLimitRefusal()

	/**
	 * Whether a grant lapses soon enough to warn its holder about (C40.2).
	 *
	 * @param TokenGrant        $grant  The grant.
	 * @param DateTimeInterface $now    The moment.
	 * @param int               $within How many days ahead counts as soon.
	 *
	 * @return bool True when the holder should be warned.
	 *
	 * @spec openspec/changes/scoped-api-tokens/specs/auth-system/spec.md
	 */
	public function lapsesSoon(TokenGrant $grant, DateTimeInterface $now, int $within = 14): bool {
		$days = $grant->daysLeft(now: $now);
		if ($days === null) {
			return false;
		}

		return ($days >= 0 && $days <= $within);
	}//end lapsesSoon()
}//end class
