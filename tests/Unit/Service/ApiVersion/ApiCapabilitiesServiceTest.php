<?php

/**
 * ApiCapabilitiesServiceTest — the split, and the numbers.
 *
 * Two things are worth failing over. The public half must name no register and
 * no schema, ever, because it is readable by anyone and a register name says
 * what a municipality keeps records about. And every published number must
 * come from the constant that enforces it: a page size the capabilities answer
 * invents is worse than no published page size, because an integrator sizes
 * against it once and never checks again.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\ApiVersion
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/changes/api-as-a-versioned-surface/specs/api-surface-governance/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ApiVersion;

use OCA\OpenRegister\Service\ApiVersion\ApiCapabilitiesService;
use OCA\OpenRegister\Service\ApiVersion\ApiVersionCatalogue;
use OCA\OpenRegister\Service\Object\QueryHandler;
use OCA\OpenRegister\Service\SecurityService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\Service\ApiVersion\ApiCapabilitiesService
 */
class ApiCapabilitiesServiceTest extends TestCase {

	/**
	 * Build the service over a declaration and a set of switch states.
	 *
	 * @param string $declaration The raw api_versions value.
	 * @param bool $switchState What every operational switch reports.
	 *
	 * @return ApiCapabilitiesService The service.
	 */
	private function service(string $declaration = '', bool $switchState = true): ApiCapabilitiesService {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn($declaration);
		$appConfig->method('getValueBool')->willReturn($switchState);

		$security = $this->createMock(SecurityService::class);
		$security->method('describeAuthRateLimit')->willReturn(
			[
				'attemptsPerIdentity' => 20,
				'attemptsPerAddress' => 100,
				'windowSeconds' => 900,
				'lockoutSeconds' => 900,
			]
		);

		$catalogue = new ApiVersionCatalogue($appConfig, $this->createMock(LoggerInterface::class));

		return new ApiCapabilitiesService($catalogue, $security, $appConfig);
	}//end service()

	public function testTheUnauthenticatedAnswerCarriesTheVersionsAndTheLimits(): void {
		$published = $this->service()->publicCapabilities();

		$this->assertSame('1', $published['currentVersion']);
		$this->assertSame('API-Version', $published['versionHeader']);
		$this->assertSame([['version' => '1', 'status' => 'supported', 'description' => ApiVersionCatalogue::BUILT_IN[0]['description']]], $published['apiVersions']);
		$this->assertArrayHasKey('uploadBytes', $published['limits']);
		$this->assertArrayHasKey('pageSize', $published['limits']);
	}//end testTheUnauthenticatedAnswerCarriesTheVersionsAndTheLimits()

	public function testTheUnauthenticatedAnswerNamesNoRegisterNoSchemaAndNoSwitch(): void {
		$published = $this->service()->publicCapabilities();

		$this->assertArrayNotHasKey('features', $published);

		// The app's own id appears in the contract link path, and `openregister`
		// contains `register`. Strip the app id first: a path segment naming the
		// application is not the name of a register this gemeente holds.
		$serialised = str_replace('openregister', '', strtolower(json_encode($published)));
		foreach (['register', 'schema', 'tenant', 'organisation'] as $forbidden) {
			$this->assertStringNotContainsString(
				$forbidden,
				$serialised,
				'The public answer is readable by anyone; it must not say what this municipality keeps.'
			);
		}
	}//end testTheUnauthenticatedAnswerNamesNoRegisterNoSchemaAndNoSwitch()

	public function testTheSessionAnswerAddsTheOperationalSwitches(): void {
		$published = $this->service(switchState: true)->sessionCapabilities();

		$this->assertArrayHasKey('features', $published);
		$this->assertTrue($published['features']['enforceDefaultClosed']);
		$this->assertTrue($published['features']['flowKillSwitch']);
		$this->assertArrayHasKey('limits', $published, 'The session answer is the public one plus the switches, not a different answer.');
	}//end testTheSessionAnswerAddsTheOperationalSwitches()

