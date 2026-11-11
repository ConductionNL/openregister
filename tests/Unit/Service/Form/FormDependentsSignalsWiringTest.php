<?php

/**
 * Unit tests that the form dependents check reports through the channels its signals carry.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Form
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Form;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Event\FormDestinationDependentsEvent;
use OCA\OpenRegister\Service\Form\FormDependent;
use OCA\OpenRegister\Service\Form\FormDependentsCheck;
use OCA\OpenRegister\Service\Form\FormDependentsSignals;
use OCA\OpenRegister\Service\Form\FormDestinationValidator;
use OCA\OpenRegister\Service\Form\PublishedForm;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\Notification\IManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The dependents question goes out on the signals' dispatcher, and the author's notice on its notification manager.
 *
 * @covers \OCA\OpenRegister\Service\Form\FormDependentsSignals
 * @covers \OCA\OpenRegister\Service\Form\FormDependentsCheck
 */
class FormDependentsSignalsWiringTest extends TestCase {

	/**
	 * A broken form is found through the dispatcher and its author is told through the notification manager.
	 *
	 * @return void
	 */
	public function testTheCheckUsesBothChannelsOfItsSignals(): void {
		$schema = new Schema();
		$schema->setSlug('ticket');

		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->expects($this->once())
			->method('dispatchTyped')
			->willReturnCallback(
				static function (FormDestinationDependentsEvent $event): void {
					$event->addForm(
						new FormDependent(
							form: new PublishedForm(id: 'form-a', app: 'pipelinq', title: 'Contact'),
							author: 'alice',
							mapping: ['fields' => []],
							unpublish: static function (): void {
							}
						)
					);
				}
			);

		$notification = $this->createMock(INotification::class);
		foreach (['setApp', 'setUser', 'setDateTime', 'setObject', 'setSubject'] as $setter) {
			$notification->method($setter)->willReturnSelf();
		}

		$notification->expects($this->once())->method('setUser')->with('alice');
		$notifications = $this->createMock(IManager::class);
		$notifications->method('createNotification')->willReturn($notification);
		$notifications->expects($this->once())->method('notify')->with($notification);

		$validator = $this->createMock(FormDestinationValidator::class);
		$validator->method('validate')->willReturn([['code' => 'missing_required', 'property' => 'priority']]);

		$check = new FormDependentsCheck(
			new FormDependentsSignals($dispatcher, $notifications),
			$validator,
			$this->createMock(IAppConfig::class),
			$this->createMock(LoggerInterface::class)
		);

		$applied = $check->apply($check->assess($schema));

		$this->assertSame('unpublished', $applied[0]['outcome']);

	}//end testTheCheckUsesBothChannelsOfItsSignals()
}//end class
