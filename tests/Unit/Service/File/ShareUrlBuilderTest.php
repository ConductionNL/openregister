<?php

/**
 * ShareUrlBuilderTest
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/openregister
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\File;

use OCA\OpenRegister\Db\FileMapper;
use OCA\OpenRegister\Service\File\FileOwnershipHandler;
use OCA\OpenRegister\Service\File\FileSharingHandler;
use OCA\OpenRegister\Service\File\ShareUrlBuilder;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\Share\IManager;
use OCP\Share\IShare;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * A file link points at the host the visitor used, or at `overwrite.cli.url`
 * when there is no request, and never at an arbitrary `trusted_domains` entry.
 *
 * The URL generator is modelled on Nextcloud's contract: during a request
 * `getBaseUrl()` and `getAbsoluteURL()` answer with the request's scheme and
 * host; without one the request host falls back to `localhost`.
 *
 * @spec openspec/specs/file-actions/spec.md#requirement-a-file-link-points-at-the-host-the-visitor-used
 */
class ShareUrlBuilderTest extends TestCase {

	/**
	 * The development instance's trusted domains: `nextcloud` is the Docker
	 * service name, which no browser can resolve.
	 */
	private const DEV_TRUSTED = ['localhost', 'nextcloud'];

	/**
	 * Build a URL generator that answers for one request base.
	 *
	 * @param string $base Scheme, host and port of the request, e.g. `http://localhost:8080`.
	 *
	 * @return IURLGenerator
	 */
	private function urlGeneratorFor(string $base): IURLGenerator {
		$generator = $this->createMock(IURLGenerator::class);
		$generator->method('getBaseUrl')->willReturn($base);
		$generator->method('getAbsoluteURL')->willReturnCallback(
			static fn (string $url): string => $base . '/' . ltrim($url, '/')
		);
		return $generator;
	}//end urlGeneratorFor()

