<?php

/**
 * OpenRegister SearchScopeCatalog
 *
 * The searchable schemas described by the names a search scope uses for them:
 * the schema slug, the slug of the register that owns it and the apps that
 * claim it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Search
 * @package  OCA\OpenRegister\Search
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/content-search-index/specs/unified-search-provider/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Search;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaCatalogInterface;
use OCA\OpenRegister\Service\DeepLinkRegistryServiceInterface;
use OCP\IL10N;
use Psr\Log\LoggerInterface;

/**
 * Describe the searchable schemas the way a scope names them.
 *
 * One description serves both readers of a scope: the search provider, which
 * narrows the schema list with it, and the OCS capability, which advertises the
 * scopes a reader may pick. Describing them twice would let a chip be offered
 * that the provider then reads differently.
 *
 * @spec openspec/changes/content-search-index/specs/unified-search-provider/spec.md
 */
class SearchScopeCatalog {
	/**
	 * Constructor.
	 *
	 * @param SchemaCatalogInterface                $catalog  Schemas (the searchable flag) and registers (ownership).
	 * @param LoggerInterface                       $logger   Where a description that cannot be built is reported.
	 * @param DeepLinkRegistryServiceInterface|null $deepLink The (register, schema) pairs each app claims; null claims none.
	 * @param IL10N|null                            $l10n     Names the OpenRegister provider identity; null offers no identities.
	 *
	 * @spec openspec/changes/content-search-index/specs/unified-search-provider/spec.md
	 */
	public function __construct(
		private readonly SchemaCatalogInterface $catalog,
		private readonly LoggerInterface $logger,
		private readonly ?DeepLinkRegistryServiceInterface $deepLink = null,
		private readonly ?IL10N $l10n = null,
	) {
	}//end __construct()

	/**
	 * The deep link claims: one registration per claimed (register, schema) pair.
	 *
	 * The registry keeps the first claim of a pair. Each registration carries
	 * `appId`, `registerSlug`, `schemaSlug` and an optional `displayName`. A
	 * registry that cannot be read throws; the caller decides what that means.
	 *
	 * @return array<int, object> The registrations; empty without a registry.
	 *
	 * @spec openspec/changes/platform-search-provider/specs/unified-search-provider/spec.md#requirement-a-claiming-app-has-its-own-search-provider-identity-req-psp-001
	 */
	public function registrations(): array {
		if ($this->deepLink === null) {
			return [];
		}

		return $this->deepLink->registrations();
	}//end registrations()

	/**
	 * The provider identities that own at least one of the given described schemas, in their order.
	 *
	 * A described row's pair belongs to the app that claimed it, else to the
	 * OpenRegister identity. An identity owning none of the rows is left out,
	 * so a list built from the rows a reader may read names no identity whose
	 * every schema the reader is refused.
	 *
	 * @param array<int, array<string, mixed>> $rows Described schemas, as describe() returns them.
	 *
	 * @return array<int, array{id: string, name: string, order: int}> The identities; empty without a translator.
	 *
	 * @spec openspec/changes/platform-search-provider/specs/unified-search-provider/spec.md#requirement-a-claiming-app-has-its-own-search-provider-identity-req-psp-001
	 */
	public function providers(array $rows): array {
		if ($this->l10n === null || empty($rows) === true) {
			return [];
		}

		$identities = new SearchProviderIdentities(l10n: $this->l10n, logger: $this->logger, claims: $this);

		$owning = [];
		foreach ($rows as $row) {
			$owning[$identities->forPair(registerSlug: (string)$row['register'], schemaSlug: (string)$row['slug'])->id] = true;
		}

		$providers = [];
		foreach ($identities->all() as $identity) {
			if (isset($owning[$identity->id]) === true) {
				$providers[] = $identity->jsonSerialize();
			}
		}

		return $providers;
	}//end providers()

