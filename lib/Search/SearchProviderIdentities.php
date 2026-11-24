<?php

/**
 * The identities the objects search provider presents itself under.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Search
 * @package  OCA\OpenRegister\Search
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/platform-search-provider/specs/unified-search-provider/spec.md#requirement-a-claiming-app-has-its-own-search-provider-identity-req-psp-001
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Search;

use OCP\IL10N;
use Psr\Log\LoggerInterface;

/**
 * One provider identity per app that claimed a (register, schema) pair, plus OpenRegister's own.
 *
 * D-2: the identity is read from the deep link claim an app already makes,
 * never from a second declaration, so "this pair is ours" is said once. The
 * registry keeps the first claim of a pair, so a pair belongs to exactly one
 * app; a pair nobody claimed belongs to the OpenRegister identity.
 *
 * The order is the provider's own order for OpenRegister and the next ones
 * for the apps, in the order they first claimed, so the search bar lists the
 * same identities in the same order on every request.
 *
 * @spec openspec/changes/platform-search-provider/specs/unified-search-provider/spec.md#requirement-a-claiming-app-has-its-own-search-provider-identity-req-psp-001
 */
class SearchProviderIdentities {

	/**
	 * The order of the OpenRegister identity, the objects provider's own order.
	 *
	 * @var int
	 */
	public const BASE_ORDER = 10;

	/**
	 * The identities of this request, OpenRegister first; null until read.
	 *
	 * @var array<string, SearchProviderIdentity>|null
	 */
	private ?array $identities = null;

	/**
	 * Constructor.
	 *
	 * @param IL10N                   $l10n   Names the OpenRegister identity.
	 * @param LoggerInterface         $logger Where an unreadable registry is reported.
	 * @param SearchScopeCatalog|null $claims Reads the deep link claims; null means no app claimed anything.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/platform-search-provider/specs/unified-search-provider/spec.md#requirement-a-claiming-app-has-its-own-search-provider-identity-req-psp-001
	 */
	public function __construct(
		private readonly IL10N $l10n,
		private readonly LoggerInterface $logger,
		private readonly ?SearchScopeCatalog $claims = null,
	) {
	}//end __construct()

	/**
	 * Every identity, OpenRegister first, then the apps in the order they first claimed.
	 *
	 * @return array<int, SearchProviderIdentity> The identities.
	 *
	 * @spec openspec/changes/platform-search-provider/specs/unified-search-provider/spec.md#requirement-a-claiming-app-has-its-own-search-provider-identity-req-psp-001
	 */
	public function all(): array {
		return array_values($this->identities());
	}//end all()

	/**
	 * The identity an id names, or null when none does.
	 *
	 * @param string $id The identity id, compared case-insensitively.
	 *
	 * @return SearchProviderIdentity|null The identity, or null.
	 *
	 * @spec openspec/changes/platform-search-provider/specs/unified-search-provider/spec.md#requirement-a-claiming-app-has-its-own-search-provider-identity-req-psp-001
	 */
	public function byId(string $id): ?SearchProviderIdentity {
		return ($this->identities()[strtolower(trim($id))] ?? null);
	}//end byId()

	/**
	 * The identity a register and schema belong to: its claiming app, else OpenRegister.
	 *
	 * @param string $registerSlug The register slug.
	 * @param string $schemaSlug   The schema slug.
	 *
	 * @return SearchProviderIdentity The owning identity.
	 *
	 * @spec openspec/changes/platform-search-provider/specs/unified-search-provider/spec.md#requirement-a-claiming-app-has-its-own-search-provider-identity-req-psp-001
	 */
	public function forPair(string $registerSlug, string $schemaSlug): SearchProviderIdentity {
		foreach ($this->identities() as $identity) {
			if ($identity->claims(registerSlug: $registerSlug, schemaSlug: $schemaSlug) === true) {
				return $identity;
			}
		}

		return $this->identities()[SearchProviderIdentity::OPENREGISTER];
	}//end forPair()

	/**
	 * The identities, read from the claims once per request.
	 *
	 * @return array<string, SearchProviderIdentity> The identities by id.
	 */
	private function identities(): array {
		if ($this->identities !== null) {
			return $this->identities;
		}

		$identities = [
			SearchProviderIdentity::OPENREGISTER => new SearchProviderIdentity(
				id: SearchProviderIdentity::OPENREGISTER,
				name: $this->l10n->t('Open Register Objects'),
				order: self::BASE_ORDER
			),
		];

		$claims = $this->claimsByApp();
		$order  = self::BASE_ORDER;
		foreach ($claims as $appId => $claim) {
			$order++;
			$identities[$appId] = new SearchProviderIdentity(
				id: $appId,
				name: $claim['name'],
				order: $order,
				pairs: $claim['pairs']
			);
		}

		$this->identities = $identities;
		return $this->identities;
	}//end identities()

	/**
	 * The claimed pairs per app, in the order the apps first claimed, with the app's name.
	 *
	 * The name is the first display name the app gave; an app that gave none
	 * is named by its id. A registry that cannot be read leaves only the
	 * OpenRegister identity, and says so at ERROR level.
	 *
	 * @return array<string, array{name: string, pairs: array<int, string>}> The claims by app id.
	 */
	private function claimsByApp(): array {
		if ($this->claims === null) {
			return [];
		}

		try {
			$registrations = $this->claims->registrations();
		} catch (\Throwable $e) {
			$this->logger->error(
				'[SearchProviderIdentities] Failed to read the deep-link registry; only the OpenRegister identity is offered: {error}',
				['error' => $e->getMessage(), 'exception' => $e]
			);

			return [];
		}

		$claims = [];
		foreach ($registrations as $registration) {
			$appId = strtolower(trim($registration->appId));
			if ($appId === '' || $appId === SearchProviderIdentity::OPENREGISTER) {
				continue;
			}

			$claim = ($claims[$appId] ?? ['name' => '', 'pairs' => []]);
			if ($claim['name'] === '' && trim((string)$registration->displayName) !== '') {
				$claim['name'] = trim((string)$registration->displayName);
			}

			$pair = SearchProviderIdentity::pairKey(registerSlug: $registration->registerSlug, schemaSlug: $registration->schemaSlug);
			if (in_array($pair, $claim['pairs'], true) === false) {
				$claim['pairs'][] = $pair;
			}

			$claims[$appId] = $claim;
		}

		foreach ($claims as $appId => $claim) {
			if ($claim['name'] === '') {
				$claims[$appId]['name'] = $appId;
			}
		}

		return $claims;
	}//end claimsByApp()
}//end class
