<?php

/**
 * The event an app answers with what the identity provider asserted.
 *
 * OpenRegister does not speak to an identity provider, and should not start.
 * The app that holds the session with the provider is the one that knows what
 * it asserted, so this asks that app rather than guessing, and the derivation
 * reads whatever comes back.
 *
 * A listener answers like this:
 *
 *   $event->assert(claims: ['department' => 'vergunningen', 'groups' => ['jur']]);
 *
 * NOTHING HERE DECIDES ACCESS. The claims are an input to the rules an
 * administrator wrote down. A listener that asserts nothing leaves the account
 * with no derived access at all, which is the fail-closed direction: an account
 * whose mapping has gone missing must not keep yesterday's rights.
 *
 * @category Event
 * @package  OCA\OpenRegister\Event
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/permission-provenance-and-deny/specs/rbac-scopes/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Event;

use OCP\EventDispatcher\Event;

/**
 * Collects the claims one sign-in carried.
 *
 * @spec openspec/changes/permission-provenance-and-deny/specs/rbac-scopes/spec.md
 */
class IdentityClaimsCollectingEvent extends Event {

	/**
	 * The claims the listeners asserted.
	 *
	 * @var array<string, mixed>
	 */
	private array $claims = [];

	/**
	 * Constructor.
	 *
	 * @param string $userId The account signing in.
	 */
	public function __construct(
		private readonly string $userId,
	) {
		parent::__construct();

	}//end __construct()

	/**
	 * The account signing in.
	 *
	 * @return string The account id.
	 */
	public function getUserId(): string {
		return $this->userId;
	}//end getUserId()

	/**
	 * Assert what the provider said about this account.
	 *
	 * Later listeners add to the set rather than replacing it, and a claim two
	 * apps assert differently keeps the FIRST answer. An identity fact that
	 * changes depending on which app was asked is a conflict somebody has to
	 * resolve, and the quiet last-writer-wins is how it stays unresolved.
	 *
	 * @param array<string, mixed> $claims The claims.
	 *
	 * @return void
	 */
	public function assert(array $claims): void {
		foreach ($claims as $name => $value) {
			if (is_string($name) === false || $name === '') {
				continue;
			}

			if (array_key_exists($name, $this->claims) === true) {
				continue;
			}

			$this->claims[$name] = $value;
		}
	}//end assert()

	/**
	 * Everything the listeners asserted.
	 *
	 * @return array<string, mixed> The claims.
	 */
	public function getClaims(): array {
		return $this->claims;
	}//end getClaims()
}//end class
