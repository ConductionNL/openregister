<?php

/**
 * OfficeSessionAccessGoldenTest
 *
 * Golden for who may open a document in Nextcloud Office through
 * OfficeSessionService::open(): allowed and refused outcomes, the write
 * flag on the token and the name the editor is shown under.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\File;

use OCA\OpenRegister\Exception\OfficeOpenRefusedException;
use OCA\OpenRegister\Files\Service\File\OfficeOpenRequest;
use OCA\OpenRegister\Files\Service\File\OfficeSessionService;
use OCA\OpenRegister\Files\Service\File\OfficeSessionSettings;
use OCP\App\IAppManager;
use OCP\Files\File;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * Allowed and refused outcomes of {@see OfficeSessionService::open()}.
 */
class OfficeSessionAccessGoldenTest extends TestCase {

	/**
	 * Arguments of the last token request, or null when none was made.
	 *
	 * @var array<int, mixed>|null
	 */
	public ?array $tokenArgs = null;

	/**
	 * A service whose Office permission manager answers as given.
	 *
	 * @param bool $officeForUser Whether the user may use Office at all.
	 * @param bool $userCanEdit   Whether Office lets the user edit.
	 * @param bool $tokenFails    Whether issuing the token throws.
	 *
	 * @return OfficeSessionService
	 */
	private function service(bool $officeForUser, bool $userCanEdit, bool $tokenFails = false): OfficeSessionService {
		$test = $this;
		$tokenManager = new class {
			public function getUrlSrcForMimeType(string $type): ?string {
				return 'https://office.test/cool.html?';
			}
		};
		$permissionManager = new class($officeForUser, $userCanEdit) {
			public function __construct(private bool $forUser, private bool $canEdit) {
			}

			public function isEnabledForUser(?string $uid = null): bool {
				return $this->forUser;
			}

			public function userCanEdit(?string $uid = null): bool {
				return $this->canEdit;
			}
		};
		$wopiMapper = new class($test, $tokenFails) {
			public function __construct(private OfficeSessionAccessGoldenTest $test, private bool $fails) {
			}

			public function generateFileToken(...$args): object {
				if ($this->fails === true) {
					throw new RuntimeException('db down');
				}

				$this->test->tokenArgs = $args;
				return new class {
					public function getToken(): string {
						return 'tok';
					}

					public function getExpiry(): int {
						return 5;
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
		$apps->method('isEnabledForAnyone')->willReturn(true);
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('getAbsoluteURL')->willReturn('https://nc.test/');
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueString')->willReturn('inst');
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('https://wopi.test/');

		return new OfficeSessionService(
			appManager: $apps,
			container: $container,
			urlGenerator: $urls,
			settings: new OfficeSessionSettings(config: $config, appConfig: $appConfig)
		);
	}//end service()

	/**
	 * A document file.
	 *
	 * @return File
	 */
	private function file(): File {
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn(9);
		$file->method('getMimeType')->willReturn('application/vnd.oasis.opendocument.text');
		return $file;
	}//end file()

	/**
	 * A user Office refuses gets a 403 and no token is issued, whatever write right they carry.
	 *
	 * @return void
	 */
	public function testAUserOfficeRefusesGetsNoToken(): void {
		foreach ([true, false] as $canWrite) {
			try {
				$this->service(officeForUser: false, userCanEdit: true)->openSession(new OfficeOpenRequest($this->file(), 'gast', 'Gast', $canWrite));
				self::fail('Expected a refusal');
			} catch (OfficeOpenRefusedException $e) {
				self::assertSame(OfficeOpenRefusedException::NOT_PERMITTED, $e->getCode());
			}
		}

		self::assertNull($this->tokenArgs);
	}//end testAUserOfficeRefusesGetsNoToken()

	/**
	 * Write access needs both the caller's write right and Office's edit right.
	 *
	 * @return void
	 */
	public function testWriteNeedsBothRights(): void {
		$cases = [
			[true, true, true],
			[true, false, false],
			[false, true, false],
			[false, false, false],
		];
		foreach ($cases as [$canWrite, $userCanEdit, $writable]) {
			$session = $this->service(officeForUser: true, userCanEdit: $userCanEdit)->openSession(new OfficeOpenRequest($this->file(), 'u', 'U', $canWrite));
			self::assertSame($writable, $this->tokenArgs[4]);
			self::assertSame(($writable === false), $session->readOnly);
		}
	}//end testWriteNeedsBothRights()

	/**
	 * Without a display name the editor is shown by uid; the token carries the full session shape.
	 *
	 * @return void
	 */
	public function testGuestNameFallsBackToTheUid(): void {
		$session = $this->service(officeForUser: true, userCanEdit: true)->openSession(new OfficeOpenRequest($this->file(), 'jdoe', '', true));

		self::assertSame(['9', 'openregister', 'jdoe', '0', true, 'https://nc.test/', 'jdoe'], $this->tokenArgs);
		self::assertSame('https://office.test/cool.html?', $session->urlSrc);
		self::assertSame('https://wopi.test/index.php/apps/richdocuments/wopi/files/9_inst', $session->wopiSrc);
		self::assertSame('tok', $session->token);
		self::assertSame(5000, $session->tokenTtl);
	}//end testGuestNameFallsBackToTheUid()

	/**
	 * A token that cannot be issued is a 503-style refusal carrying the cause.
	 *
	 * @return void
	 */
	public function testATokenFailureIsUnavailable(): void {
		try {
			$this->service(officeForUser: true, userCanEdit: true, tokenFails: true)->openSession(new OfficeOpenRequest($this->file(), 'u', 'U', true));
			self::fail('Expected a refusal');
		} catch (OfficeOpenRefusedException $e) {
			self::assertSame(OfficeOpenRefusedException::UNAVAILABLE, $e->getCode());
			self::assertInstanceOf(RuntimeException::class, $e->getPrevious());
		}
	}//end testATokenFailureIsUnavailable()
}//end class
