<?php

/**
 * ApiVersionCatalogueTest — the two directions the catalogue fails in.
 *
 * The declaration fails closed (a bad version is dropped) and the read fails
 * open (a bad SET keeps serving the built-in contract). The second half is the
 * one worth testing hardest: the tempting implementation refuses to resolve
 * anything when the administered JSON is malformed, which takes the entire API
 * down over a mistyped sunset date.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\ApiVersion
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/changes/api-as-a-versioned-surface/specs/openapi-generation/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ApiVersion;

use OCA\OpenRegister\Service\ApiVersion\ApiVersion;
use OCA\OpenRegister\Service\ApiVersion\ApiVersionCatalogue;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\Service\ApiVersion\ApiVersionCatalogue
 */
class ApiVersionCatalogueTest extends TestCase {

	/**
	 * Build a catalogue over an administered declaration.
	 *
	 * @param string $configured The raw configuration value.
	 *
	 * @return ApiVersionCatalogue The catalogue.
	 */
	private function catalogue(string $configured = ''): ApiVersionCatalogue {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn($configured);

		return new ApiVersionCatalogue($appConfig, $this->createMock(LoggerInterface::class));
	}//end catalogue()

	public function testAnUnconfiguredInstancePublishesTheBuiltInContract(): void {
		$catalogue = $this->catalogue();

		$this->assertSame(['1'], $catalogue->identifiers());
		$this->assertSame('1', $catalogue->current()->id);
		$this->assertSame(ApiVersion::STATUS_SUPPORTED, $catalogue->current()->status);
		$this->assertSame([], $catalogue->rejections());
	}//end testAnUnconfiguredInstancePublishesTheBuiltInContract()

	public function testTwoVersionsAreServedAtOnce(): void {
		$catalogue = $this->catalogue(
			configured: json_encode(
				[
					['id' => '1', 'status' => 'deprecated', 'deprecatedOn' => '2026-09-01', 'sunset' => '2027-03-01', 'successor' => '2'],
					['id' => '2', 'status' => 'supported'],
				]
			)
		);

		$served = $catalogue->served();

		$this->assertSame(['1', '2'], $catalogue->servedIdentifiers());
		$this->assertTrue($served['1']->isDeprecated());
		$this->assertSame('2', $catalogue->current()->id, 'The current version is the highest supported one.');
	}//end testTwoVersionsAreServedAtOnce()

	public function testAWithdrawnVersionIsReturnedRatherThanHidden(): void {
		$catalogue = $this->catalogue(
			configured: json_encode(
				[
					['id' => '1', 'status' => 'withdrawn', 'successor' => '2'],
					['id' => '2', 'status' => 'supported'],
				]
			)
		);

		$withdrawn = $catalogue->get(identifier: '1');

		$this->assertNotNull($withdrawn, 'Hiding it turns a deliberate withdrawal back into a 404.');
		$this->assertFalse($withdrawn->isServed());
		$this->assertSame(['2'], $catalogue->servedIdentifiers());
	}//end testAWithdrawnVersionIsReturnedRatherThanHidden()

	public function testAnUndeclaredVersionResolvesToNull(): void {
		$this->assertNull($this->catalogue()->get(identifier: '9'));
	}//end testAnUndeclaredVersionResolvesToNull()

	public function testAMalformedConfigurationKeepsServingTheBuiltInContract(): void {
		$catalogue = $this->catalogue(configured: 'not json at all');

		$this->assertSame(['1'], $catalogue->identifiers());
		$this->assertSame('1', $catalogue->current()->id);
		$this->assertArrayHasKey('*', $catalogue->rejections());
	}//end testAMalformedConfigurationKeepsServingTheBuiltInContract()

	public function testASetWithNothingSupportedFallsBackRatherThanRefusingEveryCall(): void {
		$catalogue = $this->catalogue(
			configured: json_encode([['id' => '1', 'status' => 'withdrawn', 'successor' => '2']])
		);

		$this->assertSame(['1'], $catalogue->identifiers());
		$this->assertTrue(
			$catalogue->current()->isServed(),
			'A set with nothing supported must not take the API down; the built-in contract answers instead.'
		);
	}//end testASetWithNothingSupportedFallsBackRatherThanRefusingEveryCall()

	public function testASuccessorThatDoesNotAnswerIsRefused(): void {
		$catalogue = $this->catalogue(
			configured: json_encode(
				[
					['id' => '1', 'status' => 'withdrawn', 'successor' => '3'],
					['id' => '2', 'status' => 'supported'],
				]
			)
		);

		$this->assertSame(
			['1'],
			$catalogue->identifiers(),
			'The built-in contract answers, because pointing an integrator at version 3 would refuse them too.'
		);
	}//end testASuccessorThatDoesNotAnswerIsRefused()

	public function testOneBadDeclarationIsRecordedAndTheRestOfTheSetStands(): void {
		$catalogue = $this->catalogue(
			configured: json_encode(
				[
					['id' => '1', 'status' => 'supported'],
					['id' => '2', 'status' => 'deprecated'],
				]
			)
		);

		$this->assertSame(['1'], $catalogue->identifiers());
		$this->assertArrayHasKey('2', $catalogue->rejections());
		$this->assertStringContainsString('sunset', $catalogue->rejections()['2']);
	}//end testOneBadDeclarationIsRecordedAndTheRestOfTheSetStands()

	public function testAVersionDeclaredTwiceIsRefused(): void {
		$catalogue = $this->catalogue(
			configured: json_encode(
				[
					['id' => '1', 'status' => 'supported'],
					['id' => '1', 'status' => 'deprecated', 'sunset' => '2027-03-01', 'successor' => '1'],
				]
			)
		);

		$this->assertArrayHasKey('1', $catalogue->rejections());
	}//end testAVersionDeclaredTwiceIsRefused()

	public function testVersionsArePublishedInIdentifierOrderNotDeclarationOrder(): void {
		$catalogue = $this->catalogue(
			configured: json_encode(
				[
					['id' => '10', 'status' => 'supported'],
					['id' => '2', 'status' => 'supported'],
				]
			)
		);

		$this->assertSame(['2', '10'], $catalogue->identifiers());
		$this->assertSame('10', $catalogue->current()->id, 'Ten is after two, not before it.');
	}//end testVersionsArePublishedInIdentifierOrderNotDeclarationOrder()
}//end class
