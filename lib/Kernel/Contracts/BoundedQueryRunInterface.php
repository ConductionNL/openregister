<?php
/**
 * BoundedQueryRunInterface: the row cap and deadline a GraphQL run carries in its resolver context.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts;

/**
 * What a list resolver needs of a bounded run (the admin query console).
 *
 * Implemented by \OCA\OpenRegister\Search\Service\GraphQL\BoundedRun.
 *
 * @spec openspec/changes/operate-admin-query-console/specs/admin-query-console/spec.md
 */
interface BoundedQueryRunInterface {

	/**
	 * The context key the run is carried under.
	 *
	 * @var string
	 */
	public const CONTEXT_KEY = 'boundedRun';

	/**
	 * The error code a passed deadline carries.
	 *
	 * @var string
	 */
	public const TIMEOUT_CODE = 'QUERY_TIMEOUT';

	/**
	 * The lower of a list's requested page size and what the run has left.
	 *
	 * @param int $requested The `first` the list asked for.
	 *
	 * @return int The page size the list may use.
	 */
	public function clampFirst(int $requested): int;

	/**
	 * Count rows a list returned against the cap.
	 *
	 * @param int $rows The rows.
	 *
	 * @return void
	 */
	public function consume(int $rows): void;

	/**
	 * Refuse further work once the deadline has passed.
	 *
	 * @param callable|null $clock Returns the current microtime; null for the real clock.
	 *
	 * @return void
	 *
	 * @throws \GraphQL\Error\Error With extensions.code TIMEOUT_CODE when the deadline has passed.
	 */
	public function assertWithinDeadline(?callable $clock = null): void;
}//end interface
