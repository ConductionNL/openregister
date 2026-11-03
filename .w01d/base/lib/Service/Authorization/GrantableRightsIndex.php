<?php

/**
 * OpenRegister GrantableRightsIndex
 *
 * The menu of rights that EXIST TO GIVE: every `(register, schema, action)`
 * that may be offered to an agent, across every register and schema.
 *
 * Hermiq asks "what could this agent be granted?" when a person opens a
 * permission screen. Answering that from scratch means walking every schema in
 * every register — measured on the dev instance at 406 registers and 1,000+
 * schemas — which is not a per-request query. So it is built once and cached.
 *
 * 🔴 It lists what may be OFFERED, never what is HELD. An entry here is a right
 * that could be granted, not one anybody has. Whether a specific agent holds it
 * stays resolved in Hermiq against that agent's own grants — RBAC groups are per
 * user and cannot separate two agents owned by one person.
 *
 * ⚠️ Invalidated on the WRITE, never on a timer. A stale permission index is a
 * permission bug whose failure is silent: a right that was revoked still reads
 * as grantable, and nothing about the answer looks wrong. An EMPTY index is
 * always preferable to a stale one — it rebuilds on the next read, and the
 * worst case is a slow request rather than a wrong permission.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Authorization
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/declared-actions-and-mcp-scope/specs/declared-actions/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Authorization;

use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCP\ICacheFactory;
use Psr\Log\LoggerInterface;

/**
 * Builds and caches the index of rights that may be offered to agents.
 *
 * @SuppressWarnings(PHPMD.StaticAccess) PermissionHandler::mcpOfferedActions() is deliberately static and pure so every
 *      reader of an authorization block agrees on what the mcp scope means. An injected copy could diverge from the
 *      evaluator, and an index that disagrees with the enforcer about which rights exist is worse than no index.
 */
class GrantableRightsIndex {

	/**
	 * Distributed cache namespace.
	 *
	 * @var string
	 */
	private const CACHE_PREFIX = 'openregister_grantable_rights';

	/**
	 * The single cache key holding the whole index.
	 *
	 * One key, not one per schema: the index is always read whole, and a
	 * per-schema key set cannot be invalidated atomically — a partial
	 * invalidation is precisely the stale-permission failure this guards.
	 *
	 * @var string
	 */
	private const CACHE_KEY = 'index';

	/**
	 * The right is offered by an `mcp` entry in the schema's authorization block.
	 *
	 * @var string
	 */
	public const SOURCE_AUTHORIZATION = 'authorization';

	/**
	 * The right is offered by the schema's `x-openregister-mcp` dialect, which
	 * emits live derived tools.
	 *
	 * @var string
	 */
	public const SOURCE_MCP_DIALECT = 'x-openregister-mcp';

	/**
	 * Per-request memo, so repeated reads in one request do not re-hit the
	 * distributed cache. Deliberately NOT a fallback for a missing cache — it
	 * is dropped whenever the index is invalidated within the same request.
	 *
	 * @var array<int, array<string, mixed>>|null
	 */
	private ?array $memo = null;

	/**
	 * Constructor.
	 *
	 * @param SchemaMapper    $schemaMapper   Source of the schemas to walk.
	 * @param RegisterMapper  $registerMapper Source of register → schema membership.
	 * @param ICacheFactory   $cacheFactory   Distributed cache for the built index.
	 * @param LoggerInterface $logger         Diagnostics.
	 */
	public function __construct(
		private readonly SchemaMapper $schemaMapper,
		private readonly RegisterMapper $registerMapper,
		private readonly ICacheFactory $cacheFactory,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The rights that may be offered, as a list of
	 * `{register, schema, schemaId, action, source}` entries.
	 *
	 * Rebuilt on a miss. A rebuild failure returns an EMPTY list rather than a
	 * partial one and caches nothing: a half-built permission menu is a wrong
	 * answer that looks like a right one, and the next read gets to try again.
	 *
	 * @return array<int, array<string, mixed>> The grantable rights.
	 *
	 * @spec openspec/changes/declared-actions-and-mcp-scope/specs/declared-actions/spec.md
	 */
	public function getIndex(): array {
		if ($this->memo !== null) {
			return $this->memo;
		}

		$cache = $this->cacheFactory->createDistributed(self::CACHE_PREFIX);
		$cached = $cache->get(self::CACHE_KEY);
		if (is_array($cached) === true) {
			$this->memo = $cached;
			return $cached;
		}

		try {
			$index = $this->build();
		} catch (\Throwable $e) {
			$this->logger->error(
				message: '[GrantableRightsIndex] build failed; serving an empty index rather than a partial one',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'error' => $e->getMessage(),
				]
			);

			return [];
		}

		$cache->set(self::CACHE_KEY, $index);
		$this->memo = $index;

		return $index;
	}//end getIndex()

	/**
	 * Drop the index.
	 *
	 * Called from the schema create/update/delete listener. There is no TTL to
	 * fall back on by design: a right removed from a schema must stop being
	 * offered at the moment of the write, not at the end of some window.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/declared-actions-and-mcp-scope/specs/declared-actions/spec.md
	 */
	public function invalidate(): void {
		$this->memo = null;
		$this->cacheFactory->createDistributed(self::CACHE_PREFIX)->remove(self::CACHE_KEY);
	}//end invalidate()

