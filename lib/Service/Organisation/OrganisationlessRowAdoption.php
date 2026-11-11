<?php

/**
 * OrganisationlessRowAdoption: gives the stored rows that carry no organisation one.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Organisation
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/saas-multi-tenant/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Organisation;

use OCA\OpenRegister\Command\MagicTableTarget;
use OCP\IAppConfig;
use OCP\IDBConnection;

/**
 * Gives each magic-table row without an organisation the organisation that owns its table.
 *
 * The organisation is the register's, else the schema's, else the instance's
 * default organisation. A table none of the three names is left alone and
 * reported. Only rows whose `_organisation` is empty are written, so a second
 * run changes nothing.
 *
 * @spec openspec/specs/saas-multi-tenant/spec.md
 */
class OrganisationlessRowAdoption {

	/**
	 * Build the adoption.
	 *
	 * @param IDBConnection $db        The connection.
	 * @param IAppConfig    $appConfig Holds the default organisation.
	 */
	public function __construct(
		private readonly IDBConnection $db,
		private readonly IAppConfig $appConfig,
	) {
	}//end __construct()

	/**
	 * The organisation a table's org-less rows are given, or null when none is known.
	 *
	 * @param MagicTableTarget $target The table.
	 *
	 * @return string|null The register's, the schema's or the default organisation.
	 *
	 * @spec openspec/specs/saas-multi-tenant/spec.md
	 */
	public function organisationFor(MagicTableTarget $target): ?string {
		foreach ([$target->register->getOrganisation(), $target->schema->getOrganisation(), $this->defaultOrganisation()] as $candidate) {
			if (is_string($candidate) === true && trim($candidate) !== '') {
				return trim($candidate);
			}
		}

		return null;
	}//end organisationFor()

	/**
	 * How many rows of a table carry no organisation.
	 *
	 * @param string $tableName The magic table.
	 *
	 * @return int The count.
	 *
	 * @spec openspec/specs/saas-multi-tenant/spec.md
	 */
	public function count(string $tableName): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'cnt'))
			->from($tableName)
			->where(
				$qb->expr()->orX(
					$qb->expr()->isNull('_organisation'),
					$qb->expr()->eq('_organisation', $qb->createNamedParameter(''))
				)
			);

		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();

		return (int)($row['cnt'] ?? 0);
	}//end count()

	/**
	 * Give every row of a table that carries no organisation the one named.
	 *
	 * @param string $tableName    The magic table.
	 * @param string $organisation The organisation uuid.
	 *
	 * @return int The rows written.
	 *
	 * @spec openspec/specs/saas-multi-tenant/spec.md
	 */
	public function adopt(string $tableName, string $organisation): int {
		$qb = $this->db->getQueryBuilder();
		$qb->update($tableName)
			->set('_organisation', $qb->createNamedParameter($organisation))
			->where(
				$qb->expr()->orX(
					$qb->expr()->isNull('_organisation'),
					$qb->expr()->eq('_organisation', $qb->createNamedParameter(''))
				)
			);

		return (int)$qb->executeStatement();
	}//end adopt()

	/**
	 * The instance's default organisation, from either of its two config spellings.
	 *
	 * @return string|null The uuid, or null when none is set.
	 *
	 * @spec openspec/specs/saas-multi-tenant/spec.md
	 */
	private function defaultOrganisation(): ?string {
		$direct = $this->appConfig->getValueString('openregister', 'defaultOrganisation', '');
		if ($direct !== '') {
			return $direct;
		}

		$nested = json_decode($this->appConfig->getValueString('openregister', 'organisation', ''), true);
		$legacy = null;
		if (is_array($nested) === true) {
			$legacy = ($nested['default_organisation'] ?? null);
		}

		if (is_string($legacy) === true && $legacy !== '') {
			return $legacy;
		}

		return null;
	}//end defaultOrganisation()
}//end class
