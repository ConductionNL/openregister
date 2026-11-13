<?php

/**
 * OfficeSessionService: the Office address a file list offers.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/changes/object-files-follow-object-access/tasks.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\File;

use OCA\OpenRegister\Files\Service\File\OfficeSessionService;
use OCA\OpenRegister\Files\Service\File\OfficeSessionSettings;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * officeUrl is offered only for the types Office opens, asked once per type.
 */
class OfficeSessionServiceOfficeUrlTest extends TestCase {

	/**
	 * The MIME types the token manager was asked about.
	 *
	 * @var list<string>
	 */
	public array $asked = [];

	/**
	 * The routes the URL generator built.
	 *
	 * @var list<array{0: string, 1: array<string, mixed>}>
	 */
	public array $routes = [];

	/**
	 * A service whose Office opens text documents only.
	 *
	 * @param bool $enabled  Whether richdocuments is enabled.
	 * @param bool $resolves Whether richdocuments' services resolve.
	 *
	 * @return OfficeSessionService
	 */
	private function service(bool $enabled = true, bool $resolves = true): OfficeSessionService {
		$test = $this;
		$tokenManager = new class($test) {
			public function __construct(private OfficeSessionServiceOfficeUrlTest $test) {
			}

			public function getUrlSrcForMimeType(string $type): ?string {
				$this->test->asked[] = $type;
				return $type === 'application/vnd.oasis.opendocument.text' ? 'https://office.test/cool.html?' : null;
			}
		};
		$other = new class {
			public function userCanEdit(): bool {
				return true;
			}

			public function generateFileToken(): object {
				return new \stdClass();
			}
		};
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($tokenManager, $other, $resolves): object {
				if ($resolves === false) {
					throw new RuntimeException('not installed');
				}

				return $id === 'OCA\\Richdocuments\\TokenManager' ? $tokenManager : $other;
			}
		);
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isEnabledForAnyone')->willReturn($enabled);
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('linkToRoute')->willReturnCallback(
			function (string $route, array $parameters) use ($test): string {
				$test->routes[] = [$route, $parameters];
				return '/apps/openregister/office/' . implode('/', $parameters);
			}
		);

		return new OfficeSessionService(
			appManager: $apps,
			container: $container,
			urlGenerator: $urls,
			settings: new OfficeSessionSettings(config: $this->createMock(IConfig::class), appConfig: $this->createMock(IAppConfig::class))
		);
	}//end service()

	/**
	 * A type Office opens gets the office page of that object and file.
	 *
	 * @return void
	 */
	public function testATypeOfficeOpensGetsTheOfficePage(): void {
		$url = $this->service()->officePageUrl(register: 'zaken', schema: 'zaak', objectId: 'obj-1', fileId: 77, mimeType: 'application/vnd.oasis.opendocument.text');

		$this->assertSame('/apps/openregister/office/zaken/zaak/obj-1/77', $url);
		$this->assertSame([['openregister.files.officePage', ['register' => 'zaken', 'schema' => 'zaak', 'id' => 'obj-1', 'fileId' => 77]]], $this->routes);
	}//end testATypeOfficeOpensGetsTheOfficePage()

	/**
	 * Another type, or no type, gets none.
	 *
	 * @return void
	 */
	public function testAnotherTypeGetsNone(): void {
		$service = $this->service();

		$this->assertNull($service->officePageUrl(register: 'zaken', schema: 'zaak', objectId: 'obj-1', fileId: 78, mimeType: 'image/png'));
		$this->assertNull($service->officePageUrl(register: 'zaken', schema: 'zaak', objectId: 'obj-1', fileId: 79, mimeType: ''));
		$this->assertSame([], $this->routes);
		$this->assertSame(['image/png'], $this->asked, 'an empty type is not asked about');
	}//end testAnotherTypeGetsNone()

	/**
	 * Each type is asked about once, however many files carry it.
	 *
	 * @return void
	 */
	public function testEachTypeIsAskedOnce(): void {
		$service = $this->service();

		foreach (['application/vnd.oasis.opendocument.text', 'image/png', 'application/vnd.oasis.opendocument.text', 'image/png'] as $type) {
			$service->opensInOffice(mimeType: $type);
		}

		$this->assertSame(['application/vnd.oasis.opendocument.text', 'image/png'], $this->asked);
	}//end testEachTypeIsAskedOnce()

	/**
	 * Without Office, or when its services do not resolve, nothing opens in it.
	 *
	 * @return void
	 */
	public function testWithoutOfficeNothingOpens(): void {
		$this->assertFalse($this->service(enabled: false)->opensInOffice(mimeType: 'application/vnd.oasis.opendocument.text'));
		$this->assertFalse($this->service(resolves: false)->opensInOffice(mimeType: 'application/vnd.oasis.opendocument.text'));
		$this->assertNull($this->service(enabled: false)->officePageUrl(register: 'r', schema: 's', objectId: 'o', fileId: 1, mimeType: 'application/vnd.oasis.opendocument.text'));
		$this->assertSame([], $this->asked);
	}//end testWithoutOfficeNothingOpens()
}//end class
