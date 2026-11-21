<?php

/**
 * OpenRegister Row Install Run
 *
 * One install of a fragment's rows of one engine-row kind.
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
 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-declare-saved-views-for-the-sets-own-accounts
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ExampleSet;

/**
 * The fragment whose rows are written, the context placeholders draw on, whether this run refreshes the part, and
 * the report and record the rows are written into.
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-declare-saved-views-for-the-sets-own-accounts
 */
final class RowInstallRun {

	/**
	 * The fragment.
	 *
	 * @var array<string, mixed>
	 */
	public readonly array $fragment;

	/**
	 * The context placeholders draw on.
	 *
	 * @var array<string, mixed>
	 */
	public readonly array $context;

	/**
	 * Whether this run refreshes the part.
	 *
	 * @var boolean
	 */
	public readonly bool $refresh;

	/**
	 * The report and the record, held by reference.
	 *
	 * @var RecordLedger
	 */
	public readonly RecordLedger $ledger;

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $fragment The fragment.
	 * @param array<string, mixed> $context  The context placeholders draw on.
	 * @param boolean              $refresh  Whether this run refreshes the part.
	 * @param RecordLedger         $ledger   The report and the record, held by reference.
	 */
	public function __construct(array $fragment, array $context, bool $refresh, RecordLedger $ledger) {
		$this->fragment = $fragment;
		$this->context  = $context;
		$this->refresh  = $refresh;
		$this->ledger   = $ledger;
	}//end __construct()
}//end class
