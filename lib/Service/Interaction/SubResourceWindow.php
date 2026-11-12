<?php

/**
 * OpenRegister Sub-Resource Window
 *
 * One sub-resource of an object, with the moment its reader last saw it: what
 * an unread count is counted over.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Interaction
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/specs/object-read-state/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Interaction;

use DateTime;

/**
 * A sub-resource descriptor and the moment it was last seen.
 *
 * @spec openspec/specs/object-read-state/spec.md#requirement-unread-is-a-filter-and-a-badge-resolved-in-the-query-req-ors-002
 */
final class SubResourceWindow {

	/**
	 * The sub-resource descriptor, as SubstantiveChangeEvaluator::subResources() gives it.
	 *
	 * @var array<string, string>
	 */
	public readonly array $descriptor;

	/**
	 * The seen moment, or null when never seen.
	 *
	 * @var DateTime|null
	 */
	public readonly ?DateTime $since;

	/**
	 * Constructor.
	 *
	 * @param array<string, string> $descriptor The sub-resource descriptor.
	 * @param DateTime|null         $since      The seen moment, or null when never seen.
	 */
	public function __construct(array $descriptor, ?DateTime $since) {
		$this->descriptor = $descriptor;
		$this->since      = $since;
	}//end __construct()
}//end class
