<?php

/**
 * The dependents check around a schema save: which forms a change breaks,
 * each owning app's break setting, the refusal that wins over unpublishing,
 * and the unpublish outcomes (done and notified, unavailable, failed).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Form
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Form;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Event\FormDestinationDependentsEvent;
use OCA\OpenRegister\Service\Form\FormDependent;
use OCA\OpenRegister\Service\Form\FormDependentsCheck;
use OCA\OpenRegister\Service\Form\FormDependentsSignals;
use OCA\OpenRegister\Service\Form\FormDestinationValidator;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\Notification\IManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * FormDependentsCheck.
 */
class FormDependentsCheckBehaviourTest extends TestCase {

	private IEventDispatcher&MockObject $dispatcher;

	private IManager&MockObject $notifications;

	private FormDestinationValidator&MockObject $validator;

	private IAppConfig&MockObject $appConfig;

	private LoggerInterface&MockObject $logger;

	/**
	 * Forms the owning apps register on the event.
	 *
	 * @var list<FormDependent>
	 */
	private array $forms = [];

	/**
	 * Break setting per app.
	 *
	 * @var array<string, string>
	 */
	private array $breaks = [];

	/**
	 * Findings per form id.
	 *
	 * @var array<string, list<array<string, mixed>>>
	 */
	private array $findings = [];

