<?php

/**
 * OpenRegister ContextChat ContentProvider
 *
 * Implements `OCP\ContextChat\IContentProvider` so opted-in, published
 * register objects are indexed into the Nextcloud Assistant's RAG pipeline.
 * Registration is soft-gated (see ContentProviderRegistrationListener) so a
 * standalone instance without the `context_chat` app installed is
 * unaffected — `OCP\ContextChat\*` are core Nextcloud interfaces (present
 * since NC 32) that resolve safely via DI regardless of whether the
 * `context_chat` app itself is enabled.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category ContextChat
 * @package  OCA\OpenRegister\ContextChat
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/context-chat-provider/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\ContextChat;

use OCA\OpenRegister\Kernel\Contracts\ListQuery;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterSchema;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaCatalogInterface;
use OCA\OpenRegister\ContextChat\Listener\ContextChatSubmissionListenerInterface;
use OCA\OpenRegister\Service\DeepLinkResolverInterface;
use OCP\ContextChat\IContentProvider;
use Psr\Log\LoggerInterface;
use Throwable;
use OCA\OpenRegister\ContextChat\Support\ContentProviderLinks;

/**
 * Content provider surfacing OpenRegister objects to Context Chat.
 *
 * @spec openspec/specs/context-chat-provider/spec.md
 */
class ContentProvider implements IContentProvider, ContentProviderInterface {

	/**
	 * Batch size used when walking a (register, schema) pair during import.
	 *
	 * @var int
	 */
	private const BATCH_SIZE = 200;

