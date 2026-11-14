<?php

/**
 * OpenRegister Source Refused
 *
 * A source write refused for one field; the REST surface answers it with 422 naming that field.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Sync
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/app-harvest-fetchers-and-flow-node/specs/data-sync-harvesting/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Sync;

use InvalidArgumentException;

/**
 * A source create or update refused because of one field.
 *
 * @spec openspec/changes/app-harvest-fetchers-and-flow-node/specs/data-sync-harvesting/spec.md
 */
final class SourceRefused extends InvalidArgumentException {

	/**
	 * Constructor.
	 *
	 * @param string $field   The refused field.
	 * @param string $message Why it was refused.
	 */
	public function __construct(
		public readonly string $field,
		string $message,
	) {
		parent::__construct(message: $message);
	}//end __construct()

	/**
	 * The response body: the field and the reason.
	 *
	 * @return array{error: string, field: string}
	 *
	 * @spec openspec/changes/app-harvest-fetchers-and-flow-node/specs/data-sync-harvesting/spec.md
	 */
	public function toArray(): array {
		return ['error' => $this->getMessage(), 'field' => $this->field];
	}//end toArray()
}//end class
