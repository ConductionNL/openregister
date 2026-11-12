<?php

/**
 * ChatFamilyControllerInterface: marks the chat, agents and conversations controllers.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller\Support
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

namespace OCA\OpenRegister\Controller\Support;

/**
 * Marker for the chat-family controllers ChatCompatMiddleware decorates and may
 * proxy to hermiq. The middleware checks membership with instanceof against this
 * interface instead of naming each controller class, so the controllers stay
 * inside the Controller component. It declares no methods.
 */
interface ChatFamilyControllerInterface {
}//end interface
