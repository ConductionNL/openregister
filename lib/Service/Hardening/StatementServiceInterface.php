<?php

/**
 * The published hardening statement and its acceptances.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Hardening
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/instance-hardening-controls/specs/instance-hardening/spec.md#requirement-a-published-statement-is-accepted-before-use-per-version-req-ihc-001
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Hardening;

use InvalidArgumentException;

/**
 * The published hardening statement and its acceptances.
 *
 * Bound to StatementService in ContractBindings. Every method is described on StatementService.
 */
interface StatementServiceInterface {

	/**
	 * The statement in force, or null when nothing is published.
	 *
	 * @return array{version: string, title: string, body: string, publishedAt: string, publishedBy: string}|null The statement.
	 *
	 * @spec openspec/changes/instance-hardening-controls/specs/instance-hardening/spec.md#requirement-a-published-statement-is-accepted-before-use-per-version-req-ihc-001
	 */
	public function published(): ?array;

	/**
	 * Publish a statement, or a new version of one.
	 *
	 * @param string $version The version, as the administrator writes it.
	 * @param string $body    The text a user reads.
	 * @param string $title   The heading above it.
	 * @param string $userId  Who published it.
	 *
	 * @return array{version: string, title: string, body: string, publishedAt: string, publishedBy: string} The statement now in force.
	 *
	 * @throws InvalidArgumentException When the version or the text is missing.
	 *
	 * @spec openspec/changes/instance-hardening-controls/specs/instance-hardening/spec.md#requirement-a-published-statement-is-accepted-before-use-per-version-req-ihc-001
	 */
	public function publish(string $version, string $body, string $title = '', string $userId = ''): array;

	/**
	 * Withdraw the statement, so nothing is asked.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/instance-hardening-controls/specs/instance-hardening/spec.md#requirement-a-published-statement-is-accepted-before-use-per-version-req-ihc-001
	 */
	public function withdraw(): void;

	/**
	 * What one user has accepted, or null when they have accepted nothing.
	 *
	 * @param string $userId The account.
	 *
	 * @return array{version: string, acceptedAt: string}|null The acceptance.
	 *
	 * @spec openspec/changes/instance-hardening-controls/specs/instance-hardening/spec.md#requirement-a-published-statement-is-accepted-before-use-per-version-req-ihc-001
	 */
	public function acceptanceOf(string $userId): ?array;

	/**
	 * Whether this user is asked before the application renders.
	 *
	 * An anonymous caller is never asked: there is nobody to record the
	 * acceptance against, and a statement accepted by nobody is not evidence.
	 *
	 * @param string $userId The account, or an empty string for an anonymous caller.
	 *
	 * @return bool True when the statement must be shown and accepted first.
	 *
	 * @spec openspec/changes/instance-hardening-controls/specs/instance-hardening/spec.md#requirement-a-published-statement-is-accepted-before-use-per-version-req-ihc-001
	 */
	public function needsAcceptance(string $userId): bool;

	/**
	 * Record that this user accepted this version.
	 *
	 * The version is checked against the one in force rather than trusted from
	 * the request: a client that posts an old version would otherwise close the
	 * gate on a text the user was never shown.
	 *
	 * @param string $userId  The account accepting.
	 * @param string $version The version they were shown.
	 *
	 * @return array{version: string, acceptedAt: string} The acceptance as recorded.
	 *
	 * @throws InvalidArgumentException When nothing is published, or the version is not the one in force.
	 *
	 * @spec openspec/changes/instance-hardening-controls/specs/instance-hardening/spec.md#requirement-a-published-statement-is-accepted-before-use-per-version-req-ihc-001
	 */
	public function accept(string $userId, string $version): array;
}//end interface
