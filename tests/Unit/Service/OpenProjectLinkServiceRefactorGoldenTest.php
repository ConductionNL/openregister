<?php

/**
 * OpenProjectLinkServiceRefactorGoldenTest
 *
 * Golden pins for OpenProjectLinkService: picker normalisation of every
 * response envelope, create-and-link payloads and failures, link hydration and
 * the stale-cache refresh path. The expected values were recorded from the
 * service before it was split into smaller units.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Service\LinkServiceRuntime;
use DateTime;
use Exception;
use OCA\OpenRegister\Db\OpenProjectLink;
use OCA\OpenRegister\Db\OpenProjectLinkMapper;
use OCA\OpenRegister\Exception\ProviderUnavailableException;
use OCA\OpenRegister\Service\Integration\ExternalIntegrationRouter;
use OCA\OpenRegister\Service\Integration\Providers\OpenProjectProvider;
use OCA\OpenRegister\Service\LinkTarget;
use OCA\OpenRegister\Service\OpenProjectLinkService;
use OCP\App\IAppManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Service\OpenProjectLinkService
 */
class OpenProjectLinkServiceRefactorGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/openproject-link-service-golden.json';

	private OpenProjectLinkMapper&MockObject $mapper;

	private OpenProjectProvider&MockObject $provider;

	private ExternalIntegrationRouter&MockObject $router;

	private OpenProjectLinkService $service;

	/** @var array<int, array> */
	private array $calls = [];

	private function build(bool $connector = true): void {
		$this->calls = [];
		$this->mapper = $this->getMockBuilder(OpenProjectLinkMapper::class)
			->disableOriginalConstructor()
			->onlyMethods(['findByObjectUuid', 'findByObjectAndWorkPackage', 'deleteByObjectAndWorkPackage', 'insert', 'update'])
			->getMock();
		$this->mapper->method('insert')->willReturnCallback(static fn (OpenProjectLink $link): OpenProjectLink => $link);
		$this->provider = $this->createMock(OpenProjectProvider::class);
		$this->router = $this->createMock(ExternalIntegrationRouter::class);
		$appManager = $this->createMock(IAppManager::class);
		$match = static fn (string $id): bool => ($connector === true && $id === 'openconnector');
		$appManager->method('isInstalled')->willReturnCallback($match);
		$appManager->method('isEnabledForUser')->willReturnCallback($match);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$this->service = new OpenProjectLinkService(
			$this->mapper,
			$this->provider,
			$this->router,
			new LinkServiceRuntime(
				$appManager,
				$session,
				$this->createMock(LoggerInterface::class)
			)
		);
	}//end build()

	private static function link(OpenProjectLink $link): array {
		$row = $link->jsonSerialize();
		unset($row['linkedAt'], $row['cachedAt'], $row['id'], $row['uuid']);
		$row['cachedAtSet'] = ($link->getCachedAt() !== null);
		return $row;
	}//end link()

	private function outcome(callable $act): array {
		try {
			$value = $act();
			if ($value instanceof OpenProjectLink) {
				$value = self::link(link: $value);
			}

			return ['value' => $value, 'calls' => $this->calls];
		} catch (Exception $e) {
			return ['error' => [get_class($e), $e->getMessage(), $e->getCode()], 'calls' => $this->calls];
		}
	}//end outcome()

	public static function pickerResponses(): array {
		$hal = [
			'id' => 7,
			'subject' => '',
			'status' => '',
			'_links' => ['status' => ['title' => 'Linked status'], 'type' => ['title' => ''], 'self' => ['href' => '/wp/7']],
			'_embedded' => ['type' => ['name' => 'Embedded type'], 'priority' => ['name' => 'High'], 'assignee' => ['name' => 7]],
		];
		return [
			'results' => [['results' => [['id' => 1, 'subject' => 'One'], 'skip-me', ['reference' => 2, 'title' => 'Two']]]],
			'items' => [['items' => [['id' => 3, 'name' => 'Three', 'project' => 'P', 'url' => 'u3']]]],
			'embedded elements' => [['_embedded' => ['elements' => [$hal]]]],
			'embedded without elements' => [['_embedded' => ['a' => ['id' => 4], 'b' => 'x']]],
			'embedded elements not array' => [['_embedded' => ['elements' => 'nope', 'c' => ['id' => 5]]]],
			'elements' => [['elements' => [['id' => 6]]]],
			'results not array falls to items' => [['results' => 'x', 'items' => [['id' => 8]]]],
			'empty results wins' => [['results' => [], 'items' => [['id' => 9]]]],
			'plain list' => [[['id' => 10, 'subject' => 'Ten'], ['id' => 11]]],
			'assoc without envelope' => [['foo' => ['id' => 12]]],
			'empty' => [[]],
		];
	}//end pickerResponses()

	private function observePicker(array $response, ?string $search): array {
		$this->build();
		$this->router->method('call')->willReturnCallback(
			function (...$args) use ($response) {
				$this->calls[] = ['method' => $args[1], 'path' => $args[2], 'options' => $args[3]];
				return $response;
			}
		);
		return $this->outcome(fn () => $this->service->getAvailableWorkPackages($search));
	}//end observePicker()

	private function observeCreate(array|Exception $created, string $project, string $subject, string $type): array {
		$this->build();
		$this->provider->method('create')->willReturnCallback(
			function (...$args) use ($created) {
				$this->calls[] = $args;
				if ($created instanceof Exception) {
					throw $created;
				}

				return $created;
			}
		);
		$target = (new LinkTarget('obj-1', 3))->inSchema(4);
		return $this->outcome(fn () => $this->service->createAndLinkWorkPackage($target, $project, $subject, $type));
	}//end observeCreate()

	private function observeLink(bool $connector, array|Exception|null $row): array {
		$this->build(connector: $connector);
		$this->mapper->method('findByObjectAndWorkPackage')->willReturn(null);
		$this->provider->method('get')->willReturnCallback(
			function (...$args) use ($row) {
				$this->calls[] = $args;
				if ($row instanceof Exception) {
					throw $row;
				}

				return $row;
			}
		);
		$target = (new LinkTarget('obj-1', 3))->inSchema(4);
		return $this->outcome(fn () => $this->service->linkWorkPackage($target, 77));
	}//end observeLink()

	private static function storedLink(?DateTime $cachedAt): OpenProjectLink {
		$link = new OpenProjectLink();
		$link->setObjectUuid('obj-1');
		$link->setRegisterId(3);
		$link->setSchemaId(null);
		$link->setWorkPackageId(77);
		$link->setSubject('Old subject');
		$link->setStatus('Old status');
		$link->setUrl('old-url');
		$link->setCachedAt($cachedAt);
		return $link;
	}//end storedLink()

	private function observeRefresh(bool $connector, ?DateTime $cachedAt, array|Exception|null $row, bool $updateFails): array {
		$this->build(connector: $connector);
		$this->mapper->method('findByObjectUuid')->willReturn([self::storedLink(cachedAt: $cachedAt)]);
		$this->provider->method('get')->willReturnCallback(
			function (...$args) use ($row) {
				$this->calls[] = $args;
				if ($row instanceof Exception) {
					throw $row;
				}

				return $row;
			}
		);
		$this->mapper->method('update')->willReturnCallback(
			function (OpenProjectLink $link) use ($updateFails) {
				$this->calls[] = 'update';
				if ($updateFails === true) {
					throw new RuntimeException('db down');
				}

				$link->setPriority('from-update');
				return $link;
			}
		);
		return $this->outcome(
			fn () => array_map(
				static function (array $row): array {
					$row['cachedAtSet'] = ($row['cachedAt'] !== null);
					unset($row['linkedAt'], $row['cachedAt'], $row['id'], $row['uuid']);
					return $row;
				},
				$this->service->getLinkedWorkPackages('obj-1')
			)
		);
	}//end observeRefresh()

	private function observeAll(): array {
		$out = [];
		foreach (self::pickerResponses() as $name => [$response]) {
			$out['picker: ' . $name] = $this->observePicker(response: $response, search: null);
		}

		$out['picker search'] = $this->observePicker(response: [], search: 'abc');
		$out['picker empty search'] = $this->observePicker(response: [], search: '');
		$this->build();
		$this->router->method('call')->willThrowException(new ProviderUnavailableException('not configured', 'unconfigured'));
		$out['picker unavailable'] = $this->outcome(fn () => $this->service->getAvailableWorkPackages());

		$full = ['id' => 42, 'subject' => 'S', 'type' => 'T', 'status' => 'N', 'priority' => '  ', 'assignee' => 'A', 'project' => 'P', 'url' => 'U'];
		$out['create full'] = $this->observeCreate(created: $full, project: ' 5 ', subject: ' Ship ', type: 'Task');
		$out['create no type'] = $this->observeCreate(created: ['reference' => '43'], project: '5', subject: 'Ship', type: '');
		$out['create no id'] = $this->observeCreate(created: ['subject' => 'x'], project: '5', subject: 'Ship', type: '');
		$out['create blank subject'] = $this->observeCreate(created: $full, project: '5', subject: '  ', type: '');
		$out['create blank project'] = $this->observeCreate(created: $full, project: ' ', subject: 'Ship', type: '');
		$out['create throws'] = $this->observeCreate(created: new RuntimeException('boom'), project: '5', subject: 'Ship', type: '');
		$out['create unavailable'] = $this->observeCreate(
			created: new ProviderUnavailableException('reconnect', 'unauthorized'),
			project: '5',
			subject: 'Ship',
			type: ''
		);

		$out['link with info'] = $this->observeLink(connector: true, row: $full);
		$out['link source throws'] = $this->observeLink(connector: true, row: new RuntimeException('down'));
		$out['link no connector'] = $this->observeLink(connector: false, row: $full);

		$stale = new DateTime('2000-01-01T00:00:00+00:00');
		$out['refresh updates'] = $this->observeRefresh(connector: true, cachedAt: $stale, row: ['id' => 77, 'status' => 'Done'], updateFails: false);
		$out['refresh update fails'] = $this->observeRefresh(connector: true, cachedAt: null, row: ['id' => 77, 'url' => 'new-url'], updateFails: true);
		$out['refresh source throws'] = $this->observeRefresh(connector: true, cachedAt: $stale, row: new RuntimeException('x'), updateFails: false);
		$out['refresh fresh'] = $this->observeRefresh(connector: true, cachedAt: new DateTime('+1 day'), row: ['id' => 77], updateFails: false);
		$out['refresh no connector'] = $this->observeRefresh(connector: false, cachedAt: $stale, row: ['id' => 77], updateFails: false);

		return json_decode(json_encode($out), true);
	}//end observeAll()

	public function testOutcomesMatchGolden(): void {
		$this->assertSame(json_decode((string)file_get_contents(self::FIXTURE), true), $this->observeAll());
	}//end testOutcomesMatchGolden()
}//end class
