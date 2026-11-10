<?php

/**
 * ZaaktypeCaseSkeletonMapperInterface: what callers outside the Service component ask of ZaaktypeCaseSkeletonMapper.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Case
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

namespace OCA\OpenRegister\Service\Case;

/**
 * Mapping a zaaktype onto a case-plan skeleton, as the case controller uses
 * it. Only the method that caller calls.
 */
interface ZaaktypeCaseSkeletonMapperInterface {

	/**
	 * Map a zaaktype document onto a draft case-plan definition, with a report.
	 *
	 * @param array<string, mixed> $zaaktype The zaaktype document as stored.
	 *
	 * @return array{draft: bool, definition: array<string, mixed>, report: array<int, array<string, string>>} The result.
	 */
	public function map(array $zaaktype): array;
}//end interface
