<?php

/**
 * BatchOperationStatusInterface: what callers outside the Service component ask of BatchOperationStatus.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object
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

namespace OCA\OpenRegister\Service\Object;

/**
 * The failure read of a streaming batch status, as the bulk save outcome
 * summarises it. Recording outcomes stays on the value object itself.
 *
 * @spec openspec/specs/reference-existence-validation/spec.md
 */
interface BatchOperationStatusInterface {

	/**
	 * Get the failed-row records.
	 *
	 * @return list<array{uuid: string|null, message: string, exceptionClass: string, index: int|null}>
	 *
	 * @spec openspec/specs/reference-existence-validation/spec.md
	 */
	public function getFailed(): array;

	/**
	 * Number of rows recorded as failed.
	 *
	 * @return int
	 *
	 * @spec openspec/specs/reference-existence-validation/spec.md
	 */
	public function getFailedCount(): int;
}//end interface