	/**
	 * Wire collaborators.
	 *
	 * @param ContextChatSubmissionListenerInterface $submissionListener Shared submission logic (single object).
	 * @param SchemaCatalogInterface                 $catalog            Schema, register and magic-table lookups.
	 * @param ContentProviderLinks                   $links              Deep-link resolver and URL generator.
	 * @param LoggerInterface                        $logger             PSR logger.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/context-chat-provider/spec.md
	 */
	public function __construct(
		private readonly ContextChatSubmissionListenerInterface $submissionListener,
		private readonly SchemaCatalogInterface $catalog,
		private readonly ContentProviderLinks $links,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The unique identifier for this provider.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/context-chat-provider/spec.md
	 */
	public function getId(): string {
		return self::PROVIDER_ID;
	}//end getId()

	/**
	 * The app id making this provider available.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/context-chat-provider/spec.md
	 */
	public function getAppId(): string {
		return self::APP_ID;
	}//end getAppId()

	/**
	 * Resolve the absolute URL a user would reach this content item at.
	 *
	 * Resolves via {@see DeepLinkResolverInterface::resolveUrl()} — the same
	 * mechanism `ObjectsProvider` already uses for unified-search result
	 * URLs — and falls back to the `openregister.objects.show` route when no
	 * app has claimed a deep link for the object's (register, schema) pair.
	 *
	 * @param string $id The object's uuid (the content item's `itemId`).
	 *
	 * @return string The resolved, absolute URL.
	 *
	 * @spec openspec/specs/context-chat-provider/spec.md#requirement-getitemurl-and-initial-import-reuse-existing-openregister-infrastructure
	 */
	public function getItemUrl(string $id): string {
		try {
			$object = $this->catalog->tables()->find(
				identifier: $id,
				includeDeleted: false,
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $e) {
			$this->logger->debug(
				message: '[ContentProvider] getItemUrl: object not found, falling back to generic route',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'id' => $id,
					'error' => $e->getMessage(),
				]
			);
			return $this->links->urlGenerator->linkToRoute(
				'openregister.objects.show',
				['register' => 0, 'schema' => 0, 'id' => $id]
			);
		}//end try

		$registerId = (int)$object->getRegister();
		$schemaId = (int)$object->getSchema();

		$flatData = array_merge(
			($object->getObject() ?? []),
			[
				'id' => $object->getUuid(),
				'uuid' => $object->getUuid(),
				'register' => $registerId,
				'schema' => $schemaId,
			]
		);

		$url = $this->links->deepLinkRegistry->resolveUrl(
			registerId: $registerId,
			schemaId: $schemaId,
			objectData: $flatData
		);
		if ($url !== null) {
			return $url;
		}

		return $this->links->urlGenerator->linkToRoute(
			'openregister.objects.show',
			['register' => $registerId, 'schema' => $schemaId, 'id' => $object->getUuid()]
		);
	}//end getItemUrl()

	/**
	 * Walk every opted-in (register, schema) pair and submit each qualifying
	 * (published) object, in bounded batches.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/context-chat-provider/spec.md#requirement-getitemurl-and-initial-import-reuse-existing-openregister-infrastructure
	 */
	public function triggerInitialImport(): void {
		$this->reindex(registerId: null, schemaId: null);
	}//end triggerInitialImport()

	/**
	 * Batched (re)submission walk, optionally scoped to a single register
	 * and/or schema. Shared by {@see triggerInitialImport()} and
	 * `openregister:contextchat:reindex`.
	 *
	 * @param int|null $registerId Optional register id to scope the walk to.
	 * @param int|null $schemaId Optional schema id to scope the walk to.
	 *
	 * @return int Number of objects actually submitted (published, opted-in objects).
	 *
	 * @spec openspec/specs/context-chat-provider/spec.md#requirement-getitemurl-and-initial-import-reuse-existing-openregister-infrastructure
	 */
	public function reindex(?int $registerId, ?int $schemaId): int {
		$submitted = 0;

		foreach ($this->resolveOptedInPairs(registerId: $registerId, schemaId: $schemaId) as $pair) {
			[$register, $schema] = $pair;

			$offset = 0;
			do {
				$batch = $this->catalog->tables()->findAllInRegisterSchemaTable(
					table: new RegisterSchema(register: $register, schema: $schema),
					listing: new ListQuery(limit: self::BATCH_SIZE, offset: $offset)
				);

				// Counted once per page, not re-counted by the loop condition.
				// $batch is replaced wholesale on every iteration and never
				// mutated below, so this is the same value the condition read.
				$batchSize = count($batch);

				foreach ($batch as $object) {
					if ($this->submissionListener->submitIfEligible(object: $object, schema: $schema) === true) {
						$submitted++;
					}
				}

				$offset += self::BATCH_SIZE;
			} while ($batchSize === self::BATCH_SIZE);
		}//end foreach

		return $submitted;
	}//end reindex()

	/**
	 * Resolve every opted-in (register, schema) pair, optionally narrowed to
	 * a single register and/or schema id.
	 *
	 * @param int|null $registerId Optional register id filter.
	 * @param int|null $schemaId Optional schema id filter.
	 *
	 * @return array<int, array{0: Register, 1: Schema}> List of (register, schema) pairs.
	 *
	 * @spec openspec/specs/context-chat-provider/spec.md#requirement-getitemurl-and-initial-import-reuse-existing-openregister-infrastructure
	 */
	private function resolveOptedInPairs(?int $registerId, ?int $schemaId): array {
		$pairs = [];

		foreach ($this->schemasForReindex() as $schema) {
			if (self::isSchemaInScope(schema: $schema, schemaId: $schemaId) === false) {
				continue;
			}

			$pairs = array_merge($pairs, $this->pairsForSchema(schema: $schema, registerId: $registerId));
		}

		return $pairs;
	}//end resolveOptedInPairs()

	/**
	 * Every schema, unfiltered by RBAC or tenancy, or none when they cannot be listed.
	 *
	 * @return Schema[] The schemas.
	 */
	private function schemasForReindex(): array {
		try {
			return $this->catalog->schemas()->findAll(_rbac: false, _multitenancy: false);
		} catch (Throwable $e) {
			$this->logger->warning(
				message: '[ContentProvider] Failed to enumerate schemas for reindex',
				context: ['file' => __FILE__, 'line' => __LINE__, 'error' => $e->getMessage()]
			);
			return [];
		}
	}//end schemasForReindex()

	/**
	 * Whether a schema takes part in the reindex: opted in and, when a schema
	 * id is given, that schema.
	 *
	 * @param Schema   $schema   The schema.
	 * @param int|null $schemaId Optional schema id filter.
	 *
	 * @return bool True when the schema is walked.
	 */
	private static function isSchemaInScope(Schema $schema, ?int $schemaId): bool {
		if ($schema->isContextChatIndexingEnabled() === false) {
			return false;
		}

		return $schemaId === null || $schema->getId() === $schemaId;
	}//end isSchemaInScope()

	/**
	 * The (register, schema) pairs of one schema, optionally narrowed to one register id.
	 *
	 * @param Schema   $schema     The opted-in schema.
	 * @param int|null $registerId Optional register id filter.
	 *
	 * @return array<int, array{0: Register, 1: Schema}> List of (register, schema) pairs.
	 */
	private function pairsForSchema(Schema $schema, ?int $registerId): array {
		$pairs = [];
		$registerIds = $this->catalog->registers()->getAllRegisterIdsWithSchema((int)$schema->getId());
		foreach ($registerIds as $candidateRegisterId) {
			if (self::isRegisterInScope(candidateRegisterId: $candidateRegisterId, registerId: $registerId) === false) {
				continue;
			}

			$register = $this->findRegisterForReindex(registerId: $candidateRegisterId);
			if ($register !== null) {
				$pairs[] = [$register, $schema];
			}
		}

		return $pairs;
	}//end pairsForSchema()

	/**
	 * Whether a candidate register id passes the optional register id filter.
	 *
	 * @param int|string $candidateRegisterId A register id that holds the schema.
	 * @param int|null   $registerId          Optional register id filter.
	 *
	 * @return bool True when no filter is given or the ids match as integers.
	 */
	private static function isRegisterInScope(int|string $candidateRegisterId, ?int $registerId): bool {
		return $registerId === null || (int)$candidateRegisterId === $registerId;
	}//end isRegisterInScope()

	/**
	 * Load a register unfiltered by RBAC or tenancy, or null when it cannot be loaded.
	 *
	 * @param int|string $registerId The register id.
	 *
	 * @return Register|null The register, or null.
	 */
	private function findRegisterForReindex(int|string $registerId): ?Register {
		try {
			return $this->catalog->registers()->find(
				id: $registerId,
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $e) {
			return null;
		}
	}//end findRegisterForReindex()
}//end class
