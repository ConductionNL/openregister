<?php

/**
 * SearchScopesCapability — advertise the search scopes available to the
 * current user through Nextcloud's `/ocs/v2.php/cloud/capabilities` endpoint.
 *
 * A consuming app renders its scope chips from this block, not from a copy of
 * its own (content-search-index D-2).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Capabilities
 * @package  OCA\OpenRegister\Capabilities
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/content-search-index/specs/unified-search-provider/spec.md#requirement-the-provider-accepts-scopes-and-advertises-them
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Capabilities;

use OCA\OpenRegister\Search\SearchScopeCatalog;
use OCA\OpenRegister\Search\SearchScopes;
use OCA\OpenRegister\Service\Object\PermissionCheckInterface;
use OCP\Capabilities\ICapability;
use OCP\Capabilities\IInitialStateExcludedCapability;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * OCS capability listing the scopes the unified search provider accepts for this user.
 *
 * 🔑 THE SCOPES ARE THE USER'S, NOT THE INSTANCE'S. A schema the reader may not
 * read is not offered, and neither is a register or app whose only searchable
 * schemas are such schemas: a chip for it would answer an empty page, and its
 * name alone tells the reader the data exists. A permission check that throws
 * keeps the schema out (fail closed). The search itself still filters every
 * row; this list only decides which chips to draw.
 *
 * 🔑 `files` IS NOT OFFERED YET. The provider parses it, but no hit carries a
 * `kind` until file hits ship (content-search-index task 1.1), so the chip
 * would narrow nothing. It joins the list together with the hits it keeps.
 *
 * Excluded from the initial state Nextcloud renders into every page: the list
 * costs a permission check per searchable schema, which only a client that
 * draws search chips should pay for.
 *
 * @spec openspec/changes/content-search-index/specs/unified-search-provider/spec.md#requirement-the-provider-accepts-scopes-and-advertises-them
 */
class SearchScopesCapability implements ICapability, IInitialStateExcludedCapability {
	/**
	 * Contract version of the `openregister.search` capability shape.
	 *
	 * @var int
	 */
	public const CONTRACT_VERSION = 1;

	/**
	 * The unified search provider the scopes are for.
	 *
	 * @var string
	 */
	public const PROVIDER_ID = 'openregister_objects';

	/**
	 * The provider filter the scopes travel in, comma separated.
	 *
	 * @var string
	 */
	public const FILTER = 'scopes';

	/**
	 * Constructor.
	 *
	 * @param SearchScopeCatalog       $scopes      The searchable schemas described the way a scope names them.
	 * @param PermissionCheckInterface $permissions Whether the current user may read a schema.
	 * @param IUserSession             $userSession The current user; nobody signed in is offered nothing.
	 * @param LoggerInterface          $logger      Where a permission check that fails is reported.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly SearchScopeCatalog $scopes,
		private readonly PermissionCheckInterface $permissions,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Expose the scopes the current user may search in.
	 *
	 * @inheritDoc
	 *
	 * @return array{openregister: array{search: array{
	 *     contractVersion: int, provider: string, filter: string, scopes: array<int, array<string, string>>
	 * }}}
	 *
	 * @spec openspec/changes/content-search-index/specs/unified-search-provider/spec.md#requirement-the-provider-accepts-scopes-and-advertises-them
	 */
	public function getCapabilities(): array {
		return [
			'openregister' => [
				'search' => [
					'contractVersion' => self::CONTRACT_VERSION,
					'provider' => self::PROVIDER_ID,
					'filter' => self::FILTER,
					'scopes' => $this->availableScopes(),
				],
			],
		];
	}//end getCapabilities()

	/**
	 * The scopes of the schemas the current user may read: apps, then registers, then schemas.
	 *
	 * Each scope appears once, even when several schemas lead to it. A schema
	 * slug used in two registers is one `schema:` scope, because the provider
	 * reads it as one.
	 *
	 * @return array<int, array<string, string>> The scopes, each with its `id`, `type`, `value` and `label`.
	 */
	private function availableScopes(): array {
		$userId = $this->userSession->getUser()?->getUID();
		if ($userId === null) {
			return [];
		}

		$found = ['app' => [], 'register' => [], 'schema' => []];
		foreach ($this->scopes->describe() as $row) {
			if ($this->mayRead(row: $row, userId: $userId) === false) {
				continue;
			}

			foreach ($row['apps'] as $app) {
				$found['app'][$app] = ($found['app'][$app] ?? self::scope(type: 'app', value: $app, label: $app));
			}

			$register = strtolower($row['register']);
			if ($register !== '') {
				$found['register'][$register] = ($found['register'][$register] ?? self::scope(type: 'register', value: $register, label: $row['registerTitle']));
			}

			$schema = strtolower($row['slug']);
			if ($schema !== '') {
				$found['schema'][$schema] = ($found['schema'][$schema] ?? self::scope(type: 'schema', value: $schema, label: $row['title']));
			}
		}//end foreach

		$offered = array_merge(
			self::sorted(scopes: $found['app']),
			self::sorted(scopes: $found['register']),
			self::sorted(scopes: $found['schema'])
		);

		// `files` keeps only file hits, so it is offered once there is something readable to keep them from.
		if ($offered !== []) {
			$offered[] = ['id' => SearchScopes::FILES, 'type' => SearchScopes::FILES, 'value' => SearchScopes::FILES, 'label' => SearchScopes::FILES];
		}

		return $offered;
	}//end availableScopes()

	/**
	 * Whether the current user may read a schema; a check that throws answers no.
	 *
	 * @param array{id: int, schema: \OCA\OpenRegister\Db\Schema} $row    One described schema.
	 * @param string                                             $userId The current user.
	 *
	 * @return bool True when the schema may be offered.
	 */
	private function mayRead(array $row, string $userId): bool {
		try {
			return $this->permissions->hasPermission(schema: $row['schema'], action: 'read', userId: $userId);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'[SearchScopesCapability] Read check failed for schema {schema}; its scopes are not offered: {error}',
				['schema' => $row['id'], 'error' => $e->getMessage()]
			);

			return false;
		}
	}//end mayRead()

	/**
	 * One advertised scope.
	 *
	 * @param string $type  `app`, `register` or `schema`.
	 * @param string $value The app id or slug, in lower case as the provider reads it.
	 * @param string $label What a chip shows; the value when nothing better is known.
	 *
	 * @return array<string, string> The scope.
	 */
	private static function scope(string $type, string $value, string $label): array {
		if ($label === '') {
			$label = $value;
		}

		return ['id' => $type.':'.$value, 'type' => $type, 'value' => $value, 'label' => $label];
	}//end scope()

	/**
	 * The scopes of one type, ordered by value so the list does not move between calls.
	 *
	 * @param array<string, array<string, string>> $scopes The scopes keyed by value.
	 *
	 * @return array<int, array<string, string>> The scopes.
	 */
	private static function sorted(array $scopes): array {
		ksort($scopes, SORT_STRING);

		return array_values($scopes);
	}//end sorted()
}//end class
