<?php

/**
 * NotificationsAnnotationInstallerInterface: what callers outside the component use of NotificationsAnnotationInstaller.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Notification
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

namespace OCA\OpenRegister\Service\Notification;

use OCA\OpenRegister\Kernel\Contracts\Notifications\DeclaredWebhookInstallerInterface;

/**
 * Synchronises the persistent webhooks a schema declares, on the schema-saved events.
 *
 * Declares only what callers outside the component use; the rest of the
 * class stays its own. Its entry point, handle(), is the Notifications port
 * DeclaredWebhookInstallerInterface the notifications annotation listener calls.
 */
interface NotificationsAnnotationInstallerInterface extends DeclaredWebhookInstallerInterface {
}//end interface
