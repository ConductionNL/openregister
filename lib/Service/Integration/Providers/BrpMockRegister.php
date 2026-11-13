<?php

/**
 * BrpMockRegister — the persons of the seeded mock BRP register, in the
 * HaalCentraal Personen shape.
 *
 * The mock BRP register (`lib/Settings/brp_register.json`, register `brp`,
 * schema `ingeschreven-persoon`) holds flat person objects. A canned
 * `mockResponse` on a source answers one fixed body whatever is asked, which
 * cannot serve a family lookup: it asks the resident first, then the relatives
 * the resident names. This reader answers each asked BSN from the register and
 * shapes the object the way HaalCentraal answers, so the provider's own
 * filters, references and minimisation run unchanged on it.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Integration\Providers
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/brp-family-members-lookup/tasks.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Integration\Providers;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use Psr\Container\ContainerInterface;

/**
 * Reads persons by BSN from the mock BRP register (design D4).
 *
 * @spec openspec/changes/brp-family-members-lookup/specs/integration-person-lookup/spec.md
 */
class BrpMockRegister {

	/**
	 * The mock register's slug.
	 */
	public const REGISTER = 'brp';

	/**
	 * The mock person schema's slug.
	 */
	public const SCHEMA = 'ingeschreven-persoon';

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Resolves the object service on first use, so
	 *                                      constructing the provider never builds it.
	 */
	public function __construct(
		private ContainerInterface $container,
	) {
	}//end __construct()

	/**
	 * The persons with these BSNs, in the HaalCentraal shape; an unknown BSN is left out.
	 *
	 * @param array<int,string> $bsns The BSNs asked.
	 *
	 * @return array<int,array<string,mixed>>
	 *
	 * @spec openspec/changes/brp-family-members-lookup/specs/integration-person-lookup/spec.md
	 */
	public function persons(array $bsns): array {
		$persons = [];
		foreach (array_values(array_unique(array_map('strval', $bsns))) as $bsn) {
			$object = $this->objectFor(bsn: $bsn);
			if ($object !== null) {
				$persons[] = self::haalCentraalShape(person: $object);
			}
		}

		return $persons;
	}//end persons()

	/**
	 * A flat mock person in the HaalCentraal Personen shape.
	 *
	 * @param array<string,mixed> $person The mock object's data.
	 *
	 * @return array<string,mixed>
	 *
	 * @spec openspec/changes/brp-family-members-lookup/specs/integration-person-lookup/spec.md
	 */
	public static function haalCentraalShape(array $person): array {
		$shaped = [
			'burgerservicenummer' => (string)($person['burgerservicenummer'] ?? ''),
			'naam' => array_filter(
				[
					'voornamen' => ($person['voornamen'] ?? null),
					'voorvoegsel' => ($person['voorvoegsel'] ?? null),
					'geslachtsnaam' => ($person['geslachtsnaam'] ?? null),
				],
				static fn ($value): bool => is_string($value) === true && $value !== ''
			),
			'verblijfplaats' => ($person['verblijfplaats'] ?? []),
			'partners' => array_values(array_filter((array)($person['partners'] ?? []), 'is_array')),
			'kinderen' => array_values(array_filter((array)($person['kinderen'] ?? []), 'is_array')),
		];

		$birth = ($person['geboortedatum'] ?? null);
		if (is_string($birth) === true && $birth !== '') {
			$shaped['geboorte'] = ['datum' => ['datum' => $birth]];
		}

		if (empty($person['overlijden']) === false) {
			$shaped['overlijden'] = $person['overlijden'];
		}

		return $shaped;
	}//end haalCentraalShape()

	/**
	 * The mock object of one BSN, or null.
	 *
	 * @param string $bsn The BSN.
	 *
	 * @return array<string,mixed>|null
	 */
	private function objectFor(string $bsn): ?array {
		if (trim($bsn) === '') {
			return null;
		}

		$found = $this->container->get(ObjectService::class)->findAll(
			config: [
				'filters' => ['register' => self::REGISTER, 'schema' => self::SCHEMA, 'burgerservicenummer' => $bsn],
				'limit' => 1,
			],
			_rbac: false,
			_multitenancy: false
		);

		foreach (($found['results'] ?? $found) as $entity) {
			if ($entity instanceof ObjectEntity === true) {
				$data = $entity->getObject();
				if (is_array($data) === true && (string)($data['burgerservicenummer'] ?? '') === $bsn) {
					return $data;
				}
			}
		}

		return null;
	}//end objectFor()
}//end class
