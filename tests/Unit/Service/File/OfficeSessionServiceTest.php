<?php

/**
 * The Office token OpenRegister issues: owner, editor, write flag, refusals.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\File
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/object-files-follow-object-access/specs/file-actions/spec.md#requirement-nextcloud-office-opens-an-objects-document-by-the-object-rule-req-ofoa-006
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\File;

use OCA\OpenRegister\Exception\OfficeOpenRefusedException;
use OCA\OpenRegister\Service\File\OfficeSessionService;
use OCP\App\IAppManager;
use OCP\Files\File;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Stand-ins with the richdocuments method shapes the service calls.
 */
class OfficeSessionServiceTest extends TestCase {

	/**
	 * The arguments the last token was made with.
	 *
	 * @var array<int, mixed>
	 */
	public array $tokenArgs = [];

	private function service(bool $enabled, bool $userCanEdit = true, ?string $urlSrc = 'https://office.test/cool.html?'): OfficeSessionService {
		$test = $this;
		$tokenManager = new class($urlSrc) {
			public function __construct(private ?string $urlSrc) {
			}

			public function getUrlSrcForMimeType(string $type): ?string {
				return $this->urlSrc;
			}
		};
		$permissionManager = new class($userCanEdit) {
			public function __construct(private bool $canEdit) {
			}

			public function isEnabledForUser(?string $uid = null): bool {
				return true;
			}

			public function userCanEdit(?string $uid = null): bool {
				return $this->canEdit;
			}
		};
		$wopiMapper = new class($test) {
			public function __construct(private OfficeSessionServiceTest $test) {
			}

			public function generateFileToken(...$args): object {
				$this->test->tokenArgs = $args;
				return new class {
					public function getToken(): string {
						return 'tok';
					}

					public function getExpiry(): int {
						return 100;
					}
				};
			}
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static fn (string $id): object => match ($id) {
				'OCA\\Richdocuments\\TokenManager' => $tokenManager,
				'OCA\\Richdocuments\\PermissionManager' => $permissionManager,
				'OCA\\Richdocuments\\Db\\WopiMapper' => $wopiMapper,
			}
		);

		$apps = $this->createMock(IAppManager::class);
		$apps->method('isEnabledForAnyone')->willReturn($enabled);
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('getAbsoluteURL')->willReturn('https://nc.test/');
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueString')->willReturn('ocinst');
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('');

		return new OfficeSessionService($apps, $container, $urls, $config, $appConfig);
	}//end service()

	private function file(): File {
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn(77);
		$file->method('getMimeType')->willReturn('application/vnd.oasis.opendocument.text');
		return $file;
	}//end file()

	public function testTheTokenIsOwnedByOpenRegisterAndNamesTheEditor(): void {
		$session = $this->service(enabled: true)->open($this->file(), 'behandelaar', 'Behandelaar', true);

		$this->assertSame('77', $this->tokenArgs[0]);
		$this->assertSame('openregister', $this->tokenArgs[1]);
		$this->assertSame('behandelaar', $this->tokenArgs[2]);
		$this->assertTrue($this->tokenArgs[4]);
		// A display name makes richdocuments read the file through the owner's home.
		$this->assertSame('Behandelaar', $this->tokenArgs[6]);
		$this->assertFalse($session->readOnly);
		$this->assertSame('https://nc.test/index.php/apps/richdocuments/wopi/files/77_ocinst', $session->wopiSrc);
		$this->assertSame(100000, $session->tokenTtl);
	}//end testTheTokenIsOwnedByOpenRegisterAndNamesTheEditor()

	public function testAReaderGetsAReadOnlyToken(): void {
		$session = $this->service(enabled: true)->open($this->file(), 'lezer', 'Lezer', false);

		$this->assertFalse($this->tokenArgs[4]);
		$this->assertTrue($session->readOnly);
	}//end testAReaderGetsAReadOnlyToken()

	public function testTheOfficeEditSettingStillApplies(): void {
		$session = $this->service(enabled: true, userCanEdit: false)->open($this->file(), 'behandelaar', 'Behandelaar', true);

		$this->assertTrue($session->readOnly);
	}//end testTheOfficeEditSettingStillApplies()

	public function testWithoutOfficeTheAnswerIs409(): void {
		$this->expectException(OfficeOpenRefusedException::class);
		$this->expectExceptionCode(409);

		$this->service(enabled: false)->open($this->file(), 'behandelaar', 'Behandelaar', true);
	}//end testWithoutOfficeTheAnswerIs409()

	public function testAFileTypeOfficeCannotOpenIs415(): void {
		$this->expectException(OfficeOpenRefusedException::class);
		$this->expectExceptionCode(415);

		$this->service(enabled: true, urlSrc: null)->open($this->file(), 'behandelaar', 'Behandelaar', true);
	}//end testAFileTypeOfficeCannotOpenIs415()
}//end class
