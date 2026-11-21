<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\File;

use OCA\OpenRegister\Service\File\AntivirusScan;
use OCA\OpenRegister\Service\File\AntivirusScannerAdapter;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The files_antivirus adapter over stubs whose methods mirror the real ones.
 *
 * @covers \OCA\OpenRegister\Service\File\AntivirusScannerAdapter
 * @covers \OCA\OpenRegister\Service\File\AntivirusScan
 */
final class AntivirusScannerAdapterTest extends TestCase {

	/**
	 * A status as files_antivirus's Status answers it.
	 */
	private static function engineStatus(int $code, string $details): object {
		return new class ($code, $details) {
			public function __construct(private readonly int $code, private readonly string $details) {
			}

			public function getNumericStatus(): int {
				return $this->code;
			}

			public function getDetails(): string {
				return $this->details;
			}
		};
	}

	/**
	 * A factory whose getScanner() takes the path, as newer releases do.
	 */
	private static function factory(object $status, ?array &$asked=null): object {
		$asked ??= [];
		$scanner = new class ($status) {
			public array $scanned = [];

			public function __construct(private readonly object $status) {
			}

			public function scanString(string $data): object {
				$this->scanned[] = $data;
				return $this->status;
			}
		};

		return new class ($scanner) {
			public array $paths = [];

			public function __construct(public readonly object $scanner) {
			}

			public function getScanner(string $path): object {
				$this->paths[] = $path;
				return $this->scanner;
			}
		};
	}

	private function adapter(bool $enabled, ?object $factory=null, string $mode='daemon', ?LoggerInterface $logger=null): AntivirusScannerAdapter {
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isEnabledForAnyone')->with('files_antivirus')->willReturn($enabled);
		$container = $this->createMock(ContainerInterface::class);
		if ($factory === null) {
			$container->method('get')->willThrowException(new RuntimeException('no ScannerFactory'));
		} else {
			$container->method('get')->with(AntivirusScannerAdapter::FACTORY)->willReturn($factory);
		}

		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->with('files_antivirus', 'av_mode', '')->willReturn($mode);

		return new AntivirusScannerAdapter($apps, $container, $config, ($logger ?? $this->createMock(LoggerInterface::class)));
	}

	public function testADisabledAppIsUnavailableWithoutTouchingTheContainer(): void {
		$scan = $this->adapter(enabled: false)->scan('a.pdf', '%PDF');

		$this->assertSame(AntivirusScan::UNAVAILABLE, $scan->status);
		$this->assertSame('files_antivirus is not installed and enabled', $scan->finding);
	}

	public function testACleanStatusIsCleanAndNamesTheEngine(): void {
		$factory = self::factory(self::engineStatus(0, ''));
		$scan    = $this->adapter(enabled: true, factory: $factory)->scan('a.pdf', '%PDF');

		$this->assertSame(AntivirusScan::CLEAN, $scan->status);
		$this->assertSame('files_antivirus:daemon', $scan->engine);
		$this->assertSame(['a.pdf'], $factory->paths, 'the path is named to getScanner()');
		$this->assertSame(['%PDF'], $factory->scanner->scanned);
	}

	public function testAnInfectedStatusCarriesTheFinding(): void {
		$scan = $this->adapter(enabled: true, factory: self::factory(self::engineStatus(1, 'Eicar-Signature')), mode: '')->scan('e.com', 'x');

		$this->assertSame(AntivirusScan::INFECTED, $scan->status);
		$this->assertSame('Eicar-Signature', $scan->finding);
		$this->assertSame('files_antivirus', $scan->engine);
	}

	public function testAnyOtherStatusIsUnchecked(): void {
		$this->assertSame(AntivirusScan::UNCHECKED, $this->adapter(enabled: true, factory: self::factory(self::engineStatus(-1, '')))->scan('a', 'x')->status);
		$this->assertSame(AntivirusScan::UNCHECKED, $this->adapter(enabled: true, factory: self::factory(self::engineStatus(2, '')))->scan('a', 'x')->status);
	}

	public function testAFactoryTakingNoPathIsAskedWithout(): void {
		$scanner = new class () {
			public function scanString(string $data): object {
				return new class () {
					public function getNumericStatus(): int {
						return 0;
					}

					public function getDetails(): string {
						return '';
					}
				};
			}
		};
		$factory = new class ($scanner) {
			public function __construct(private readonly object $scanner) {
			}

			public function getScanner(): object {
				return $this->scanner;
			}
		};

		$this->assertSame(AntivirusScan::CLEAN, $this->adapter(enabled: true, factory: $factory)->scan('a', 'x')->status);
	}

	public function testAScannerThatThrowsIsUnavailableAndLogged(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error')->with($this->stringContains('files_antivirus could not scan "a.pdf": no ScannerFactory'));

		$scan = $this->adapter(enabled: true, logger: $logger)->scan('a.pdf', '%PDF');

		$this->assertSame(AntivirusScan::UNAVAILABLE, $scan->status);
		$this->assertSame('no ScannerFactory', $scan->finding);
	}
}
