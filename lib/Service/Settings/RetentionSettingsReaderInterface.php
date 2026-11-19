<?php

/**
 * Reads the stored retention settings, with their defaults filled in.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Settings;

use OCA\OpenRegister\Kernel\Contracts\Core\RetentionSettingsInterface;

/**
 * The read side of the retention settings, for callers that only read them.
 *
 * Its one read, getRetentionSettingsOnly(), is declared by the Core port it
 * extends, so a Core caller types against that port. The container binds it to
 * {@see ObjectRetentionHandler} in ContractBindings.
 */
interface RetentionSettingsReaderInterface extends RetentionSettingsInterface {
}//end interface
