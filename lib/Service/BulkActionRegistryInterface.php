<?php

/**
 * BulkActionRegistryInterface: what callers outside the Service component use of BulkActionRegistry.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
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

namespace OCA\OpenRegister\Service;

use InvalidArgumentException;
use OCA\OpenRegister\BulkAction\BulkActionInterface;

/**
 * The registration and catalogue the bulk-jobs endpoint and the registration event call on BulkActionRegistry.
 */
interface BulkActionRegistryInterface {

	/**
	 * The catalogue as a consumer reads it.
	 *
	 * @return array<int, array<string, mixed>> One entry per action.
	 *
	 * @spec openspec/specs/bulk-action-jobs/spec.md
	 */
	public function describe(): array;

	/**
	 * Register one action.
	 *
	 * Called by apps during the BulkActionRegistrationEvent.
	 *
	 * @param BulkActionInterface $action The action to register.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When the id is malformed or taken.
	 *
	 * @spec openspec/specs/bulk-action-jobs/spec.md
	 */
	public function register(BulkActionInterface $action): void;
}//end interface
