<?php

/**
 * ExternalGrantGuardInterface: the external-grant refusal and lapse reads.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rbac;


/**
 * The methods of ExternalGrantGuard that callers outside its component use.
 * Callers depend on this interface, not on the class.
 */
interface ExternalGrantGuardInterface {

	/**
	 * How many days before an end counts as "about to lapse" by default.
	 *
	 * @var int
	 */
	public const DEFAULT_WARNING_DAYS = 14;

	/**
	 * The refusal body for a block that may not be written, or null.
	 *
	 * @param array|null $authorization The block as written.
	 *
	 * @return array<string, mixed>|null The refusal, or null when the block is fine.
	 *
	 * @spec openspec/specs/authorization-rbac/spec.md
	 */
	public function refusalFor(?array $authorization): ?array;

	/**
	 * The external grants whose end arrives within the warning window.
	 *
	 * The holder is warned before it lapses so the work is not lost
	 * mid-sentence, which is only possible if somebody can ask which grants are
	 * about to run out.
	 *
	 * @param array|null       $authorization The block as written.
	 * @param LapseWindow|null $window        The moment and how far ahead to look; null for now and the default window.
	 *
	 * @return array<int, array<string, mixed>> The grants about to lapse, soonest first.
	 *
	 * @spec openspec/specs/authorization-rbac/spec.md
	 */
	public function lapsingWithin(?array $authorization, ?LapseWindow $window = null): array;
}//end interface
