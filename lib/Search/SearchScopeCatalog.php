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
	 *
	 * @spec openspec/changes/content-search-index/specs/unified-search-provider/spec.md
	 */
	public function __construct(
		private readonly SchemaCatalogInterface $catalog,
		private readonly LoggerInterface $logger,
		private readonly ?DeepLinkRegistryServiceInterface $deepLink = null,
	) {
	}//end __construct()

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
