<?php

/**
 * ApiCallRecorderInterface: ApiCallRecorder as the API caller middleware reaches it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ApiCaller
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

namespace OCA\OpenRegister\Service\ApiCaller;


/**
 * ApiCallRecorder, typed for callers outside the Service component.
 *
 * Its outside caller, the API caller middleware, calls principal() and recordCall(), so those are
 * the whole contract. Binding it in ContractBindings lets that caller resolve
 * ApiCallRecorder without naming the class.
 */
interface ApiCallRecorderInterface {
	/**
	 * The caller making the current request.
	 *
	 * @return string The Nextcloud uid, or the empty string for anonymous.
	 *
	 * @spec openspec/specs/api-surface-governance/spec.md
	 */
	public function principal(): string;

	/**
	 * Record one call against the caller making the current request.
	 *
	 * @param ApiCallObservation $call The path, method, API version and moment (defaults to now).
	 *
	 * @return void
	 *
	 * @spec openspec/specs/api-surface-governance/spec.md#requirement-every-api-call-records-its-caller-and-a-caller-carries-a-limit-and-an-address-binding-req-avs-003
	 */
	public function recordCall(ApiCallObservation $call): void;
}//end interface