	/**
	 * The searchable schemas with the names a scope uses for them.
	 *
	 * 🔑 THE REGISTER SLUG COMES FROM THE REGISTER THAT OWNS THE SCHEMA, not
	 * from the first register that happens to load. A schema belongs to exactly
	 * one register, and pairing it with the wrong one makes `register:dossiq`
	 * answer somebody else's rows — true-looking, and about the wrong data.
	 *
	 * 🔑 AN APP OWNS WHAT IT CLAIMS IN THE DEEP-LINK REGISTRY, keyed on the
	 * (register, schema) PAIR. A schema slug alone is not unique across
	 * registers, so `app:dossiq` keeps only the pairs dossiq claimed.
	 *
	 * Fails soft to an empty list and says so at ERROR level: a caller then
	 * narrows to nothing, which is the same outcome as a lookup that cannot run.
	 *
	 * @return array<int, array{
	 *     id: int, slug: string, title: string, register: string, registerTitle: string,
	 *     apps: array<int, string>, schema: Schema
	 * }> The schemas.
	 *
	 * @spec openspec/changes/content-search-index/specs/unified-search-provider/spec.md#requirement-the-provider-accepts-scopes-and-advertises-them
	 */
	public function describe(): array {
		try {
			$searchable = array_flip($this->catalog->schemas()->findSearchableIds());
			$owners     = $this->registerOwners();
			$claims     = $this->appClaims();

			$described = [];
			foreach ($this->catalog->schemas()->findAll() as $schema) {
				$id = (int)$schema->getId();
				if (array_key_exists($id, $searchable) === false) {
					continue;
				}

				$owner = ($owners[$id] ?? ['slug' => '', 'title' => '']);
				$slug  = (string)$schema->getSlug();

				$described[] = [
					'id' => $id,
					'slug' => $slug,
					'title' => (string)($schema->getTitle() ?? $slug),
					'register' => $owner['slug'],
					'registerTitle' => $owner['title'],
					'apps' => ($claims[strtolower($owner['slug']).'::'.strtolower($slug)] ?? []),
					'schema' => $schema,
				];
			}

			return $described;
		} catch (\Throwable $e) {
			$this->logger->error(
				'[SearchScopeCatalog] Failed to describe searchable schemas for scoping: {error}',
				['error' => $e->getMessage(), 'exception' => $e]
			);

			return [];
		}//end try
	}//end describe()

	/**
	 * The slug and title of the register that owns each schema, keyed by schema id.
	 *
	 * @return array<int, array{slug: string, title: string}> The owners.
	 */
	private function registerOwners(): array {
		$owners = [];
		foreach ($this->catalog->registers()->findAll() as $register) {
			$slug = (string)$register->getSlug();
			$owner = ['slug' => $slug, 'title' => (string)($register->getTitle() ?? $slug)];
			foreach (($register->getSchemas() ?? []) as $schemaId) {
				$owners[(int)$schemaId] = $owner;
			}
		}

		return $owners;
	}//end registerOwners()

	/**
	 * The apps that claim each (register, schema) pair, keyed `register::schema` in lower case.
	 *
	 * A registry that cannot be read claims nothing: an `app:` scope then keeps
	 * no schema, which is reported here rather than widened to every schema.
	 *
	 * @return array<string, array<int, string>> The claiming app ids per pair.
	 */
	private function appClaims(): array {
		if ($this->deepLink === null) {
			return [];
		}

		try {
			$registrations = $this->deepLink->registrations();
		} catch (\Throwable $e) {
			$this->logger->error(
				'[SearchScopeCatalog] Failed to read the deep-link registry; app scopes keep nothing: {error}',
				['error' => $e->getMessage(), 'exception' => $e]
			);

			return [];
		}

		$claims = [];
		foreach ($registrations as $registration) {
			$key = strtolower($registration->registerSlug).'::'.strtolower($registration->schemaSlug);
			$app = strtolower($registration->appId);
			if (in_array($app, ($claims[$key] ?? []), true) === false) {
				$claims[$key][] = $app;
			}
		}

		return $claims;
	}//end appClaims()
}//end class
