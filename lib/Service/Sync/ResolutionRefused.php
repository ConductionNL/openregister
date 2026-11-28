<?php

/**
 * OpenRegister Resolution Refused
 *
 * A conflict resolution refused, with the HTTP status the REST surface answers it with.
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
 * A resolution refused: 403 when the caller may not change the object, 409 when the record is no conflict, 422 for a bad request.
 *
 * @spec openspec/changes/app-harvest-fetchers-and-flow-node/specs/data-sync-harvesting/spec.md
 */
final class ResolutionRefused extends InvalidArgumentException {

	/**
	 * Constructor.
	 *
	 * @param int    $status  The HTTP status.
	 * @param string $message Why it was refused.
	 */
	public function __construct(
		public readonly int $status,
		string $message,
	) {
		parent::__construct(message: $message);
	}//end __construct()

	/**
	 * The response body: the reason.
	 *
	 * @return array{error: string}
	 *
	 * @spec openspec/changes/app-harvest-fetchers-and-flow-node/specs/data-sync-harvesting/spec.md
	 */
	public function toArray(): array {
		return ['error' => $this->getMessage()];
	}//end toArray()
}//end class
