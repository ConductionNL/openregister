<?php

/**
 * DraftExpiryInterface: what callers outside the Service component use of DraftExpiry.
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

use DateTimeImmutable;

/**
 * The methods of DraftExpiry that callers outside the Service component use.
 *
 * Callers type against this interface and resolve it from the container; the
 * registrar binds it to DraftExpiry.
 */
interface DraftExpiryInterface {

	/**
	 * How many drafts one run removes at most, over all schemas; the rest waits for the next run.
	 *
	 * @var integer
	 */
	public const MAX_REMOVALS = 1000;

	/**
	 * Count what a sweep at this moment would remove, per schema, and remove nothing.
	 *
	 * For an administrator who wants to look before the job runs, the first
	 * run after an upgrade above all: the default lifetime of 30 days applies
	 * to drafts that were kept until now.
	 *
	 * @param DateTimeImmutable $now The moment the lifetimes are measured from.
	 *
	 * @return array{
	 *     schemas: int, expired: int, removed: int, refused: int, deferred: int,
	 *     perSchema: array<int, array{
	 *         register: string, schema: string, lifetimeDays: int, expired: int, removed: int, refused: int, deferred: int
	 *     }>
	 * }
	 *         The same shape as sweep(), with nothing removed: every expired draft is counted as deferred.
	 *
	 * @spec openspec/changes/draft-owner-activation-and-lifetime/specs/form-destination/spec.md#requirement-an-administrator-must-be-able-to-see-what-the-draft-cleanup-would-remove
	 */
	public function preview(DateTimeImmutable $now): array;

	/**
	 * Remove every draft whose last change is older than its schema's lifetime, up to the run's limit.
	 *
	 * @param DateTimeImmutable $now The moment the lifetimes are measured from.
	 *
	 * @return array{
	 *     schemas: int, expired: int, removed: int, refused: int, deferred: int,
	 *     perSchema: array<int, array{
	 *         register: string, schema: string, lifetimeDays: int, expired: int, removed: int, refused: int, deferred: int
	 *     }>
	 * }
	 *         Register and schema pairs with a lifetime, drafts past it, removed, not removed, left for the
	 *         next run, and the same per pair that holds an expired draft.
	 *
	 * @spec openspec/changes/draft-owner-activation-and-lifetime/specs/form-destination/spec.md#requirement-one-background-job-must-remove-drafts-past-their-schemas-lifetime
	 */
	public function sweep(DateTimeImmutable $now): array;
}//end interface
