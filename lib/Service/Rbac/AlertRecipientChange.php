<?php

/**
 * The alert recipients one save declares, against those already stored.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/saved-search-views/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rbac;


/**
 * The declared alert and the recipients stored before the save.
 *
 * @spec openspec/specs/saved-search-views/spec.md
 */
final class AlertRecipientChange {
	/**
	 * Describe the change.
	 *
	 * @param array<string, mixed>|null $alert    The declared alert, or null when the save declares none.
	 * @param string[]                  $previous Recipients already stored on the view.
	 */
	public function __construct(
		public readonly ?array $alert,
		public readonly array $previous = [],
	) {
	}//end __construct()
}//end class
