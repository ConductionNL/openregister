<?php

/**
 * OpenRegister team resource provider.
 *
 * Puts the objects a team owns on that team's own Nextcloud page. Only schemas
 * declaring `x-openregister-team-resources` take part; an object belongs to the
 * teams its declared owning-team property names. Listing goes through the
 * object search with RBAC and multitenancy on, so a reader sees only the
 * objects they may read, one page at a time.
 *
 * @category Reference
 * @package  OCA\OpenRegister\Reference
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/platform-team-resource-provider/specs/platform-team-resources/spec.md#requirement-a-teams-page-lists-the-objects-that-team-owns-req-ptr-001
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Reference;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\SchemaCatalogInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\Search\ObjectSearchResultFormatterInterface;
use OCP\IL10N;
use OCP\Teams\ITeamResourceProvider;
use OCP\Teams\TeamResource;
use Psr\Log\LoggerInterface;

/**
 * Lists, per team, the objects of opted-in schemas that the team owns.
 *
 * @spec openspec/changes/platform-team-resource-provider/specs/platform-team-resources/spec.md#requirement-a-teams-page-lists-the-objects-that-team-owns-req-ptr-001
 */
final class ObjectTeamResourceProvider implements ITeamResourceProvider {

	/**
	 * Objects on one page.
	 *
	 * @var integer
	 */
	public const PAGE_LIMIT = 25;

