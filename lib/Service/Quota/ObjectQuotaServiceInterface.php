<?php

/**
 * ObjectQuotaServiceInterface: ObjectQuotaService as the object quota listener reaches it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Quota
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

namespace OCA\OpenRegister\Service\Quota;

use OCA\OpenRegister\Kernel\Contracts\Core\ObjectQuotaInterface;

/**
 * ObjectQuotaService, typed for callers outside the Service component.
 *
 * Its outside callers, the object quota listener and the bulk save path, call limitFor()
 * and count(), so those are the whole contract. They are declared once, on the Core-owned
 * ObjectQuotaInterface this interface extends, so the bulk save path can hold its creates
 * to the cap without naming a Processes class. Binding it in ContractBindings lets the
 * listener resolve ObjectQuotaService without naming the class.
 */
interface ObjectQuotaServiceInterface extends ObjectQuotaInterface {
}//end interface
