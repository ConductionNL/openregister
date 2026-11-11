<?php

/**
 * The notifications-annotation install listener hands every event to the installer.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Listener
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

namespace OCA\OpenRegister\Tests\Unit\Listener;

use OCA\OpenRegister\Listener\NotificationsAnnotationInstallListener;
use OCA\OpenRegister\Service\Notification\NotificationsAnnotationInstallerInterface;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;

/**
 * The listener is a pure delegate: the installer decides which events matter.
 */
class NotificationsAnnotationInstallListenerTest extends TestCase {
	public function testForwardsTheExactEventToTheInstallerOnce(): void {
		$event     = new Event();
		$installer = $this->createMock(NotificationsAnnotationInstallerInterface::class);
		$installer->expects($this->once())->method('handle')->with($this->identicalTo($event));

		(new NotificationsAnnotationInstallListener($installer))->handle($event);
	}
}
