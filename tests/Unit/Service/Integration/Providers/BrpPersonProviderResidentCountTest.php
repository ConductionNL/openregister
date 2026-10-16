<?php

/**
 * Unit tests for BrpPersonProvider::countResidentsAtAddressObject().
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Integration\Providers
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/integration-person-lookup/spec.md#requirement-brp-count-of-residents-at-an-address-object
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Integration\Providers;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- test method names say what each proves; matches BrpPersonProviderTest.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers take positional args by convention.

use OCA\OpenRegister\Exception\ProviderUnavailableException;
use OCA\OpenRegister\Service\Integration\AbstractIntegrationProvider;
use OCA\OpenRegister\Service\Integration\ExternalIntegrationRouter;
use OCA\OpenRegister\Service\Integration\Providers\BrpPersonProvider;
use OCP\App\IAppManager;
use OCP\IL10N;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

#[CoversClass(BrpPersonProvider::class)]
#[UsesClass(AbstractIntegrationProvider::class)]
#[UsesClass(ProviderUnavailableException::class)]
class BrpPersonProviderResidentCountTest extends TestCase {

	private const ADDRESS = '0518010000123456';

	/**
	 * The router double.
	 *
	 * @var ExternalIntegrationRouter&MockObject
	 */
	private $router;

	/**
	 * Every log line the provider wrote, message and context.
	 *
	 * @var list<string>
	 */
	private array $logged = [];

	private BrpPersonProvider $provider;

	protected function setUp(): void {
		parent::setUp();
		$this->router = $this->createMock(ExternalIntegrationRouter::class);
		$this->logged = [];

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['warning', 'error', 'info', 'debug', 'notice'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context = []): void {
					$this->logged[] = $message . json_encode($context);
				}
			);
		}

		$this->provider = new BrpPersonProvider(
			$this->router,
			$this->createMock(IAppManager::class),
			$this->createMock(IL10N::class),
			$logger
		);
	}//end setUp()

	public function testItCountsTheResidentsAndAnswersNothingAboutThem(): void {
		$this->router->method('sourceConfiguration')->willReturn(['residentCount' => true]);
		$this->router->expects($this->once())
			->method('callWithMeta')
			->with(
				$this->identicalTo($this->provider),
				'POST',
				'personen',
				$this->callback(
					static function (array $options): bool {
						return $options['body'] === [
							'type' => 'ZoekMetAdresseerbaarObjectIdentificatie',
							'adresseerbaarObjectIdentificatie' => self::ADDRESS,
							'fields' => ['burgerservicenummer'],
						] && $options['withholdBodies'] === true;
					}
				)
			)
			->willReturn(
				[
					'body' => [
						'type' => 'ZoekMetAdresseerbaarObjectIdentificatie',
						'personen' => [
							['burgerservicenummer' => '999993653'],
							['burgerservicenummer' => '999990627'],
							['burgerservicenummer' => '999991723'],
						],
					],
					'meta' => ['correlationId' => 'c-1', 'durationMs' => 42, 'status' => 200, 'headers' => ['X-Correlation-ID' => 'c-1']],
				]
			);

		$answer = $this->provider->countResidentsAtAddressObject(' ' . self::ADDRESS . ' ');

		$this->assertSame(['total' => 3, 'meta' => ['correlationId' => 'c-1', 'durationMs' => 42, 'status' => 200]], $answer);
		$this->assertStringNotContainsString('9999', (string)json_encode($answer));
	}//end testItCountsTheResidentsAndAnswersNothingAboutThem()

	public function testNoOneRegisteredIsACountOfZero(): void {
		$this->router->method('sourceConfiguration')->willReturn(['residentCount' => true]);
		$this->router->method('callWithMeta')->willReturn(['body' => ['personen' => []], 'meta' => []]);

		$answer = $this->provider->countResidentsAtAddressObject(self::ADDRESS);

		$this->assertSame(0, $answer['total']);
		$this->assertArrayNotHasKey('unavailable', $answer);
	}//end testNoOneRegisteredIsACountOfZero()

	/**
	 * Only the boolean true allows the count.
	 *
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function settingsThatDoNotAllowTheCount(): array {
		return [
			'absent' => [[]],
			'false' => [['residentCount' => false]],
			'the string true' => [['residentCount' => 'true']],
			'one' => [['residentCount' => 1]],
		];
	}//end settingsThatDoNotAllowTheCount()

	/**
	 * @dataProvider settingsThatDoNotAllowTheCount
	 *
	 * @param array<string, mixed> $configuration The source configuration.
	 *
	 * @return void
	 */
	public function testWithoutTheSettingTheRegistryIsNotAsked(array $configuration): void {
		$this->router->method('sourceConfiguration')->willReturn($configuration);
		$this->router->expects($this->never())->method('callWithMeta');

		$this->assertSame(
			['unavailable' => true, 'cause' => 'resident-count-not-enabled'],
			$this->provider->countResidentsAtAddressObject(self::ADDRESS)
		);
	}//end testWithoutTheSettingTheRegistryIsNotAsked()

	public function testAMalformedIdIsRefusedWithoutAnyCall(): void {
		$this->router->expects($this->never())->method('sourceConfiguration');
		$this->router->expects($this->never())->method('callWithMeta');

		foreach (['', '12345', '0518 0100 0012 3456', '051801000012345X', '05180100001234567'] as $id) {
			$this->assertSame(
				['unavailable' => true, 'cause' => 'invalid-identifier'],
				$this->provider->countResidentsAtAddressObject($id),
				$id
			);
		}
	}//end testAMalformedIdIsRefusedWithoutAnyCall()

	public function testAMissingSourceDegrades(): void {
		$this->router->method('sourceConfiguration')->willThrowException(
			new ProviderUnavailableException(message: 'missing', cause: ProviderUnavailableException::CAUSE_OPENCONNECTOR_SOURCE_MISSING)
		);

		$this->assertSame(
			['unavailable' => true, 'cause' => 'openconnector-source-missing'],
			$this->provider->countResidentsAtAddressObject(self::ADDRESS)
		);
	}//end testAMissingSourceDegrades()

	public function testAnUnreachableRegistryDegrades(): void {
		$this->router->method('sourceConfiguration')->willReturn(['residentCount' => true]);
		$this->router->method('callWithMeta')->willThrowException(
			new ProviderUnavailableException(message: 'down', cause: ProviderUnavailableException::CAUSE_UPSTREAM_SERVICE_DOWN)
		);

		$this->assertSame(
			['unavailable' => true, 'cause' => 'upstream-service-down'],
			$this->provider->countResidentsAtAddressObject(self::ADDRESS)
		);
	}//end testAnUnreachableRegistryDegrades()

	public function testAnUnexpectedFailureIsLoggedWithoutTheAddress(): void {
		$this->router->method('sourceConfiguration')->willReturn(['residentCount' => true]);
		$this->router->method('callWithMeta')->willThrowException(new \RuntimeException('POST personen ' . self::ADDRESS . ' failed'));

		$answer = $this->provider->countResidentsAtAddressObject(self::ADDRESS);

		$this->assertSame(['unavailable' => true, 'cause' => 'upstream-service-down'], $answer);
		$this->assertCount(1, $this->logged);
		$this->assertStringNotContainsString(self::ADDRESS, implode("\n", $this->logged));
	}//end testAnUnexpectedFailureIsLoggedWithoutTheAddress()

	public function testAConnectorThatCannotWithholdBodiesAnswersItsOwnCause(): void {
		$this->router->method('sourceConfiguration')->willReturn(['residentCount' => true]);
		$this->router->method('callWithMeta')->willThrowException(
			new ProviderUnavailableException(message: 'old connector', cause: ProviderUnavailableException::CAUSE_CONNECTOR_CANNOT_WITHHOLD_BODIES)
		);

		$this->assertSame(
			['unavailable' => true, 'cause' => 'connector-cannot-withhold-bodies'],
			$this->provider->countResidentsAtAddressObject(self::ADDRESS)
		);
	}//end testAConnectorThatCannotWithholdBodiesAnswersItsOwnCause()
}//end class