	public function testTheSessionAnswerSurfacesARefusedDeclaration(): void {
		$published = $this->service(
			declaration: json_encode(
				[
					['id' => '1', 'status' => 'supported'],
					['id' => '2', 'status' => 'deprecated'],
				]
			)
		)->sessionCapabilities();

		$this->assertArrayHasKey(
			'versionDeclarationRejections',
			$published,
			'A silent fallback leaves an administrator reading a set that is not theirs.'
		);
		$this->assertArrayHasKey('2', $published['versionDeclarationRejections']);
	}//end testTheSessionAnswerSurfacesARefusedDeclaration()

	public function testThePublishedPageSizeIsTheOneTheQueryHandlerApplies(): void {
		$limits = $this->service()->limits();

		$this->assertSame(QueryHandler::MAX_PAGE_SIZE, $limits['pageSize']['maximum']);
		$this->assertSame(QueryHandler::DEFAULT_PAGE_SIZE, $limits['pageSize']['default']);
	}//end testThePublishedPageSizeIsTheOneTheQueryHandlerApplies()

	public function testEachServedVersionGetsAContractLink(): void {
		$published = $this->service(
			declaration: json_encode(
				[
					['id' => '1', 'status' => 'deprecated', 'sunset' => '2027-03-01', 'successor' => '2'],
					['id' => '2', 'status' => 'supported'],
				]
			)
		)->publicCapabilities();

		$this->assertSame(
			[
				'1' => '/apps/openregister/api/versions/1/oas',
				'2' => '/apps/openregister/api/versions/2/oas',
			],
			$published['contracts']
		);
	}//end testEachServedVersionGetsAContractLink()

	public function testAWithdrawnVersionIsListedButGetsNoContractLink(): void {
		$published = $this->service(
			declaration: json_encode(
				[
					['id' => '1', 'status' => 'withdrawn', 'successor' => '2'],
					['id' => '2', 'status' => 'supported'],
				]
			)
		)->publicCapabilities();

		$this->assertSame(['2'], array_map('strval', array_keys($published['contracts'])));
		$this->assertContains('1', array_column($published['apiVersions'], 'version'));
	}//end testAWithdrawnVersionIsListedButGetsNoContractLink()

	/**
	 * @dataProvider provideSizeShorthands
	 */
	public function testPhpSizeShorthandIsParsed(string $value, ?int $expected): void {
		$this->assertSame($expected, ApiCapabilitiesService::toBytes(value: $value));
	}//end testPhpSizeShorthandIsParsed()

	public static function provideSizeShorthands(): array {
		return [
			'megabytes' => ['512M', (512 * 1024 * 1024)],
			'lowercase megabytes' => ['512m', (512 * 1024 * 1024)],
			'kilobytes' => ['2K', 2048],
			'gigabytes' => ['1G', (1024 * 1024 * 1024)],
			'plain bytes' => ['1048576', 1048576],
			'unlimited' => ['0', 0],
			'empty' => ['', null],
			'nonsense' => ['plenty', null],
		];
	}//end provideSizeShorthands()

	public function testTheUploadCeilingIsTheSmallerOfTheTwoDirectives(): void {
		$ceiling = $this->service()->uploadCeilingBytes();

		if ($ceiling === null) {
			$this->assertNull($ceiling, 'Neither directive bounds the upload on this runtime.');
			return;
		}

		$upload = ApiCapabilitiesService::toBytes(value: (string)ini_get('upload_max_filesize'));
		$post = ApiCapabilitiesService::toBytes(value: (string)ini_get('post_max_size'));
		$bounding = array_filter([$upload, $post], static fn (?int $value): bool => ($value !== null && $value > 0));

		$this->assertSame(
			min($bounding),
			$ceiling,
			'A caller is stopped by whichever directive is lower; naming the higher one is worse than naming none.'
		);
	}//end testTheUploadCeilingIsTheSmallerOfTheTwoDirectives()
}//end class
