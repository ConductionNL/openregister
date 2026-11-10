<?php

/**
 * OpenRegister Collective Team
 *
 * The owner of a collective an example set writes and the groups its team gains.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ExampleSet
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-write-collectives-pages
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ExampleSet;

/**
 * The account that owns and manages a collective's team, and the Nextcloud groups added to that team.
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-write-collectives-pages
 */
final class CollectiveTeam {

	/**
	 * The account that owns the collective and manages its team.
	 *
	 * @var string
	 */
	public readonly string $owner;

	/**
	 * The Nextcloud group ids added to the team.
	 *
	 * @var array<int, string>
	 */
	public readonly array $groups;

	/**
	 * Constructor.
	 *
	 * @param string             $owner  The account that owns the collective and manages its team.
	 * @param array<int, string> $groups The Nextcloud group ids added to the team.
	 */
	public function __construct(string $owner, array $groups) {
		$this->owner  = $owner;
		$this->groups = $groups;
	}//end __construct()
}//end class
