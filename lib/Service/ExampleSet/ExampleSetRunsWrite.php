<?php

/**
 * Writing the runs a set's flow carries.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ExampleSet
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-flow-may-carry-finished-runs
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ExampleSet;

use Closure;
use OCA\OpenRegister\Db\Flow;

/**
 * One flow's runs to write: the stored flow, its version, the declared runs and where their uuids come from.
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-flow-may-carry-finished-runs
 */
final class ExampleSetRunsWrite {
	/**
	 * Constructor.
	 *
	 * @param Flow $flow The stored flow the runs belong to.
	 * @param int|null $version The flow version the runs ran, if any.
	 * @param array<int, array<string, mixed>> $runs The declared runs.
	 * @param Closure $newUuid Mints a uuid for each run: fn (): string.
	 */
	public function __construct(
		public readonly Flow $flow,
		public readonly ?int $version,
		public readonly array $runs,
		public readonly Closure $newUuid,
	) {
	}//end __construct()
}//end class
