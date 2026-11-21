<?php

/**
 * Reads the stored archival settings, with their defaults filled in.
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

use OCA\OpenRegister\Kernel\Contracts\Compliance\ArchivalSettingsInterface;

/**
 * The read side of the archival settings, for callers that only read them.
 *
 * The method is declared on the Compliance contract this interface extends.
 * The container binds both to {@see ObjectRetentionHandler} in ContractBindings.
 */
interface ArchivalSettingsReaderInterface extends ArchivalSettingsInterface {
}//end interface