	/**
	 * Validator options seen, per form mapping marker.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $options = [];

	protected function setUp(): void {
		$this->dispatcher = $this->createMock(IEventDispatcher::class);
		$this->dispatcher->method('dispatchTyped')->willReturnCallback(
			function (FormDestinationDependentsEvent $event): void {
				foreach ($this->forms as $form) {
					$event->addForm($form);
				}
			}
		);
		$this->notifications = $this->createMock(IManager::class);
		$this->validator = $this->createMock(FormDestinationValidator::class);
		$this->validator->method('validate')->willReturnCallback(
			function (array $mapping, Schema $schema, array $options = []): array {
				$this->options[] = $options;
				return $this->findings[$mapping['form']] ?? [];
			}
		);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => $this->breaks[$app] ?? $default
		);
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	private function check(): FormDependentsCheck {
		return new FormDependentsCheck(
			new FormDependentsSignals($this->dispatcher, $this->notifications),
			$this->validator,
			$this->appConfig,
			$this->logger
		);
	}//end check()

	private function schema(): Schema {
		$schema = new Schema();
		$schema->setSlug('ticket');
		return $schema;
	}//end schema()

	private function form(string $id, string $app, ?string $author = 'alice', ?\Closure $unpublish = null, ?string $audience = null): FormDependent {
		return new FormDependent($id, $app, 'Title '.$id, $author, ['form' => $id], $unpublish, $audience);
	}//end form()

	public function testAClaimIsRememberedPerSchemaId(): void {
		$check = $this->check();
		$check->claim(4);

		$this->assertTrue($check->isClaimed(4));
		$this->assertFalse($check->isClaimed(5));
	}//end testAClaimIsRememberedPerSchemaId()

	public function testOnlyFormsWithFindingsAreAffectedAndTheBreakDefaultsToUnpublish(): void {
		$this->forms = [
			$this->form('clean', 'pipelinq'),
			$this->form('broken', 'pipelinq', audience: 'public'),
			$this->form('odd-setting', 'odd'),
		];
		$this->findings = ['broken' => [['code' => 'x']], 'odd-setting' => [['code' => 'y']]];
		$this->breaks = ['odd' => 'something-else'];

		$assessment = $this->check()->assess($this->schema());

		$this->assertFalse($assessment['refused']);
		$this->assertSame('ticket', $assessment['schema']);
		$this->assertSame(['broken', 'odd-setting'], array_column($assessment['affected'], 'id'));
		$this->assertSame(['unpublish', 'unpublish'], array_column($assessment['affected'], 'break'));
		$this->assertSame([['code' => 'x']], $assessment['affected'][0]['findings']);
		$this->assertSame('Title broken', $assessment['affected'][0]['title']);
		$this->assertSame([[], ['audience' => 'public'], []], $this->options);
	}//end testOnlyFormsWithFindingsAreAffectedAndTheBreakDefaultsToUnpublish()

	public function testOneAppThatRefusesRefusesTheWholeSaveAndNothingIsUnpublished(): void {
		$unpublished = [];
		$this->forms = [
			$this->form('a', 'pipelinq', unpublish: function () use (&$unpublished): void {
				$unpublished[] = 'a';
			}),
			$this->form('b', 'strict'),
		];
		$this->findings = ['a' => [['code' => 'x']], 'b' => [['code' => 'y']]];
		$this->breaks = ['strict' => 'refuse'];
		$this->notifications->expects($this->never())->method('notify');
		$check = $this->check();

		$assessment = $check->assess($this->schema());
		$listed = $check->apply($assessment);

		$this->assertTrue($assessment['refused']);
		$this->assertSame(['unpublish', 'refuse'], array_column($assessment['affected'], 'break'));
		$this->assertSame(['refused', 'refused'], array_column($listed, 'outcome'));
		$this->assertSame(['pipelinq', 'strict'], array_column($listed, 'app'));
		$this->assertSame([], $unpublished);
	}//end testOneAppThatRefusesRefusesTheWholeSaveAndNothingIsUnpublished()

	public function testAnUnpublishedFormNotifiesItsAuthor(): void {
		$unpublished = [];
		$this->forms = [$this->form('a', 'pipelinq', 'alice', function () use (&$unpublished): void {
			$unpublished[] = 'a';
		})];
		$this->findings = ['a' => [['code' => 'x'], ['code' => 'y']]];
		$notification = $this->createMock(INotification::class);
		$notification->expects($this->once())->method('setApp')->with('openregister')->willReturnSelf();
		$notification->expects($this->once())->method('setUser')->with('alice')->willReturnSelf();
		$notification->expects($this->once())->method('setDateTime')->willReturnSelf();
		$notification->expects($this->once())->method('setObject')->with('form', 'a')->willReturnSelf();
		$notification->expects($this->once())->method('setSubject')
			->with('form_unpublished', ['formTitle' => 'Title a', 'schema' => 'ticket', 'count' => 2])
			->willReturnSelf();
		$this->notifications->method('createNotification')->willReturn($notification);
		$this->notifications->expects($this->once())->method('notify')->with($notification);
		$check = $this->check();

		$listed = $check->apply($check->assess($this->schema()));

		$this->assertSame(['a'], $unpublished);
		$this->assertSame(
			[['id' => 'a', 'app' => 'pipelinq', 'title' => 'Title a', 'outcome' => 'unpublished', 'findings' => [['code' => 'x'], ['code' => 'y']]]],
			$listed
		);
	}//end testAnUnpublishedFormNotifiesItsAuthor()

	public function testAnUnpublishedFormWithoutAnAuthorNotifiesNobody(): void {
		$this->forms = [
			$this->form('a', 'pipelinq', null, static function (): void {
			}),
			$this->form('b', 'pipelinq', '', static function (): void {
			}),
		];
		$this->findings = ['a' => [['code' => 'x']], 'b' => [['code' => 'x']]];
		$this->notifications->expects($this->never())->method('createNotification');
		$check = $this->check();

		$listed = $check->apply($check->assess($this->schema()));

		$this->assertSame(['unpublished', 'unpublished'], array_column($listed, 'outcome'));
	}//end testAnUnpublishedFormWithoutAnAuthorNotifiesNobody()

	public function testAFormWithoutAnUnpublishClosureStaysPublishedAndIsLogged(): void {
		$this->forms = [$this->form('a', 'pipelinq')];
		$this->findings = ['a' => [['code' => 'x']]];
		$this->logger->expects($this->once())->method('warning')->with(
			'[FormDependentsCheck] A form broken by a schema change has no way to be unpublished; it stays published',
			['form' => 'a', 'app' => 'pipelinq', 'schema' => 'ticket']
		);
		$this->notifications->expects($this->never())->method('notify');
		$check = $this->check();

		$listed = $check->apply($check->assess($this->schema()));

		$this->assertSame('unpublish-unavailable', $listed[0]['outcome']);
	}//end testAFormWithoutAnUnpublishClosureStaysPublishedAndIsLogged()

	public function testAFailingUnpublishIsLoggedAndTheAuthorIsNotTold(): void {
		$this->forms = [$this->form('a', 'pipelinq', 'alice', static function (): void {
			throw new RuntimeException('app down');
		})];
		$this->findings = ['a' => [['code' => 'x']]];
		$this->logger->expects($this->once())->method('error')->with(
			'[FormDependentsCheck] An owning app could not unpublish a form broken by a schema change',
			['form' => 'a', 'app' => 'pipelinq', 'schema' => 'ticket', 'exception' => 'app down']
		);
		$this->notifications->expects($this->never())->method('notify');
		$check = $this->check();

		$listed = $check->apply($check->assess($this->schema()));

		$this->assertSame('unpublish-failed', $listed[0]['outcome']);
	}//end testAFailingUnpublishIsLoggedAndTheAuthorIsNotTold()

	public function testNoDependentsMeansNothingAffectedAndNothingRefused(): void {
		$check = $this->check();
		$assessment = $check->assess($this->schema());

		$this->assertSame(['refused' => false, 'schema' => 'ticket', 'affected' => []], $assessment);
		$this->assertSame([], $check->apply($assessment));
	}//end testNoDependentsMeansNothingAffectedAndNothingRefused()
}//end class
