<?php

/**
 * Unit tests for the schema events and defaults of the notifications annotation installer.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Notification
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Notification;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\Webhook;
use OCA\OpenRegister\Db\WebhookMapperInterface;
use OCA\OpenRegister\Event\SchemaCreatedEvent;
use OCA\OpenRegister\Event\SchemaUpdatedEvent;
use OCA\OpenRegister\Service\Notification\NotificationsAnnotationInstaller;
use OCP\EventDispatcher\Event;
use OCP\IGroupManager;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Which schema each event installs, which configurations install nothing, and the payload defaults.
 *
 * @covers \OCA\OpenRegister\Service\Notification\NotificationsAnnotationInstaller
 * @uses \OCA\OpenRegister\Db\Schema
 * @uses \OCA\OpenRegister\Db\Schema\ConfigurationEntryRules
 * @uses \OCA\OpenRegister\Db\Schema\ConfigurationValidator
 * @uses \OCA\OpenRegister\Db\Webhook
 * @uses \OCA\OpenRegister\Event\AbstractSchemaEvent
 * @uses \OCA\OpenRegister\Kernel\Contracts\ListQuery
 * @uses \OCA\OpenRegister\Event\SchemaUpdatedEvent
 */
class NotificationsAnnotationInstallerEventsTest extends TestCase {

	/**
	 * The webhook mapper, mocked.
	 *
	 * @var WebhookMapperInterface&MockObject
	 */
	private WebhookMapperInterface&MockObject $mapper;

	/**
	 * Build the shared mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->mapper = $this->createMock(WebhookMapperInterface::class);

	}//end setUp()

	/**
	 * The installer under test.
	 *
	 * @param LoggerInterface|null $logger The logger.
	 *
	 * @return NotificationsAnnotationInstaller
	 */
	private function installer(?LoggerInterface $logger = null): NotificationsAnnotationInstaller {
		return new NotificationsAnnotationInstaller($this->mapper, $logger ?? $this->createMock(LoggerInterface::class), $this->createMock(IUserSession::class), $this->createMock(IGroupManager::class));

	}//end installer()

	/**
	 * A schema with the given slug and notifications configuration.
	 *
	 * @param string|null $slug          The slug.
	 * @param mixed       $notifications The x-openregister-notifications value.
	 *
	 * @return Schema
	 */
	private function schema(?string $slug, mixed $notifications): Schema {
		$schema = new Schema();
		$schema->setId(9);
		$schema->setSlug($slug);
		$schema->setConfiguration(['x-openregister-notifications' => $notifications]);

		return $schema;

	}//end schema()

	/**
	 * One persistent webhook notification.
	 *
	 * @param array<string, mixed> $hook Extra webhook keys.
	 *
	 * @return array<string, mixed>
	 */
	private function persistent(array $hook = []): array {
		return ['channels' => ['webhook'], 'webhook' => ['persistent' => true, 'url' => 'https://example.com/h'] + $hook];

	}//end persistent()

	/**
	 * Capture the payload of the one create call.
	 *
	 * @return \ArrayObject<string, mixed> Filled when the create happens.
	 */
	private function captureCreate(): \ArrayObject {
		$captured = new \ArrayObject();
		$this->mapper->expects($this->once())
			->method('createFromArray')
			->willReturnCallback(
				static function (array $payload) use ($captured): Webhook {
					$captured->exchangeArray($payload);
					return new Webhook();
				}
			);

		return $captured;

	}//end captureCreate()

	/**
	 * A created schema installs its own notifications.
	 *
	 * @return void
	 */
	public function testACreatedSchemaIsInstalled(): void {
		$this->mapper->method('findAll')->willReturn([]);
		$captured = $this->captureCreate();

		$this->installer()->handle(new SchemaCreatedEvent($this->schema('case', ['opened' => $this->persistent()])));

		$this->assertSame('or-notif-case-opened', $captured['name']);

	}//end testACreatedSchemaIsInstalled()

	/**
	 * An updated schema installs the NEW schema's notifications, not the old one's.
	 *
	 * @return void
	 */
	public function testAnUpdatedSchemaInstallsTheNewVersion(): void {
		$this->mapper->method('findAll')->willReturn([]);
		$captured = $this->captureCreate();

		$this->installer()->handle(
			new SchemaUpdatedEvent(
				$this->schema('case', ['closed' => $this->persistent()]),
				$this->schema('case', ['opened' => $this->persistent()])
			)
		);

		$this->assertSame('or-notif-case-closed', $captured['name']);

	}//end testAnUpdatedSchemaInstallsTheNewVersion()

	/**
	 * Any other event installs nothing.
	 *
	 * @return void
	 */
	public function testAnUnrelatedEventInstallsNothing(): void {
		$this->mapper->expects($this->never())->method('findAll');
		$this->mapper->expects($this->never())->method('createFromArray');

		$this->installer()->handle(new Event());

	}//end testAnUnrelatedEventInstallsNothing()

	/**
	 * A configuration without a notifications map installs nothing.
	 *
	 * @return void
	 */
	public function testANonArrayNotificationsBlockInstallsNothing(): void {
		$this->mapper->expects($this->never())->method('findAll');
		$this->mapper->expects($this->never())->method('createFromArray');

		$this->installer()->installSchema($this->schema('case', 'webhook'));

		$schema = new Schema();
		$schema->setConfiguration(null);
		$this->installer()->installSchema($schema);

	}//end testANonArrayNotificationsBlockInstallsNothing()

	/**
	 * A malformed notification is skipped and the next one still installs.
	 *
	 * @return void
	 */
	public function testAMalformedEntryIsSkipped(): void {
		$this->mapper->method('findAll')->willReturn([]);
		$captured = $this->captureCreate();

		$this->installer()->installSchema($this->schema('case', ['broken' => 'yes', 'ok' => $this->persistent()]));

		$this->assertSame('or-notif-case-ok', $captured['name']);

	}//end testAMalformedEntryIsSkipped()

	/**
	 * A schema without a slug names the webhook by its id, and the payload takes its defaults.
	 *
	 * @return void
	 */
	public function testDefaultsAndTheIdFallback(): void {
		$this->mapper->method('findAll')->willReturn([]);
		$captured = $this->captureCreate();

		$this->installer()->installSchema($this->schema(null, ['n' => $this->persistent(['secret' => '', 'method' => 'put'])]));

		$this->assertSame('or-notif-9-n', $captured['name']);
		$this->assertSame('PUT', $captured['method']);
		$this->assertSame(json_encode(['ObjectUpdatedEvent', 'ObjectTransitionedEvent']), $captured['events']);
		$this->assertNull($captured['headers']);
		$this->assertNull($captured['secret'], 'an empty secret is no secret');
		$this->assertSame(5, $captured['timeout']);

	}//end testDefaultsAndTheIdFallback()

	/**
	 * A failing lookup is treated as not-found, so the webhook is created.
	 *
	 * @return void
	 */
	public function testAFailingLookupFallsThroughToCreate(): void {
		$this->mapper->method('findAll')->willThrowException(new RuntimeException('db'));
		$this->mapper->expects($this->never())->method('updateFromArray');
		$captured = $this->captureCreate();
		$logger   = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('info')->with('[NotificationsAnnotationInstaller] created Webhook "or-notif-case-n"');
		$logger->expects($this->never())->method('warning');

		$this->installer($logger)->installSchema($this->schema('case', ['n' => $this->persistent()]));

		$this->assertSame('https://example.com/h', $captured['url']);

	}//end testAFailingLookupFallsThroughToCreate()
}//end class
