<?php

/**
 * ObjectHistoryForReadersInterface: what callers outside the component use of ObjectHistoryForReaders.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Audit
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Audit;

use OCA\OpenRegister\Kernel\Contracts\Core\ReadableObjectHistoryInterface;

/**
 * The object history page the audit trail endpoints show to readers.
 *
 * Declares only what callers outside the component use; the rest of the
 * class stays its own. `page()` comes from the Core contract
 * ReadableObjectHistoryInterface, which the object API asks.
 */
interface ObjectHistoryForReadersInterface extends ReadableObjectHistoryInterface {
}//end interface