	/**
	 * Walk every schema once and collect what it offers.
	 *
	 * @return array<int, array<string, mixed>> The built index.
	 */
	private function build(): array {
		$registersBySchemaId = $this->registersBySchemaId();

		$entries = [];
		foreach ($this->schemaMapper->findAll(_rbac: false) as $schema) {
			$offered = $this->offeredActions(schema: $schema);
			if ($offered === []) {
				continue;
			}

			$schemaId = $schema->getId();
			// A schema can sit in several registers, or in none. Emitting one
			// entry per register is what makes the answer usable: "may be
			// offered" is a question about a register's data, and collapsing
			// them would hide which register a right actually reaches.
			$registers = ($registersBySchemaId[$schemaId] ?? [['id' => null, 'application' => null]]);

			foreach ($registers as $register) {
				$app = $this->owningApp(schema: $schema, registerApplication: $register['application']);

				foreach ($offered as $action => $source) {
					$entries[] = [
						'app' => $app,
						'register' => $register['id'],
						'schema' => $schema->getSlug(),
						'schemaId' => $schemaId,
						'action' => $action,
						'source' => $source,
					];
				}
			}
		}//end foreach

		return $entries;
	}//end build()

	/**
	 * The actions one schema offers, mapped to where the offer came from.
	 *
	 * Both sources count. The `mcp` scope in an authorization block declares an
	 * offer; the `x-openregister-mcp` dialect declares one AND emits the live
	 * tool. An index that knew only one of them would present itself as the
	 * complete menu while missing half of it.
	 *
	 * @param Schema $schema The schema to read.
	 *
	 * @return array<string, string> Action name → source.
	 *
	 * @spec openspec/specs/declared-actions/spec.md
	 */
	private function offeredActions(Schema $schema): array {
		$offered = [];

		foreach (PermissionHandler::mcpOfferedActions(authorization: $schema->getAuthorization()) as $action) {
			$offered[$action] = self::SOURCE_AUTHORIZATION;
		}

		$configuration = ($schema->getConfiguration() ?? []);
		$annotation = ($configuration['x-openregister-mcp'] ?? null);
		if (is_array($annotation) === false || ($annotation['enabled'] ?? false) !== true) {
			return $offered;
		}

		$tools = ($annotation['tools'] ?? []);
		if (is_array($tools) === false) {
			return $offered;
		}

		foreach (array_keys($tools) as $verb) {
			if (is_string($verb) === false || $verb === '') {
				continue;
			}

			$offered[$verb] = self::SOURCE_MCP_DIALECT;
		}

		return $offered;
	}//end offeredActions()

	/**
	 * The app that owns a schema, or null when nothing does.
	 *
	 * Mirrors `Application::resolveOwningAppId()`: the schema's OWN
	 * `application` wins, and the owning register's is the fallback. Measured on
	 * the dev instance, both halves carry real weight — 1,085 of 2,000 schemas
	 * name their own app and the remaining 915 inherit one — so an
	 * implementation that knew only the schema field would lose almost half the
	 * fleet, and one that knew only the register field would mis-attribute the
	 * rest.
	 *
	 * ⚠️ Null is a real answer, not a lookup failure. A schema owned by no app
	 * cannot emit MCP tools at all, so a right on it belongs to no cluster and
	 * inventing one would put it under a heading that cannot serve it.
	 *
	 * @param Schema      $schema             The schema being indexed.
	 * @param string|null $registerApplication The owning register's application, when it has one.
	 *
	 * @return string|null The owning app id, or null.
	 *
	 * @spec openspec/specs/declared-actions/spec.md
	 */
	private function owningApp(Schema $schema, ?string $registerApplication): ?string {
		$own = $schema->getApplication();
		if (is_string($own) === true && $own !== '') {
			return $own;
		}

		if (is_string($registerApplication) === true && $registerApplication !== '') {
			return $registerApplication;
		}

		return null;
	}//end owningApp()

	/**
	 * Map schema id → the registers that contain it, with each register's
	 * owning application.
	 *
	 * Built in one pass over the registers so the schema walk stays linear
	 * rather than issuing a membership query per schema — and it carries the
	 * application along, so resolving a schema's owning app costs no extra
	 * lookup either.
	 *
	 * @return array<int|string, array<int, array{id: mixed, application: string|null}>> Schema id → registers.
	 *
	 * @spec openspec/specs/declared-actions/spec.md
	 */
	private function registersBySchemaId(): array {
		$map = [];

		foreach ($this->registerMapper->findAll() as $register) {
			$entry = [
				'id' => $register->getId(),
				'application' => $register->getApplication(),
			];

			// `Register::setSchemas()` filters its input down to ints and
			// strings, so every entry here is already a bare id. A defensive
			// branch for hydrated schema arrays was written and then removed:
			// a test proved it unreachable, and unreachable defence reads like
			// a handled case that was never handled.
			foreach ($register->getSchemas() as $schemaId) {
				$map[$schemaId][] = $entry;
			}
		}

		return $map;
	}//end registersBySchemaId()
}//end class
