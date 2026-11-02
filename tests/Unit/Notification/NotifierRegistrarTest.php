<?php

/**
 * The notifier registrar registers both notifiers.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Notification;

use OCA\OpenRegister\Notification\NotifierRegistrar;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use PHPUnit\Framework\TestCase;

class NotifierRegistrarTest extends TestCase {

	/**
	 * register() registers the annotation notifier and the hand-written notifier, and no service.
	 *
	 * @return void
	 */
	public function testRegistersBothNotifiers(): void {
		$registered = [];
		$context    = $this->createMock(originalClassName: IRegistrationContext::class);
		$context->method('registerNotifierService')->willReturnCallback(
			function (string $class) use (&$registered): void {
				$registered[] = $class;
			}
		);
		$context->expects($this->never())->method('registerService');

		NotifierRegistrar::register(context: $context);

		$this->assertSame(
			expected: [
				'OCA\OpenRegister\Notification\AnnotationNotifier',
				'OCA\OpenRegister\Notification\Notifier',
			],
			actual: $registered
		);
	}//end testRegistersBothNotifiers()
}//end class
