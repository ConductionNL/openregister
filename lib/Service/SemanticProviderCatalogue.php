<?php

/**
 * OpenRegister SemanticProviderCatalogue
 *
 * The list of installed schemas that provide a canonical semantic type, as the
 * integrations capability advertises it under `semanticProviders`. Building the
 * list reads every schema the caller can see and walks their `allOf` parents,
 * and Nextcloud builds the capabilities on every page load, so the list is
 * cached per user and dropped whenever a schema, a register or an app changes.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/cross-app-semantic-references/tasks.md#task-4.1
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaCatalogInterface;
use OCP\App\IAppManager;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Lists `{ uri, register, registerSlug, schema, schemaSlug, appId, available, reason }`
 * for every semantic type an installed schema provides, cached per user.
 *
 * @spec openspec/changes/cross-app-semantic-references/tasks.md#task-4.1
 */
class SemanticProviderCatalogue {

	/**
	 * The distributed cache namespace; {@see self::clear()} empties all of it.
	 */
	public const CACHE_PREFIX = 'openregister_semantic_providers';

	/**
	 * How long a cached list may live when no change event drops it first, in seconds.
	 */
	public const TTL = 3600;

	/**
	 * The reason a provider whose owning app is installed but disabled is unavailable.
	 */
	public const REASON_APP_DISABLED = 'app-disabled';

	/**
	 * The reason a provider whose owning app is not installed is unavailable.
	 */
	public const REASON_APP_NOT_INSTALLED = 'app-not-installed';

	/**
	 * The cache, or null when none could be created (the list is then built every time).
	 *
	 * @var ICache|null
	 */
	private ?ICache $cache = null;

	/**
	 * Constructor.
	 *
	 * @param SchemaCatalogInterface $catalog      The register and schema mappers.
	 * @param SemanticTypeResolver   $resolver     Computes implemented types and owning apps.
	 * @param IAppManager            $appManager   Decides whether a provider's app is usable.
	 * @param IUserSession           $userSession  Keys the cache by user (the schema list is RBAC-scoped).
	 * @param ICacheFactory          $cacheFactory Creates the distributed cache.
	 * @param LoggerInterface        $logger       Logs a failed enumeration.
	 *
	 * @spec openspec/changes/cross-app-semantic-references/tasks.md#task-4.1
	 */
	public function __construct(
		private readonly SchemaCatalogInterface $catalog,
		private readonly SemanticTypeResolver $resolver,
		private readonly IAppManager $appManager,
		private readonly IUserSession $userSession,
		ICacheFactory $cacheFactory,
		private readonly LoggerInterface $logger,
	) {
		try {
			$this->cache = $cacheFactory->createDistributed(self::CACHE_PREFIX);
		} catch (Throwable) {
			$this->cache = null;
		}
	}//end __construct()

	/**
	 * The providers the current user can see, from the cache when it holds them.
	 *
	 * @return list<array<string, mixed>> One row per (semantic type, schema), ordered by uri then schema slug.
	 *
	 * @spec openspec/changes/cross-app-semantic-references/tasks.md#task-4.1
	 */
	public function list(): array {
		$key    = $this->cacheKey();
		$cached = $this->cache?->get($key);
		if (is_array($cached) === true) {
			return $cached;
		}

		$rows = $this->build();
		$this->cache?->set($key, $rows, self::TTL);

		return $rows;
	}//end list()

	/**
	 * Drop every cached list, for all users.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/cross-app-semantic-references/tasks.md#task-4.1
	 */
	public function clear(): void {
		$this->cache?->clear();
		$this->resolver->clearCache();
	}//end clear()

	/**
	 * Build the list from the schemas and registers the current user can see.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function build(): array {
		try {
			$schemas   = $this->catalog->schemas()->findAll();
			$registers = $this->catalog->registers()->findAll();
		} catch (Throwable $e) {
			$this->logger->debug(
				message: '[SemanticProviderCatalogue] enumeration failed, advertising no providers',
				context: ['file' => __FILE__, 'line' => __LINE__, 'exception' => $e->getMessage()]
			);
			return [];
		}

		$rows = [];
		foreach ($schemas as $schema) {
			foreach ($this->rowsFor(schema: $schema, registers: $registers) as $row) {
				$rows[] = $row;
			}
		}

		usort(
			$rows,
			static fn (array $a, array $b): int => [$a['uri'], (string) $a['schemaSlug']] <=> [$b['uri'], (string) $b['schemaSlug']]
		);

		return $rows;
	}//end build()

	/**
	 * One row per semantic type a schema provides.
	 *
	 * @param Schema         $schema    The schema.
	 * @param array<mixed>   $registers Every register the user can see.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function rowsFor(Schema $schema, array $registers): array {
		$types = $this->resolver->implementedTypesOf(schema: $schema);
		if ($types === []) {
			return [];
		}

		$register = $this->registerOf(schema: $schema, registers: $registers);
		$appId    = $this->resolver->owningAppOf(schema: $schema);
		$reason   = $this->unavailableReason(appId: $appId);

		$rows = [];
		foreach ($types as $uri) {
			$rows[] = [
				'uri'          => $uri,
				'register'     => $register?->getId(),
				'registerSlug' => $register?->getSlug(),
				'schema'       => $schema->getId(),
				'schemaSlug'   => $schema->getSlug(),
				'appId'        => $appId,
				'available'    => $reason === null,
				'reason'       => $reason,
			];
		}

		return $rows;
	}//end rowsFor()

	/**
	 * The first register whose schema list holds the schema, or null.
	 *
	 * @param Schema       $schema    The schema.
	 * @param array<mixed> $registers Every register the user can see.
	 *
	 * @return Register|null
	 */
	private function registerOf(Schema $schema, array $registers): ?Register {
		$schemaId = (int) $schema->getId();
		foreach ($registers as $register) {
			if ($register instanceof Register === false) {
				continue;
			}

			$members = array_map('intval', (array) $register->getSchemas());
			if (in_array($schemaId, $members, true) === true) {
				return $register;
			}
		}

		return null;
	}//end registerOf()

	/**
	 * Why a provider owned by this app cannot be used, or null when it can.
	 *
	 * A schema with no owning app, or owned by OpenRegister itself, is always usable.
	 *
	 * @param string|null $appId The owning app id.
	 *
	 * @return string|null One of the REASON_* constants, or null.
	 */
	private function unavailableReason(?string $appId): ?string {
		if ($appId === null || $appId === 'openregister') {
			return null;
		}

		try {
			if ($this->appManager->isEnabledForUser($appId) === true) {
				return null;
			}

			if ($this->appManager->isInstalled($appId) === true) {
				return self::REASON_APP_DISABLED;
			}

			return self::REASON_APP_NOT_INSTALLED;
		} catch (Throwable) {
			// Same fallback as the resolver: an app check that cannot run does not hide a provider.
			return null;
		}
	}//end unavailableReason()

	/**
	 * The cache key for the current user; the schema list is RBAC-scoped per user.
	 *
	 * @return string
	 */
	private function cacheKey(): string {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return 'providers:';
		}

		return 'providers:'.$user->getUID();
	}//end cacheKey()
}//end class
