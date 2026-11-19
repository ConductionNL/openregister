<?php
/**
 * ObjectEmailLinkWritesInterface: the checks and writes the email endpoints of an object need from the mail link store.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts\Notifications
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts\Notifications;

use Exception;

/**
 * What the email endpoints of an object (Notifications) ask of the mail link store.
 *
 * The store is Integrations' work (the Mail app integration); it implements this
 * contract through EmailLinkServiceInterface and binds it in its own wiring.
 *
 * @spec openspec/specs/generic-integrations/spec.md
 */
interface ObjectEmailLinkWritesInterface {

	/**
	 * Unlink a Mail message from an OR object.
	 *
	 * @param string $objectUuid Parent OR object uuid.
	 * @param int    $linkId     Link primary key.
	 *
	 * @return void
	 *
	 * @throws Exception When no matching link is found (404).
	 *
	 * @spec openspec/specs/generic-integrations/spec.md
	 */
	public function unlinkEmail(string $objectUuid, int $linkId): void;

	/**
	 * Verify the active user owns the Mail account; throw on mismatch.
	 *
	 * Defence-in-depth — Mail's middleware already enforces this server-side
	 * for its own endpoints, but the picker reaches into the raw tables
	 * so we re-check ownership before exposing any per-account data.
	 *
	 * @param int $accountId Mail account id.
	 *
	 * @return void
	 *
	 * @throws Exception When the active user does not own the account.
	 *
	 * @spec openspec/specs/generic-integrations/spec.md
	 */
	public function assertAccountOwnership(int $accountId): void;
}//end interface
