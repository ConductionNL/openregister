<?php

/**
 * An import of an unchanged schema still installs what the schema declares.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Configuration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Configuration;

use GuzzleHttp\Client;
use OCA\OpenRegister\Db\ConfigurationMapper;
use OCA\OpenRegister\Db\Flow;
use OCA\OpenRegister\Db\FlowMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\MappingMapper;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\Webhook;
use OCA\OpenRegister\Db\WebhookMapper;
use OCA\OpenRegister\Listener\SchemaFlowImportListener;
use OCA\OpenRegister\Service\Configuration\ImportHandler;
use OCA\OpenRegister\Service\Configuration\SchemaImportInstaller;
use OCA\OpenRegister\Service\Configuration\UploadHandler;
use OCA\OpenRegister\Service\Notification\NotificationsAnnotationInstaller;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionProperty;

/**
 * The import calls the flow and webhook installers itself when its saves fired
 * no SchemaUpdatedEvent, and leaves them to the listeners when one fired.
 *
 * The installers are the real listener classes over doubled stores, so the
 * tests show a flow and a webhook actually being written.
 *
 * @spec openspec/specs/event-driven-architecture/spec.md#requirement-an-import-of-an-unchanged-schema-still-installs-what-it-declares
 */
class ImportHandlerUnchangedSchemaInstallsTest extends TestCase {
	/**
	 * Flows the flow store received.
	 *
	 * @var array<int, Flow>
	 */
	private array $insertedFlows = [];

	/**
	 * Webhooks the webhook store received.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $createdWebhooks = [];

	/**
	 * A stored lead schema declaring one flow and one persistent webhook notification.
	 *
	 * @return Schema The schema.
	 */
	private function storedLead(): Schema {
		$schema = new Schema();
		(new ReflectionProperty($schema, 'id'))->setValue($schema, 30);
		$schema->setSlug('lead');
		$schema->setTitle('Lead');
		$schema->setVersion('1.0.0');
		$schema->setProperties(['title' => ['type' => 'string']]);
		$schema->setConfiguration(
			[
				'x-openregister-flows' => [
					['name' => 'Qualify', 'trigger' => 'object.created', 'nodes' => [['id' => 'a']], 'edges' => []],
				],
				'x-openregister-notifications' => [
					'leadWon' => [
						'channels' => ['webhook'],
						'webhook' => ['persistent' => true, 'url' => 'https://example.com/won', 'events' => ['ObjectUpdatedEvent']],
					],
				],
			]
		);

		return $schema;
	}//end storedLead()

	/**
	 * The real installer over doubled flow and webhook stores.
	 *
	 * @param bool $flowStoreFails Whether the flow store throws on insert.
	 *
	 * @return SchemaImportInstaller The installer.
	 */
	private function installer(bool $flowStoreFails = false): SchemaImportInstaller {
		$flows = $this->createMock(FlowMapper::class);
		$flows->method('findAllFlows')->willReturn([]);
		$flows->method('insert')->willReturnCallback(
			function (Flow $flow) use ($flowStoreFails): Flow {
				if ($flowStoreFails === true) {
					throw new \RuntimeException('flow store down');
				}

				$this->insertedFlows[] = $flow;
				return $flow;
			}
		);

		$webhooks = $this->createMock(WebhookMapper::class);
		$webhooks->method('findAll')->willReturn([]);
		$webhooks->method('createFromArray')->willReturnCallback(
			function (array $payload): Webhook {
				$this->createdWebhooks[] = $payload;
				return new Webhook();
			}
		);

		return new SchemaImportInstaller(
			flows: new SchemaFlowImportListener($flows, new NullLogger(), null),
			notifications: new NotificationsAnnotationInstaller($webhooks, new NullLogger()),
			logger: new NullLogger()
		);
	}//end installer()

	/**
	 * An import handler whose schema mapper reports the given update event counts.
	 *
	 * @param Schema $stored The stored schema.
	 * @param array<int, int> $eventCounts What updateEventCount() answers, call by call.
	 * @param SchemaImportInstaller|null $installer The installer.
	 *
	 * @return ImportHandler The handler.
	 */
	private function handler(Schema $stored, array $eventCounts, ?SchemaImportInstaller $installer): ImportHandler {
		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('find')->willReturn($stored);
		$schemaMapper->method('updateFromArray')->willReturn($stored);
		$schemaMapper->method('update')->willReturnArgument(0);
		$schemaMapper->method('updateEventCount')->willReturnOnConsecutiveCalls(...$eventCounts);

		return new ImportHandler(
			schemaMapper: $schemaMapper,
			registerMapper: $this->createMock(RegisterMapper::class),
			objectEntityMapper: $this->createMock(MagicMapper::class),
			configurationMapper: $this->createMock(ConfigurationMapper::class),
			mappingMapper: $this->createMock(MappingMapper::class),
			client: $this->createMock(Client::class),
			appConfig: $this->createMock(IAppConfig::class),
			logger: new NullLogger(),
			appDataPath: '/tmp',
			uploadHandler: $this->createMock(UploadHandler::class),
			objectService: $this->createMock(ObjectService::class),
			schemaInstaller: $installer
		);
	}//end handler()

	/**
	 * The schema data an app import hands over: the stored schema, unchanged.
	 *
	 * @param Schema $stored The stored schema.
	 *
	 * @return array<string, mixed> The import data.
	 */
	private function importData(Schema $stored): array {
		return [
			'slug' => 'lead',
			'title' => 'Lead',
			'version' => '1.0.0',
			'properties' => $stored->getProperties(),
			'configuration' => $stored->getConfiguration(),
		];
	}//end importData()

	/**
	 * No event fired, so the import installs the declared flow and webhook itself.
	 *
	 * @return void
	 */
	public function testUnchangedImportInstallsTheDeclaredFlowAndWebhook(): void {
		$stored = $this->storedLead();
		$handler = $this->handler(stored: $stored, eventCounts: [0, 0], installer: $this->installer());

		$handler->importSchema(data: $this->importData(stored: $stored), slugsAndIdsMap: []);

		$this->assertCount(1, $this->insertedFlows);
		$this->assertSame('Qualify', $this->insertedFlows[0]->getName());
		$this->assertCount(1, $this->createdWebhooks);
		$this->assertSame('https://example.com/won', $this->createdWebhooks[0]['url']);
	}//end testUnchangedImportInstallsTheDeclaredFlowAndWebhook()

	/**
	 * An event fired, so the listeners already ran: the import installs nothing twice.
	 *
	 * @return void
	 */
	public function testChangedImportLeavesInstallingToTheListeners(): void {
		$stored = $this->storedLead();
		$handler = $this->handler(stored: $stored, eventCounts: [0, 1], installer: $this->installer());

		$handler->importSchema(data: $this->importData(stored: $stored), slugsAndIdsMap: []);

		$this->assertSame([], $this->insertedFlows);
		$this->assertSame([], $this->createdWebhooks);
	}//end testChangedImportLeavesInstallingToTheListeners()

	/**
	 * A failing flow store neither fails the import nor stops the webhook.
	 *
	 * @return void
	 */
	public function testAFailingInstallerDoesNotStopTheOtherOrTheImport(): void {
		$stored = $this->storedLead();
		$handler = $this->handler(stored: $stored, eventCounts: [0, 0], installer: $this->installer(flowStoreFails: true));

		$result = $handler->importSchema(data: $this->importData(stored: $stored), slugsAndIdsMap: []);

		$this->assertSame(30, $result->getId());
		$this->assertCount(1, $this->createdWebhooks);
	}//end testAFailingInstallerDoesNotStopTheOtherOrTheImport()
}//end class
