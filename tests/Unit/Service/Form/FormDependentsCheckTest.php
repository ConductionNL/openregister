<?php

/**
 * Saving a schema re-checks the published forms that submit into it.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-saving-a-schema-must-re-check-the-forms-that-submit-into-it
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Form;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Event\FormDestinationDependentsEvent;
use OCA\OpenRegister\Service\Form\FormDependent;
use OCA\OpenRegister\Service\Form\FormDependentsCheck;
use OCA\OpenRegister\Service\Form\FormDestinationValidator;
use OCA\OpenRegister\Service\Form\FormFieldRules;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\Notification\IManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Q9 (Ruben): the check refuses from day one, no report mode. A broken form is unpublished (default) or the save refused, per the owning app.
 *
 * @covers \OCA\OpenRegister\Service\Form\FormDependentsCheck
 * @covers \OCA\OpenRegister\Service\Form\FormDependent
 * @covers \OCA\OpenRegister\Event\FormDestinationDependentsEvent
 * @uses \OCA\OpenRegister\Service\Form\FormDestinationValidator
 * @uses \OCA\OpenRegister\Service\Form\FormFieldRules
 * @uses \OCA\OpenRegister\Db\Schema
 */
class FormDependentsCheckTest extends TestCase {

	private IAppConfig&MockObject $appConfig;

	private IManager&MockObject $notifications;

	/**
	 * @var array<int, string>
	 */
	private array $unpublished = [];

	/**
	 * @var array<string, string>
	 */
	private array $settings = [];

	/**
	 * @var array<int, array<string, mixed>>
	 */
	private array $sent = [];

	private FormDependentsCheck $check;

