<?php

/**
 * OpenRegister Rewrite Tally
 *
 * Where a refresh's rewrites report: the caller's report and what the run sent, held by reference.
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
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-a-refresh-rewrites-every-object-the-set-declares
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ExampleSet;

/**
 * The caller's report and the map of what this run sent, by record key, held by reference: a rewrite writes into
 * them as it goes, so what it reported stays reported when a later step throws.
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-a-refresh-rewrites-every-object-the-set-declares
 */
final class RewriteTally {

	/**
	 * The report: `types` counts a refreshed or unchanged object, `dropped` names a failure.
	 *
	 * @var array<string, mixed>
	 */
	public array $report;

	/**
	 * What this run sent, by record key, for the proof.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	public array $written;

	/**
	 * Constructor; binds both arrays by reference.
	 *
	 * @param array<string, mixed>                $report  The report.
	 * @param array<string, array<string, mixed>> $written What this run sent, by record key.
	 */
	public function __construct(array &$report, array &$written) {
		$this->report  = &$report;
		$this->written = &$written;
	}//end __construct()
}//end class