	/**
	 * Constructor.
	 *
	 * @param ObjectOperationsInterface            $objects   The object search and read, access applied.
	 * @param SchemaCatalogInterface               $catalog   The schemas, for their declarations.
	 * @param ObjectSearchResultFormatterInterface $formatter Name, icon and link of an object.
	 * @param IL10N                                $l10n      Translations.
	 * @param LoggerInterface                      $logger    Logger.
	 */
	public function __construct(
		private readonly ObjectOperationsInterface $objects,
		private readonly SchemaCatalogInterface $catalog,
		private readonly ObjectSearchResultFormatterInterface $formatter,
		private readonly IL10N $l10n,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The provider id.
	 *
	 * @return string The id.
	 *
	 * @spec openspec/changes/platform-team-resource-provider/specs/platform-team-resources/spec.md#requirement-a-teams-page-lists-the-objects-that-team-owns-req-ptr-001
	 */
	public function getId(): string {
		return 'openregister';
	}//end getId()

	/**
	 * The provider name shown on the team page.
	 *
	 * @return string The name.
	 *
	 * @spec openspec/changes/platform-team-resource-provider/specs/platform-team-resources/spec.md#requirement-a-teams-page-lists-the-objects-that-team-owns-req-ptr-001
	 */
	public function getName(): string {
		return $this->l10n->t('Open Register');
	}//end getName()

	/**
	 * The provider icon, the app's own.
	 *
	 * @return string The SVG markup, or an empty string when it cannot be read.
	 *
	 * @spec openspec/changes/platform-team-resource-provider/specs/platform-team-resources/spec.md#requirement-a-teams-page-lists-the-objects-that-team-owns-req-ptr-001
	 */
	public function getIconSvg(): string {
		$svg = @file_get_contents(__DIR__ . '/../../img/app-dark.svg');
		if ($svg === false) {
			return '';
		}

		return $svg;
	}//end getIconSvg()

	/**
	 * The first page of objects the team owns.
	 *
	 * The team page asks without a cursor, so it gets one page; the rest is
	 * reached through {@see self::listPage()}.
	 *
	 * @param string $teamId The team.
	 *
	 * @return list<TeamResource> The objects on the first page.
	 *
	 * @spec openspec/changes/platform-team-resource-provider/specs/platform-team-resources/spec.md#requirement-a-teams-page-lists-the-objects-that-team-owns-req-ptr-001
	 */
	public function getSharedWith(string $teamId): array {
		return $this->listPage(teamId: $teamId)->resources;
	}//end getSharedWith()

	/**
	 * One page of the objects a team owns, with the cursor of the next page.
	 *
	 * Schemas are walked in id order and each is searched for objects whose
	 * declared owning-team property names the team. The cursor is the schema
	 * and the offset inside it where the next page starts; an unreadable
	 * cursor starts from the beginning.
	 *
	 * @param string      $teamId The team.
	 * @param string|null $cursor The cursor a previous page returned, or null for the first page.
	 * @param int         $limit  Objects per page, at most {@see self::PAGE_LIMIT}.
	 *
	 * @return TeamResourcePage The page.
	 *
	 * @spec openspec/changes/platform-team-resource-provider/specs/platform-team-resources/spec.md#requirement-a-teams-page-lists-the-objects-that-team-owns-req-ptr-001
	 */
	public function listPage(string $teamId, ?string $cursor=null, int $limit=self::PAGE_LIMIT): TeamResourcePage {
		$limit = max(1, min($limit, self::PAGE_LIMIT));
		if (trim($teamId) === '') {
			return new TeamResourcePage(resources: [], nextCursor: null);
		}

		[$fromSchema, $offset] = self::position(cursor: $cursor);
		$resources = [];
		$declared  = $this->declaredSchemas();
		$schemaIds = array_keys($declared);
		foreach ($schemaIds as $index => $schemaId) {
			if ($schemaId < $fromSchema) {
				continue;
			}

			if ($schemaId > $fromSchema) {
				$offset = 0;
			}

			$wanted = ($limit - count($resources));
			$found  = $this->search(schemaId: $schemaId, declaration: $declared[$schemaId], teamId: $teamId, limit: $wanted, offset: $offset);
			foreach ($found['rows'] as $row) {
				$resources[] = $this->resource(row: $row);
			}

			$reached = ($offset + count($found['rows']));
			if (count($resources) < $limit) {
				continue;
			}

			if ($reached < $found['total']) {
				return new TeamResourcePage(resources: $resources, nextCursor: $schemaId . ':' . $reached);
			}

			$next = ($schemaIds[($index + 1)] ?? null);
			if ($next === null) {
				return new TeamResourcePage(resources: $resources, nextCursor: null);
			}

			return new TeamResourcePage(resources: $resources, nextCursor: $next . ':0');
		}//end foreach

		return new TeamResourcePage(resources: $resources, nextCursor: null);
	}//end listPage()

	/**
	 * Whether an object belongs to a team, read from its owning-team property.
	 *
	 * @param string $teamId     The team.
	 * @param string $resourceId The object's uuid.
	 *
	 * @return bool True when the object's declared property names the team.
	 *
	 * @spec openspec/changes/platform-team-resource-provider/specs/platform-team-resources/spec.md#requirement-the-provider-answers-whether-one-object-belongs-to-a-team-req-ptr-002
	 */
	public function isSharedWithTeam(string $teamId, string $resourceId): bool {
		return in_array(trim($teamId), $this->getTeamsForResource(resourceId: $resourceId), true);
	}//end isSharedWithTeam()

	/**
	 * The teams an object belongs to, read from its owning-team property.
	 *
	 * The object is read with the caller's access: an object they may not read,
	 * or that does not exist, belongs to no team as far as they can tell. An
	 * object of a schema without the declaration belongs to none.
	 *
	 * @param string $resourceId The object's uuid.
	 *
	 * @return list<string> The team ids.
	 *
	 * @spec openspec/changes/platform-team-resource-provider/specs/platform-team-resources/spec.md#requirement-the-provider-answers-whether-one-object-belongs-to-a-team-req-ptr-002
	 */
	public function getTeamsForResource(string $resourceId): array {
		try {
			$object = $this->objects->find(id: $resourceId, _rbac: true, _multitenancy: true);
			if ($object === null || $object->getSchema() === null) {
				return [];
			}

			$declaration = TeamResourceDeclaration::of(schema: $this->catalog->schemas()->find(id: $object->getSchema()));
		} catch (\Throwable $e) {
			$this->logger->debug(
				'[ObjectTeamResourceProvider] Object {id} is not readable here, so it belongs to no team: {error}',
				['id' => $resourceId, 'error' => $e->getMessage()]
			);
			return [];
		}

		if ($declaration === null) {
			return [];
		}

		return $declaration->teamsOf(objectData: $object->getObject());
	}//end getTeamsForResource()

	/**
	 * The schemas declaring team-page appearance, by id in ascending order.
	 *
	 * @return array<int, TeamResourceDeclaration> The declarations by schema id.
	 */
	private function declaredSchemas(): array {
		try {
			$schemas = $this->catalog->schemas()->findAll();
		} catch (\Throwable $e) {
			$this->logger->error(
				'[ObjectTeamResourceProvider] Failed to read the schemas, so no team resources are listed: {error}',
				['error' => $e->getMessage(), 'exception' => $e]
			);
			return [];
		}

		$declared = [];
		foreach ($schemas as $schema) {
			$declaration = TeamResourceDeclaration::of(schema: $schema);
			if ($declaration !== null && $schema->getId() !== null) {
				$declared[(int)$schema->getId()] = $declaration;
			}
		}

		ksort($declared);
		return $declared;
	}//end declaredSchemas()

	/**
	 * One schema's objects owned by the team, access applied.
	 *
	 * @param int                     $schemaId    The schema.
	 * @param TeamResourceDeclaration $declaration Its declaration.
	 * @param string                  $teamId      The team.
	 * @param int                     $limit       How many to fetch.
	 * @param int                     $offset      Where to start.
	 *
	 * @return array{rows: list<mixed>, total: int} The rows and the schema's total for the team.
	 */
	private function search(int $schemaId, TeamResourceDeclaration $declaration, string $teamId, int $limit, int $offset): array {
		try {
			$page = $this->objects->searchObjectsPaginated(
				query: [
					'@self' => ['schema' => $schemaId],
					$declaration->teamProperty => $teamId,
					'_limit' => $limit,
					'_offset' => $offset,
				],
				_rbac: true,
				_multitenancy: true
			);
		} catch (\Throwable $e) {
			$this->logger->error(
				'[ObjectTeamResourceProvider] Listing schema {schema} for a team page failed; the other schemas still answer: {error}',
				['schema' => $schemaId, 'error' => $e->getMessage(), 'exception' => $e]
			);
			return ['rows' => [], 'total' => 0];
		}

		return [
			'rows' => array_values(array_slice(($page['results'] ?? []), 0, $limit)),
			'total' => (int)($page['total'] ?? 0),
		];
	}//end search()

	/**
	 * A team resource for one search row: its name, icon and link.
	 *
	 * @param mixed $row An object as the search returns it.
	 *
	 * @return TeamResource The resource.
	 */
	private function resource(mixed $row): TeamResource {
		$entry = $this->formatter->format(result: $row, term: null)->jsonSerialize();
		$icon  = (string)($entry['thumbnailUrl'] ?? '');
		if ($icon === '') {
			$icon = null;
		}

		return new TeamResource(
			teamResourceProvider: $this,
			resourceId: self::uuidOf(row: $row),
			label: (string)($entry['title'] ?? ''),
			url: (string)($entry['resourceUrl'] ?? ''),
			iconURL: $icon
		);
	}//end resource()

	/**
	 * The uuid of a search row.
	 *
	 * @param mixed $row An object as the search returns it.
	 *
	 * @return string The uuid, or an empty string when it carries none.
	 */
	private static function uuidOf(mixed $row): string {
		if ($row instanceof ObjectEntity) {
			return (string)($row->getUuid() ?? '');
		}

		$self = (array)($row['@self'] ?? []);
		return (string)($self['id'] ?? $row['id'] ?? $self['uuid'] ?? '');
	}//end uuidOf()

	/**
	 * Where a cursor says the next page starts.
	 *
	 * @param string|null $cursor The cursor.
	 *
	 * @return array{0: int, 1: int} The schema id and the offset inside it.
	 */
	private static function position(?string $cursor): array {
		if ($cursor === null || preg_match('/\A(\d+):(\d+)\z/', $cursor, $match) !== 1) {
			return [0, 0];
		}

		return [(int)$match[1], (int)$match[2]];
	}//end position()
}//end class
