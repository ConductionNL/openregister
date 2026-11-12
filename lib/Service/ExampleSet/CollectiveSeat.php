<?php

/**
 * OpenRegister Collective Seat
 *
 * A collective as one account reads and writes it, as the example-set Collectives store reaches its pages.
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
 * The id of a collective and the account its pages are read and written as.
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-write-collectives-pages
 */
final class CollectiveSeat {

	/**
	 * The collective id.
	 *
	 * @var integer
	 */
	public readonly int $collective;

	/**
	 * The account that reads and writes the collective's pages.
	 *
	 * @var string
	 */
	public readonly string $owner;

	/**
	 * Constructor.
	 *
	 * @param integer $collective The collective id.
	 * @param string  $owner      The account that reads and writes the collective's pages.
	 */
	public function __construct(int $collective, string $owner) {
		$this->collective = $collective;
		$this->owner      = $owner;
	}//end __construct()
}//end class