	protected function setUp(): void {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $text, array $parameters = []): string => $parameters === [] ? $text : vsprintf($text, $parameters)
		);

		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(
			function (Event $event): void {
				$this->assertInstanceOf(FormDestinationDependentsEvent::class, $event);
				$this->assertSame('ticket', $event->getSchema()->getSlug());
				$event->addForm(
					new FormDependent(
						id: 'form-a',
						app: 'pipelinq',
						title: 'Contact opnemen',
						author: 'alice',
						mapping: ['fields' => [['field' => 'onderwerp', 'property' => 'title']]],
						unpublish: function (): void {
							$this->unpublished[] = 'form-a';
						}
					)
				);
				$event->addForm(
					new FormDependent(
						id: 'form-b',
						app: 'portaliq',
						title: 'Melding',
						author: null,
						mapping: ['fields' => [['field' => 'onderwerp', 'property' => 'title'], ['field' => 'prio', 'property' => 'priority', 'type' => 'choice', 'options' => ['low', 'high']]]],
						unpublish: function (): void {
							$this->unpublished[] = 'form-b';
						}
					)
				);
			}
		);

		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => ($this->settings[$app . '.' . $key] ?? $default)
		);

		$this->notifications = $this->createMock(IManager::class);
		$this->notifications->method('createNotification')->willReturnCallback(fn (): INotification => $this->recordingNotification());
		$this->notifications->method('notify')->willReturnCallback(
			function (INotification $notification): void {
				$this->sent[] = ['user' => $notification->getUser(), 'subject' => $notification->getSubject(), 'parameters' => $notification->getSubjectParameters()];
			}
		);

		$this->check = new FormDependentsCheck(
			dispatcher: $dispatcher,
			validator: new FormDestinationValidator(l10n: $l10n, rules: new FormFieldRules()),
			appConfig: $this->appConfig,
			notifications: $this->notifications,
			logger: $this->createMock(LoggerInterface::class)
		);
	}//end setUp()

	/**
	 * A notification that remembers what was set on it.
	 */
	private function recordingNotification(): INotification {
		$state = ['user' => '', 'subject' => '', 'parameters' => []];
		$notification = $this->createMock(INotification::class);
		foreach (['setApp', 'setDateTime', 'setObject'] as $setter) {
			$notification->method($setter)->willReturnSelf();
		}

		$notification->method('setUser')->willReturnCallback(
			function (string $user) use (&$state, $notification): INotification {
				$state['user'] = $user;

				return $notification;
			}
		);
		$notification->method('setSubject')->willReturnCallback(
			function (string $subject, array $parameters = []) use (&$state, $notification): INotification {
				$state['subject'] = $subject;
				$state['parameters'] = $parameters;

				return $notification;
			}
		);
		$notification->method('getUser')->willReturnCallback(static function () use (&$state): string {
			return $state['user'];
		});
		$notification->method('getSubject')->willReturnCallback(static function () use (&$state): string {
			return $state['subject'];
		});
		$notification->method('getSubjectParameters')->willReturnCallback(static function () use (&$state): array {
			return $state['parameters'];
		});

		return $notification;
	}//end recordingNotification()

	/**
	 * The ticket schema after `priority` became required.
	 */
	private function ticketWithRequiredPriority(): Schema {
		$schema = new Schema();
		$schema->setSlug('ticket');
		$schema->setRequired(['title', 'priority']);
		$schema->setProperties(['title' => ['type' => 'string'], 'priority' => ['type' => 'string', 'enum' => ['low', 'high']]]);

		return $schema;
	}//end ticketWithRequiredPriority()

	/**
	 * Spec scenario: a new required property unpublishes a form that lacks it, and its author is told.
	 */
	public function testANewRequiredPropertyUnpublishesTheFormThatLacksIt(): void {
		$assessment = $this->check->assess(schema: $this->ticketWithRequiredPriority());
		$this->assertFalse($assessment['refused']);

		$applied = $this->check->apply(assessment: $assessment);

		$this->assertSame([['id' => 'form-a', 'app' => 'pipelinq', 'title' => 'Contact opnemen', 'outcome' => 'unpublished']], array_map(
			static fn (array $form): array => array_intersect_key($form, array_flip(['id', 'app', 'title', 'outcome'])),
			$applied
		));
		$this->assertSame(['form-a'], $this->unpublished);
		$this->assertSame('alice', $this->sent[0]['user']);
		$this->assertSame('form_unpublished', $this->sent[0]['subject']);
		$this->assertSame('Contact opnemen', $this->sent[0]['parameters']['formTitle']);
	}//end testANewRequiredPropertyUnpublishesTheFormThatLacksIt()

	/**
	 * An owning app set to refuse blocks the schema save, and nothing is unpublished.
	 */
	public function testAnOwningAppSetToRefuseBlocksTheSave(): void {
		$this->settings['pipelinq.formDestinationBreak'] = 'refuse';

		$assessment = $this->check->assess(schema: $this->ticketWithRequiredPriority());

		$this->assertTrue($assessment['refused']);
		$this->assertSame('refuse', $assessment['affected'][0]['break']);
		$this->assertSame([], $this->unpublished);
	}//end testAnOwningAppSetToRefuseBlocksTheSave()

	/**
	 * Unpublishing is a side effect of a save that happened: assess() alone changes nothing.
	 */
	public function testAssessAloneChangesNothing(): void {
		$assessment = $this->check->assess(schema: $this->ticketWithRequiredPriority());

		$this->assertSame(['form-a'], array_column($assessment['affected'], 'id'));
		$this->assertSame('required-unmapped', $assessment['affected'][0]['findings'][0]['code']);
		$this->assertSame([], $this->unpublished);
		$this->assertSame([], $this->sent);
	}//end testAssessAloneChangesNothing()

	/**
	 * A schema change no published form depends on affects nothing.
	 */
	public function testAChangeThatBreaksNoFormAffectsNothing(): void {
		$schema = $this->ticketWithRequiredPriority();
		$schema->setRequired(['title']);

		$this->assertSame([], $this->check->assess(schema: $schema)['affected']);
	}//end testAChangeThatBreaksNoFormAffectsNothing()
}//end class
