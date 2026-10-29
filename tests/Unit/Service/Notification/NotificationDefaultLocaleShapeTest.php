<?php

/**
 * A `defaultLocale` that is not a string is refused, not a crash.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Notification
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/notificatie-engine/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\Notification;

use OCA\OpenRegister\Service\Notification\NotificationAnnotationValidator;
use PHPUnit\Framework\TestCase;

/**
 * The subject and message maps both carry an optional `defaultLocale`. An
 * array or object there is a schema error the validator reports, the same
 * way it reports an undeclared locale.
 */
class NotificationDefaultLocaleShapeTest extends TestCase {

	/**
	 * Validate one rule with the given subject and message.
	 *
	 * @param mixed $subject The rule's subject.
	 * @param mixed $message The rule's message.
	 *
	 * @return array<int, array{code: string, message: string}>
	 */
	private function errors(mixed $subject, mixed $message): array {
		return (new NotificationAnnotationValidator())->validate(
			[
				'properties' => ['status' => ['type' => 'string']],
				'x-openregister-notifications' => [
					'r' => [
						'trigger'    => ['type' => 'created'],
						'channels'   => ['nc-notification'],
						'recipients' => [['kind' => 'users', 'users' => ['admin']]],
						'subject'    => $subject,
						'message'    => $message,
					],
				],
			]
		);
	}//end errors()

	/**
	 * An array `defaultLocale` in the message map is a bad-message error.
	 *
	 * @return void
	 */
	public function testAnArrayMessageDefaultLocaleIsRefused(): void {
		$this->assertSame(
			[
				[
					'code' => 'notification-bad-message',
					'message' => 'Notification "r" message defaultLocale "array" is not declared in the message map.',
				],
			],
			$this->errors(subject: 'Hello', message: ['nl' => 'Hoi', 'defaultLocale' => ['nl']])
		);
	}//end testAnArrayMessageDefaultLocaleIsRefused()

	/**
	 * An array `defaultLocale` in the subject map is a bad-default-locale error.
	 *
	 * @return void
	 */
	public function testAnArraySubjectDefaultLocaleIsRefused(): void {
		$this->assertSame(
			[
				[
					'code' => 'notification-bad-default-locale',
					'message' => 'Notification "r" defaultLocale "array" is not declared in the subject map.',
				],
			],
			$this->errors(subject: ['nl' => 'Hoi', 'defaultLocale' => ['nl']], message: 'Body')
		);
	}//end testAnArraySubjectDefaultLocaleIsRefused()

	/**
	 * A declared string `defaultLocale` still passes.
	 *
	 * @return void
	 */
	public function testADeclaredDefaultLocalePasses(): void {
		$this->assertSame(
			[],
			$this->errors(subject: ['nl' => 'Hoi', 'defaultLocale' => 'nl'], message: ['nl' => 'Body', 'defaultLocale' => 'nl'])
		);
	}//end testADeclaredDefaultLocalePasses()
}//end class
