<?php
/**
 * Unit tests for BagAddressController — the public address lookup endpoint.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Controller\BagAddressController;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\Bag\BagAddressLookup;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

#[CoversClass(BagAddressController::class)]
#[UsesClass(BagAddressLookup::class)]
final class BagAddressControllerTest extends TestCase {

	private function controller(array $params, array|\Throwable $rows, ?LoggerInterface $logger = null): BagAddressController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => ($params[$key] ?? $default)
		);

		$objects = $this->createMock(ObjectOperationsInterface::class);
		if ($rows instanceof \Throwable) {
			$objects->method('searchObjectsBySlug')->willThrowException($rows);
		} else {
			$objects->method('searchObjectsBySlug')->willReturn($rows);
		}

		return new BagAddressController($request, new BagAddressLookup($objects), $logger ?? $this->createMock(LoggerInterface::class));
	}

	private static function address(array $fields = []): array {
		return $fields + [
			'identificatie' => '0599200000001234',
			'huisnummer' => 12,
			'postcode' => '2611AB',
			'openbareRuimteNaam' => 'Lindelaan',
			'woonplaatsNaam' => 'Zuiddrecht',
			'status' => 'Naamgeving uitgegeven',
		];
	}

	public function testAHitAnswersTheAddresses(): void {
		$response = $this->controller(['postcode' => '2611 ab', 'huisnummer' => '12'], [self::address()])->index();

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(1, $response->getData()['total']);
		$this->assertSame('Lindelaan', $response->getData()['results'][0]['straat']);
	}

	public function testALetterAndAdditionAreHandedOn(): void {
		$rows = [self::address(['huisletter' => 'A']), self::address(['identificatie' => '2', 'huisletter' => 'A', 'huisnummertoevoeging' => '2'])];

		$response = $this->controller(['postcode' => '2611AB', 'huisnummer' => '12', 'huisletter' => 'a', 'toevoeging' => '2'], $rows)->index();

		$this->assertSame(['2'], array_column($response->getData()['results'], 'nummeraanduiding'));
	}

	public function testNoMatchAnswers404(): void {
		$response = $this->controller(['postcode' => '9999ZZ', 'huisnummer' => '1'], [])->index();

		$this->assertSame(404, $response->getStatus());
		$this->assertSame(['results' => [], 'total' => 0], $response->getData());
	}

	public function testInputThatIsNoAddressAnswers400(): void {
		$response = $this->controller(['postcode' => 'abc', 'huisnummer' => '12'], [])->index();

		$this->assertSame(400, $response->getStatus());
		$this->assertSame('invalid_address', $response->getData()['error']);
		$this->assertSame(400, $this->controller([], [])->index()->getStatus());
	}

	public function testAnUnreadableRegisterAnswers503WithoutItsDetail(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error');

		$response = $this->controller(['postcode' => '2611AB', 'huisnummer' => '12'], new RuntimeException('SQLSTATE secret'), $logger)->index();

		$this->assertSame(503, $response->getStatus());
		$this->assertSame(['message' => 'The address lookup is not available.', 'error' => 'lookup_unavailable'], $response->getData());
	}

	public function testTheEndpointIsPublicWithoutCsrfAndThrottledAtSixtyAMinute(): void {
		$method = new ReflectionMethod(BagAddressController::class, 'index');

		$this->assertCount(1, $method->getAttributes(PublicPage::class));
		$this->assertCount(1, $method->getAttributes(NoCSRFRequired::class));
		$limits = $method->getAttributes(AnonRateLimit::class);
		$this->assertCount(1, $limits);
		$this->assertSame(['limit' => 60, 'period' => 60], $limits[0]->getArguments());
	}
}
