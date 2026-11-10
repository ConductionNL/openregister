<?php

/**
 * One scheduled render of one dashboard.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category BackgroundJob
 * @package  OCA\OpenRegister\Search\BackgroundJob\Support
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Search\BackgroundJob\Support;

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * The dashboard a ReportRenderJob pass is rendering and its data.
 *
 * Built by ReportRenderJob for each candidate and never handed outside it.
 *
 * @spec openspec/specs/rapportage-bi-export/spec.md
 */
final class DashboardRenderRun {
	/**
	 * Constructor.
	 *
	 * @param ObjectEntity         $dashboard The dashboard object.
	 * @param array<string, mixed> $payload   The dashboard's data.
	 *
	 * @spec openspec/specs/rapportage-bi-export/spec.md
	 */
	public function __construct(
		public readonly ObjectEntity $dashboard,
		public readonly array $payload,
	) {
	}//end __construct()

	/**
	 * The schedule descriptor, as stored (may be anything).
	 *
	 * @return mixed
	 *
	 * @spec openspec/specs/rapportage-bi-export/spec.md
	 */
	public function schedule(): mixed {
		return ($this->payload['schedule'] ?? null);
	}//end schedule()

	/**
	 * The delivery descriptor; an empty one when it is missing or not a map.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/rapportage-bi-export/spec.md
	 */
	public function delivery(): array {
		$delivery = ($this->payload['delivery'] ?? []);
		if (is_array($delivery) === false) {
			return [];
		}

		return $delivery;
	}//end delivery()
}//end class
