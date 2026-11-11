<?php

/**
 * Resolution edges of {@see \OCA\OpenRegister\Service\ApiVersion\ApiVersionCatalogue}
 * that ApiVersionCatalogueTest leaves open: an unreadable configuration, an
 * empty or non-list value, which supported version is current, memoisation,
 * and inspecting a proposed declaration without applying it.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\ApiVersion
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ApiVersion;

use OCA\OpenRegister\Service\ApiVersion\ApiVersionCatalogue;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * ApiVersionCatalogueResolutionTest.
 */
class ApiVersionCatalogueResolutionTest extends TestCase {
	private IAppConfig&MockObject $appConfig;
	private LoggerInterface&MockObject $logger;

	protected function setUp(): void {
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}

	private function catalogue(): ApiVersionCatalogue {
		return new ApiVersionCatalogue($this->appConfig, $this->logger);
	}

	private function configured(array|string $value): void {
		$this->appConfig->method('getValueString')->with('openregister', 'api_versions', '')
			->willReturn(is_string($value) === true ? $value : (string) json_encode($value));
	}

	public function testAnUnreadableConfigurationIsLoggedAndTheBuiltInContractServed(): void {
		$this->appConfig->method('getValueString')->willThrowException(new RuntimeException('db'));
		$this->logger->expects($this->once())->method('warning')
			->with('OpenRegister: could not read the API version configuration; using the built-in contract.');

		$catalogue = $this->catalogue();

		$this->assertSame(['1'], $catalogue->identifiers());
		$this->assertSame([], $catalogue->rejections());
	}

	public function testAnEmptyConfigurationIsTheBuiltInContractWithoutRejections(): void {
		$this->configured('   ');
		$this->logger->expects($this->never())->method('error');

		$this->assertSame([], $this->catalogue()->rejections());
		$this->assertSame('1', $this->catalogue()->current()->id);
	}

	public function testAJsonScalarIsRejectedAsNotAList(): void {
		$this->configured('"2"');

		$this->assertSame(
			['*' => 'The api_versions configuration is not a JSON list of version declarations.'],
			$this->catalogue()->rejections()
		);
	}

	public function testTheCurrentVersionIsTheHighestSupportedNotTheHighestServed(): void {
		$this->configured([
			['id' => '10', 'status' => 'supported'],
			['id' => '9', 'status' => 'supported'],
			['id' => '11', 'status' => 'withdrawn', 'successor' => '10'],
		]);
		$catalogue = $this->catalogue();

		$this->assertSame('10', $catalogue->current()->id);
		$this->assertSame(['9', '10', '11'], $catalogue->identifiers());
		$this->assertSame(['9', '10'], $catalogue->servedIdentifiers());
		$this->assertSame('9', $catalogue->get(' 9 ')->id);
	}

	public function testTheDeclarationIsReadOnce(): void {
		$this->appConfig->expects($this->once())->method('getValueString')->willReturn('');
		$catalogue = $this->catalogue();

		$catalogue->all();
		$catalogue->rejections();
		$catalogue->served();
	}

	public function testRejectionsResolveTheCatalogueWhenAskedFirst(): void {
		$this->configured([['id' => '2', 'status' => 'withdrawn']]);
		$this->logger->expects($this->once())->method('error');

		$rejections = $this->catalogue()->rejections();

		$this->assertSame('No version is supported; a caller naming no version would have nothing to be answered with.', $rejections['*']);
	}

	public function testInspectReportsNonObjectEntriesAndASuccessorThatIsNotServed(): void {
		$rejections = $this->catalogue()->inspect([
			'not an object',
			['id' => '1', 'status' => 'deprecated', 'deprecatedOn' => '2026-09-01', 'sunset' => '2027-03-01', 'successor' => '2'],
			['id' => '2', 'status' => 'withdrawn'],
			['id' => '3', 'status' => 'supported'],
		]);

		$this->assertSame('Every entry must be a version declaration object.', $rejections['*']);
		$this->assertSame('Its successor 2 is not a version this instance serves.', $rejections['1']);
	}

	public function testInspectOfAUsableSetReportsNothing(): void {
		$this->assertSame([], $this->catalogue()->inspect([['id' => '1', 'status' => 'supported']]));
	}
}
