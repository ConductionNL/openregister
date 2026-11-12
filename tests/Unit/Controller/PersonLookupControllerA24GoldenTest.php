<?php

/**
 * Golden outcomes of the BRP person lookup.
 *
 * Pins status and full body of the missing-BSN refusal, the relayed provider
 * envelope and the degraded-provider 503.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/integration-person-lookup/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Controller\PersonLookupController;
use OCA\OpenRegister\Service\Integration\Providers\BrpPersonProvider;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * Allowed and refused outcomes of the person lookup.
 */
final class PersonLookupControllerA24GoldenTest extends TestCase {
	private function outcome(array $params, ?array $result): array {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn (string $key, $default = null) => ($params[$key] ?? $default));

		$provider = $this->createMock(BrpPersonProvider::class);
		if ($result === null) {
			$provider->expects($this->never())->method('lookupByBsn');
		} else {
			$provider->expects($this->once())->method('lookupByBsn')->with('999993653')->willReturn($result);
		}

		$response = (new PersonLookupController($request, $provider))->brpPerson();

		return [$response->getStatus(), $response->getData()];
	}//end outcome()

	public function testAMissingBsnIsRefusedWith400(): void {
		$this->assertSame([400, ['error' => 'bsn is required']], $this->outcome([], null));
		$this->assertSame([400, ['error' => 'bsn is required']], $this->outcome(['bsn' => '  '], null));
	}//end testAMissingBsnIsRefusedWith400()

	public function testTheProviderEnvelopeIsRelayed(): void {
		$envelope = ['results' => [['burgerservicenummer' => '999993653']], 'total' => 1, 'meta' => ['status' => 200]];

		$this->assertSame([200, $envelope], $this->outcome(['bsn' => ' 999993653 '], $envelope));
		$this->assertSame([200, ['unavailable' => 'yes']], $this->outcome(['bsn' => '999993653'], ['unavailable' => 'yes']));
	}//end testTheProviderEnvelopeIsRelayed()

	public function testADegradedProviderIs503WithItsCause(): void {
		$this->assertSame(
			[503, ['error' => 'brp-haalcentraal source is not available', 'details' => ['cause' => 'provider-auth']]],
			$this->outcome(['bsn' => '999993653'], ['unavailable' => true, 'cause' => 'provider-auth', 'extra' => 1])
		);
	}//end testADegradedProviderIs503WithItsCause()
}//end class
