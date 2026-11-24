<?php

/**
 * TranslationProjectionServiceInterface: the part of TranslationProjectionService that the projection listener and background job use.
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

use OCA\OpenRegister\Kernel\Contracts\Processes\TranslationProjectionInterface;

/**
 * The methods of TranslationProjectionService that callers outside its component use.
 * Callers depend on this interface, not on the class. project() and purge()
 * are the Processes port TranslationProjectionInterface.
 */
interface TranslationProjectionServiceInterface extends TranslationProjectionInterface {
}//end interface
