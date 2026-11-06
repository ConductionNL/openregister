<?php

/**
 * What the object search and the objects table say about organisation types.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller\Support
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec exclude Debug scaffolding behind the type filtering diagnostic route; not a product contract.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller\Support;

use OCA\OpenRegister\Contract\RegisterSlugResolverInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCP\DB\IResult;
use OCP\IDBConnection;
use Psr\Container\ContainerInterface;

/**
 * Compares the filtered object search with a direct read of the objects table.
 *
 * @spec exclude Debug scaffolding behind the type filtering diagnostic route; not a product contract.
 */
final class OrganisationTypeDiagnostic
{

	/**
	 * The result keys and the types each one filters on.
	 *
	 * @var array<string, list<string>>
	 */
	private const SEARCHES = [
		'all_organizations' => [],
		'type_samenwerking' => ['samenwerking'],
		'type_community' => ['community'],
		'type_both' => ['samenwerking', 'community'],
	];

	/**
	 * Set up the diagnostic.
	 *
	 * @param ContainerInterface $container The container the services are resolved from.
	 *
	 * @spec exclude Debug scaffolding behind the type filtering diagnostic route; not a product contract.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
	) {
	}//end __construct()

	/**
	 * Run the searches and the direct read.
	 *
	 * The register slug is RESOLVED, not written: stackiq's repair step renames
	 * it from `voorzieningen` to `stackiq` per instance, and reading with the
	 * name this instance does not carry returns an empty set that would read
	 * as "no organisations".
	 *
	 * @return array<string, array{count: int<0, max>, organizations: list<array<string, mixed>>}>|null
	 *     The results, or null when this instance has no stackiq register.
	 *
	 * @spec exclude Debug scaffolding behind the type filtering diagnostic route; not a product contract.
	 */
	public function report(): ?array {
		$objects = $this->container->get(ObjectOperationsInterface::class);
		$register = $this->container->get(RegisterSlugResolverInterface::class)->slugOrNull(canonical: 'stackiq');
		if ($register === null) {
			return null;
		}

		$objects->setRegister($register);
		$objects->setSchema('organisation');

		$results = [];
		foreach (self::SEARCHES as $key => $types) {
			$results[$key] = $this->organisationsOfType(objects: $objects, types: $types);
		}

		$results['direct_database_query'] = $this->organisationsByName();
		return $results;
	}//end report()

	/**
	 * Search the organisation schema in the database, optionally filtered on type.
	 *
	 * The unfiltered search also returns each organisation's object data.
	 *
	 * @param ObjectOperationsInterface $objects The object service, set to the organisation schema.
	 * @param list<string>              $types   The types to filter on; empty for no filter.
	 *
	 * @return array{count: int<0, max>, organizations: list<array<string, mixed>>}
	 */
	private function organisationsOfType(ObjectOperationsInterface $objects, array $types): array {
		$query = ['_limit' => 10, '_page' => 1, '_source' => 'database'];
		if ($types !== []) {
			$query['type'] = $types;
		}

		$found = $objects->searchObjectsPaginated($query);
		$organisations = [];
		foreach ($found['results'] as $organisation) {
			$organisations[] = $this->summary(organisation: $organisation, withData: $types === []);
		}

		return ['count' => count($found['results']), 'organizations' => $organisations];
	}//end organisationsOfType()

	/**
	 * One organisation as the search reports it.
	 *
	 * @param mixed $organisation The object entity found.
	 * @param bool  $withData     Whether the object data is included.
	 *
	 * @return array<string, mixed>
	 */
	private function summary(mixed $organisation, bool $withData): array {
		$objectData = $organisation->getObject();
		$summary = [
			'id' => $organisation->getId(),
			'name' => $organisation->getName(),
			'type' => $objectData['type'] ?? 'NO TYPE',
		];
		if ($withData === true) {
			$summary['object_data'] = $objectData;
		}

		return $summary;
	}//end summary()

	/**
	 * Read the objects named like Samenwerking or Community straight from the objects table.
	 *
	 * @return array{count: int<0, max>, organizations: list<array<string, mixed>>}
	 */
	private function organisationsByName(): array {
		$stmt = $this->nameQuery();
		$organisations = $this->rows(stmt: $stmt);
		$stmt->closeCursor();

		return ['count' => count($organisations), 'organizations' => $organisations];
	}//end organisationsByName()

	/**
	 * Run the query on the objects table.
	 *
	 * @return IResult The result to read.
	 */
	private function nameQuery(): IResult {
		$qb = $this->container->get(IDBConnection::class)->getQueryBuilder();
		$qb->select('o.id', 'o.name', 'o.object')
			->from('openregister_objects', 'o')
			->where($qb->expr()->like('o.name', $qb->createNamedParameter('%Samenwerking%')))
			->orWhere($qb->expr()->like('o.name', $qb->createNamedParameter('%Community%')));

		return $qb->executeQuery();
	}//end nameQuery()

	/**
	 * The organisations a result holds.
	 *
	 * NC's IResult does not expose Doctrine's fetchAllAssociative() on every
	 * supported server (absent on NC 32), and a fetch() that answers NULL never
	 * satisfies `!== false`, so the loop ends on anything that is not a row.
	 *
	 * @param IResult $stmt The result.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function rows(IResult $stmt): array {
		$organisations = [];
		$row = $stmt->fetch();
		while (is_array($row) === true) {
			$objectData = json_decode($row['object'], true);
			$organisations[] = [
				'id' => $row['id'],
				'name' => $row['name'],
				'type' => $objectData['type'] ?? 'NO TYPE',
				'object_json' => $row['object'],
			];
			$row = $stmt->fetch();
		}

		return $organisations;
	}//end rows()
}//end class
