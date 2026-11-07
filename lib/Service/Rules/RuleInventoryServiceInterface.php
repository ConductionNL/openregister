<?php

/**
 * RuleInventoryServiceInterface: what callers outside the Service component ask of RuleInventoryService.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rules
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rules;

use DateTime;
use OCA\OpenRegister\Db\Schema;

/**
 * The rule inventory of a schema, as the rules controller and the apply-rule
 * bulk action use it. Only the methods and the constant those callers use.
 */
interface RuleInventoryServiceInterface {

	/**
	 * How long a rule may go unevaluated before the inventory says so.
	 *
	 * Ninety days is the window the change names, and it is a default rather
	 * than a constant of nature: the caller may ask for another.
	 *
	 * @var integer
	 */
	public const DEFAULT_IDLE_DAYS = 90;

	/**
	 * The rules a schema declares, with how recently each was evaluated.
	 *
	 * @param Schema               $schema   The schema to read.
	 * @param integer              $idleDays How long a rule may go unevaluated before it is flagged.
	 * @param DateTime|null        $now      The moment to measure the idle window from.
	 * @param array<string, mixed> $scope    Property => value: only the rules with that scope, plus those without one.
	 *
	 * @return array<int, array<string, mixed>> The inventory rows.
	 */
	public function inventory(
		Schema $schema,
		int $idleDays = self::DEFAULT_IDLE_DAYS,
		?DateTime $now = null,
		array $scope = []
	): array;

	/**
	 * One rule of a schema by its derived id.
	 *
	 * @param Schema $schema The schema to read.
	 * @param string $ruleId The derived rule id.
	 *
	 * @return RuleDescriptor|null The descriptor, or null when the schema declares no such rule.
	 */
	public function find(Schema $schema, string $ruleId): ?RuleDescriptor;
}//end interface
