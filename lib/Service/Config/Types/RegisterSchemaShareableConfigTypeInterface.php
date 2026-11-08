<?php

/**
 * RegisterSchemaShareableConfigTypeInterface: the register/schema shareable config type as its registration listener reaches it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Config\Types
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

namespace OCA\OpenRegister\Service\Config\Types;

use OCA\OpenRegister\Service\Config\IShareableConfigType;

/**
 * The register/schema shareable config type, typed for callers outside the Service component.
 *
 * Its only outside caller hands it to {@see \OCA\OpenRegister\Service\Config\RegisterShareableConfigTypesEvent::registerType()},
 * which takes an IShareableConfigType, so the contract adds nothing to it. Binding it in
 * ContractBindings lets that caller resolve the type without naming the class.
 */
interface RegisterSchemaShareableConfigTypeInterface extends IShareableConfigType {
}//end interface
