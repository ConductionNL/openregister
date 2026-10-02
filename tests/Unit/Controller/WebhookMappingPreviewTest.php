<?php

/**
 * The webhook dialog previews the payload a delivery would send (REQ-WHMAP-001).
 *
 * The REAL controller over the REAL WebhookService and MappingService; only the
 * mappers and Nextcloud's session and groups are doubles. The preview is
 * compared with what the delivery path builds, not with a hand-written copy.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/webhook-payload-mapping/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\WebhooksController;
use OCA\OpenRegister\Db\Mapping;
use OCA\OpenRegister\Db\MappingMapper;
use OCA\OpenRegister\Db\Webhook;
use OCA\OpenRegister\Db\WebhookLogMapper;
use OCA\OpenRegister\Db\WebhookMapper;
use OCA\OpenRegister\Service\MappingService;
use OCA\OpenRegister\Service\WebhookService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\BackgroundJob\IJobList;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @covers \OCA\OpenRegister\Controller\WebhooksController
 * @covers \OCA\OpenRegister\Service\WebhookService
 */
class WebhookMappingPreviewTest extends TestCase {

	private IRequest&MockObject $request;

	private WebhookService $service;

	/**
	 * The controller for an administrator or not.
	 *
	 * @param bool $admin Whether the caller is an administrator.
	 *
	 * @return WebhooksController
	 */
	private function controller(bool $admin): WebhooksController {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('caller');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturn($admin);

		return new WebhooksController(
			'openregister',
			$this->request,
			$this->createMock(WebhookMapper::class),
			$this->createMock(WebhookLogMapper::class),
			$this->service,
			new NullLogger(),
			$session,
			$groups
		);
	}//end controller()

	protected function setUp(): void {
		$this->request = $this->createMock(IRequest::class);

		$mapping = new Mapping();
		$mapping->hydrate(['name' => 'to-zgw-notification', 'mapping' => json_encode(['kenmerk' => 'objectUuid', 'actie' => 'action', 'soort' => 'event'])]);
		$mappingMapper = $this->createMock(MappingMapper::class);
		$mappingMapper->method('find')->willReturnCallback(
			function ($id) use ($mapping): Mapping {
				if ((int) $id !== 5) {
					throw new DoesNotExistException('no mapping '.$id);
				}

				return $mapping;
			}
		);

		$cacheFactory = $this->createMock(ICacheFactory::class);
		$cacheFactory->method('createDistributed')->willReturn($this->createMock(ICache::class));

		$logger = new NullLogger();
		$this->service = new WebhookService(
			webhookMapper: $this->createMock(WebhookMapper::class),
			logger: $logger,
			webhookLogMapper: $this->createMock(WebhookLogMapper::class),
			mappingService: new MappingService($mappingMapper, $cacheFactory, $logger),
			mappingMapper: $mappingMapper,
			jobList: $this->createMock(IJobList::class)
		);
	}//end setUp()

	/**
	 * The preview of a mapped webhook is the mapped payload, as the delivery builds it.
	 */
	public function testThePreviewIsWhatTheDeliverySends(): void {
		$this->request->method('getParams')->willReturn(
			['mapping' => 5, 'event' => 'OCA\OpenRegister\Event\ObjectCreatedEvent', 'payload' => ['objectUuid' => 'abc', 'action' => 'create']]
		);

		$response = $this->controller(true)->preview();

		$this->assertSame(200, $response->getStatus(), json_encode($response->getData()));
		$data = $response->getData();
		$this->assertTrue($data['mapped']);
		$this->assertSame(['kenmerk' => 'abc', 'actie' => 'create', 'soort' => 'ObjectCreatedEvent'], $data['payload']);

		// The same webhook, delivered: the body buildPayload() produces.
		$webhook = new Webhook();
		$webhook->setMapping(5);
		$build = new \ReflectionMethod(WebhookService::class, 'buildPayload');
		$delivered = $build->invoke($this->service, $webhook, 'OCA\OpenRegister\Event\ObjectCreatedEvent', ['objectUuid' => 'abc', 'action' => 'create'], 1);
		$this->assertSame($delivered, $data['payload']);
	}//end testThePreviewIsWhatTheDeliverySends()

	/**
	 * A mapping that does not exist says so rather than showing the fallback as mapped.
	 */
	public function testAMissingMappingIsNotShownAsMapped(): void {
		$this->request->method('getParams')->willReturn(['mapping' => 99]);

		$data = $this->controller(true)->preview()->getData();

		$this->assertFalse($data['mapped']);
		$this->assertArrayHasKey('payload', $data);
	}//end testAMissingMappingIsNotShownAsMapped()

	/**
	 * Only an administrator previews.
	 */
	public function testANonAdministratorIsRefused(): void {
		$this->request->method('getParams')->willReturn(['mapping' => 5]);

		$this->assertSame(403, $this->controller(false)->preview()->getStatus());
	}//end testANonAdministratorIsRefused()

	/**
	 * Saving a webhook with mapping null clears it; leaving the key out keeps it.
	 */
	public function testANullMappingClearsItAndAnAbsentOneKeepsIt(): void {
		$webhook = new Webhook();
		$webhook->setMapping(5);

		$webhook->hydrate(['name' => 'hook']);
		$this->assertSame(5, $webhook->getMapping());

		$webhook->hydrate(['mapping' => null]);
		$this->assertNull($webhook->getMapping());
	}//end testANullMappingClearsItAndAnAbsentOneKeepsIt()
}//end class
