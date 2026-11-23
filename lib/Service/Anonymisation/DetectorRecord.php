<?php

/**
 * DetectorRecord: the Compliance side of DetectorRecordInterface.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Anonymisation
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Anonymisation;

use OCA\OpenRegister\Kernel\Contracts\DetectorRecordInterface;

/**
 * Reads the backend state and encodes the effective backend's version record.
 * Errors reading the state propagate; the run that asks decides (it logs and
 * stores no record).
 *
 * @spec openspec/changes/anonymisation-discloses-itself/specs/anonymisation-disclosure/spec.md#requirement-every-detection-backend-reports-what-it-is-built-from-req-adi-001
 */
final class DetectorRecord implements DetectorRecordInterface {

	/**
	 * Constructor.
	 *
	 * @param AnonymisationBackendServiceInterface $backends The backend state.
	 */
	public function __construct(
		private readonly AnonymisationBackendServiceInterface $backends,
	) {
	}//end __construct()

	/**
	 * The version record of the detection backend in effect, as JSON.
	 *
	 * @return string|null The JSON record, or null.
	 *
	 * @spec openspec/changes/anonymisation-discloses-itself/specs/anonymisation-disclosure/spec.md#requirement-every-detection-backend-reports-what-it-is-built-from-req-adi-001
	 */
	public function detectorRecord(): ?string {
		$state = $this->backends->getState();
		$info  = ($state->backends[$state->effectiveMethod] ?? null);
		if (($info instanceof BackendInfo) === false || $info->detector === null) {
			return null;
		}

		$encoded = json_encode(array_merge(['backend' => $state->effectiveMethod], $info->detector->jsonSerialize()));
		if (is_string($encoded) === false) {
			return null;
		}

		return $encoded;
	}//end detectorRecord()
}//end class
