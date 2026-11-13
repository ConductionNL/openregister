<?php

/**
 * A decided region could not be burned, so no output is written.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\File
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/anonymisation-image-seam/specs/image-anonymisation/spec.md#requirement-decided-regions-are-burned-into-the-pixels-req-ais-003
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\File;

use RuntimeException;

/**
 * Names the file and, when one is to blame, the region.
 *
 * @spec openspec/changes/anonymisation-image-seam/specs/image-anonymisation/spec.md#requirement-decided-regions-are-burned-into-the-pixels-req-ais-003
 */
class ImageRegionNotBurnableException extends RuntimeException {

	/**
	 * Constructor.
	 *
	 * @param string           $fileName The file.
	 * @param string           $reason   Why it could not be burned.
	 * @param ImageRegion|null $region   The region, when one is to blame.
	 *
	 * @spec openspec/changes/anonymisation-image-seam/specs/image-anonymisation/spec.md#requirement-decided-regions-are-burned-into-the-pixels-req-ais-003
	 */
	public function __construct(string $fileName, string $reason, ?ImageRegion $region = null) {
		$where = '';
		if ($region !== null) {
			$where = sprintf(
				' (region %s on page %s at x=%.4F y=%.4F w=%.4F h=%.4F)',
				$region->entityType,
				(string)($region->page ?? 1),
				$region->box['x'],
				$region->box['y'],
				$region->box['w'],
				$region->box['h']
			);
		}

		parent::__construct(message: sprintf('%s could not be redacted%s: %s No anonymised copy was written.', $fileName, $where, $reason));
	}//end __construct()
}//end class