	/**
	 * Build a system config with the given trusted domains and CLI URL.
	 *
	 * @param array<int, string> $trustedDomains The `trusted_domains` list.
	 * @param string $cliUrl The `overwrite.cli.url` value.
	 *
	 * @return IConfig
	 */
	private function configWith(array $trustedDomains, string $cliUrl = ''): IConfig {
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValue')->willReturnCallback(
			static fn (string $key, mixed $default = ''): mixed => match ($key) {
				'trusted_domains' => $trustedDomains,
				'overwrite.cli.url' => $cliUrl,
				default => $default,
			}
		);
		$config->method('getSystemValueString')->willReturnCallback(
			static fn (string $key, string $default = ''): string => match ($key) {
				'overwrite.cli.url' => $cliUrl,
				default => $default,
			}
		);
		return $config;
	}//end configWith()

	/**
	 * The share link the sharing handler hands out for token `abc123`.
	 *
	 * @param ShareUrlBuilder $builder The builder under test.
	 *
	 * @return string
	 */
	private function handlerLink(ShareUrlBuilder $builder): string {
		$handler = new FileSharingHandler(
			shareManager: $this->createMock(IManager::class),
			userManager: $this->createMock(IUserManager::class),
			shareUrls: $builder,
			logger: $this->createMock(LoggerInterface::class),
			fileOwnershipHandler: $this->createMock(FileOwnershipHandler::class),
		);
		$share = $this->createMock(IShare::class);
		$share->method('getToken')->willReturn('abc123');

		return $handler->getShareLink(share: $share);
	}//end handlerLink()

	/**
	 * A visitor on localhost gets a localhost link, not the Docker service name.
	 *
	 * @return void
	 */
	public function testRequestOnLocalhostGivesALocalhostLink(): void {
		$builder = new ShareUrlBuilder(
			urlGenerator: $this->urlGeneratorFor('http://localhost:8080'),
			config: $this->configWith(self::DEV_TRUSTED),
			sapi: 'apache2handler',
		);

		$this->assertSame('http://localhost:8080/index.php/s/abc123', $this->handlerLink($builder));
	}//end testRequestOnLocalhostGivesALocalhostLink()

	/**
	 * A visitor on zuiddrecht.nl gets a zuiddrecht.nl link, whatever host the
	 * CLI URL names.
	 *
	 * @return void
	 */
	public function testRequestOnThePublicHostGivesThatHost(): void {
		$builder = new ShareUrlBuilder(
			urlGenerator: $this->urlGeneratorFor('https://zuiddrecht.nl'),
			config: $this->configWith(
				['localhost', 'nextcloud', 'cloud.zuiddrecht.nl', 'zuiddrecht.nl'],
				'https://cloud.zuiddrecht.nl'
			),
			sapi: 'fpm-fcgi',
		);

		$this->assertSame('https://zuiddrecht.nl/index.php/s/abc123', $this->handlerLink($builder));
	}//end testRequestOnThePublicHostGivesThatHost()

	/**
	 * Without a request (cron, occ) the link uses `overwrite.cli.url`.
	 *
	 * @return void
	 */
	public function testNoRequestUsesTheCliUrl(): void {
		$builder = new ShareUrlBuilder(
			urlGenerator: $this->urlGeneratorFor('http://localhost'),
			config: $this->configWith(self::DEV_TRUSTED, 'https://cloud.zuiddrecht.nl/'),
			sapi: 'cli',
		);

		$this->assertSame('https://cloud.zuiddrecht.nl/index.php/s/abc123', $this->handlerLink($builder));
	}//end testNoRequestUsesTheCliUrl()

	/**
	 * Without a request and without a CLI URL the generator's own base is used.
	 *
	 * @return void
	 */
	public function testNoRequestWithoutCliUrlFallsBackToTheGeneratorBase(): void {
		$builder = new ShareUrlBuilder(
			urlGenerator: $this->urlGeneratorFor('http://localhost'),
			config: $this->configWith(self::DEV_TRUSTED),
			sapi: 'cli',
		);

		$this->assertSame('http://localhost/index.php/s/abc123', $builder->shareLink(token: 'abc123'));
	}//end testNoRequestWithoutCliUrlFallsBackToTheGeneratorBase()

	/**
	 * Orderings of the trusted domains, none of which may change the link.
	 *
	 * @return array<string, array{0: array<int, string>}>
	 */
	public static function trustedDomainOrderings(): array {
		return [
			'localhost first' => [['localhost', 'nextcloud', 'zuiddrecht.nl']],
			'service name first' => [['nextcloud', 'localhost', 'zuiddrecht.nl']],
			'public host second' => [['localhost', 'zuiddrecht.nl', 'nextcloud']],
			'only one entry' => [['localhost']],
			'empty' => [[]],
		];
	}//end trustedDomainOrderings()

	/**
	 * The order of `trusted_domains` never decides the host of a link.
	 *
	 * @param array<int, string> $trustedDomains The trusted domain list.
	 *
	 * @return void
	 */
	#[DataProvider('trustedDomainOrderings')]
	public function testTrustedDomainOrderingIsIrrelevant(array $trustedDomains): void {
		$builder = new ShareUrlBuilder(
			urlGenerator: $this->urlGeneratorFor('http://localhost:8080'),
			config: $this->configWith($trustedDomains),
			sapi: 'apache2handler',
		);

		$this->assertSame('http://localhost:8080/index.php/s/abc123', $this->handlerLink($builder));
	}//end testTrustedDomainOrderingIsIrrelevant()

	/**
	 * The file listing's share, preview and download links follow the same rule:
	 * the request host during a request, the CLI URL without one.
	 *
	 * @return void
	 */
	public function testFileMapperLinksFollowTheSameRule(): void {
		$web = new FileMapper(
			db: $this->createMock(IDBConnection::class),
			shareUrls: new ShareUrlBuilder(
				urlGenerator: $this->urlGeneratorFor('https://zuiddrecht.nl'),
				config: $this->configWith(self::DEV_TRUSTED, 'https://cloud.zuiddrecht.nl'),
				sapi: 'fpm-fcgi',
			),
		);
		$cron = new FileMapper(
			db: $this->createMock(IDBConnection::class),
			shareUrls: new ShareUrlBuilder(
				urlGenerator: $this->urlGeneratorFor('http://localhost'),
				config: $this->configWith(self::DEV_TRUSTED, 'https://cloud.zuiddrecht.nl'),
				sapi: 'cli',
			),
		);

		$share = new ReflectionMethod(FileMapper::class, 'generateShareUrl');
		$download = new ReflectionMethod(FileMapper::class, 'generateAuthenticatedDownloadUrl');
		$preview = new ReflectionMethod(FileMapper::class, 'generateAuthenticatedAccessUrl');

		$this->assertSame('https://zuiddrecht.nl/index.php/s/abc123', $share->invoke($web, 'abc123'));
		$this->assertSame(
			'https://zuiddrecht.nl/index.php/apps/openregister/api/files/42/download',
			$download->invoke($web, 42)
		);
		$this->assertSame('https://cloud.zuiddrecht.nl/index.php/s/abc123', $share->invoke($cron, 'abc123'));
		$this->assertSame(
			'https://cloud.zuiddrecht.nl/index.php/core/preview?fileId=42&x=1920&y=1080&a=1',
			$preview->invoke($cron, 42)
		);
	}//end testFileMapperLinksFollowTheSameRule()
}//end class
