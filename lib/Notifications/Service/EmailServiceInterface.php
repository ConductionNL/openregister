<?php

/**
 * EmailServiceInterface: the public surface of EmailService, for callers to type against.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Notifications
 * @package  OCA\OpenRegister\Notifications\Service
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

namespace OCA\OpenRegister\Notifications\Service;

use Exception;
use OCA\OpenRegister\Db\EmailLink;
use OCA\OpenRegister\Db\ListQuery;

/**
 * Every public method of EmailService. Callers depend on this interface, not on the class.
 */
interface EmailServiceInterface {

	/**
	 * Check if the Nextcloud Mail app is installed and enabled.
	 *
	 * @return bool True if Mail app is available.
	 */
	public function isMailAvailable(): bool;

	/**
	 * Get all email links for an object.
	 *
	 * @param string    $objectUuid The object UUID.
	 * @param ListQuery $query      The page: limit (unbounded for all) and offset.
	 *
	 * @return array{results: array, total: int} Email links with total count.
	 *
	 * @spec openspec/specs/mail-sidebar/spec.md
	 */
	public function getEmailsForObject(string $objectUuid, ListQuery $query = new ListQuery(limit: ListQuery::UNBOUNDED)): array;

	/**
	 * Link an existing email to an object.
	 *
	 * @param EmailLinkRequest $request The object uuid, its register id, and the mail account and message ids.
	 *
	 * @return EmailLink The created link.
	 *
	 * @throws Exception If the email does not exist or is already linked.
	 *
	 * @spec openspec/specs/mail-sidebar/spec.md
	 */
	public function linkEmail(EmailLinkRequest $request): EmailLink;

	/**
	 * Remove an email link.
	 *
	 * @param int $linkId The link ID.
	 *
	 * @return void
	 *
	 * @throws Exception If the link is not found.
	 *
	 * @spec exclude Legacy link-table delete by id; email link infrastructure being removed per linked-entity-types.
	 */
	public function unlinkEmail(int $linkId): void;

	/**
	 * Delete all email links for an object (cleanup).
	 *
	 * @param string $objectUuid The object UUID.
	 *
	 * @return int Number of deleted links.
	 *
	 * @spec exclude Legacy link-table bulk cleanup delegating to EmailLinkMapper; email link infrastructure being removed per linked-entity-types.
	 */
	public function deleteLinksForObject(string $objectUuid): int;
}//end interface
