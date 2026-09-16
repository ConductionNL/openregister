<?php

/**
 * ApiVersionNegotiatorTest — what a caller said, and what it gets.
 *
 * The test that matters most is the typo one: `API-Version: two` must NOT
 * resolve to the current contract. Reading an unparseable value as "named
 * nothing" would hand a typo the default and let a client believe for a year
 * that it was pinned, which is the exact failure a version header exists to
 * prevent.
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

use OCA\OpenRegister\Service\ApiVersion\ApiVersionCatalogue;
use OCA\OpenRegister\Service\ApiVersion\ApiVersionNegotiation;
use OCA\OpenRegister\Service\ApiVersion\ApiVersionNegotiator;
use OCP\IAppConfig;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\Service\ApiVersion\ApiVersionNegotiator
 * @covers \OCA\OpenRegister\Service\ApiVersion\ApiVersionNegotiation
 */
class ApiVersionNegotiatorTest extends TestCase {

	/**
	 * A catalogue serving version 1 deprecated and version 2 supported.
	 *
	 * @return ApiVersionCatalogue The catalogue.
	 */
	private function twoVersionCatalogue(): ApiVersionCatalogue {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn(
			json_encode(
				[
					['id' => '1', 'status' => 'deprecated', 'deprecatedOn' => '2026-09-01', 'sunset' => '2027-03-01', 'successor' => '2'],
					['id' => '2', 'status' => 'supported'],
				]
			)
		);

		return new ApiVersionCatalogue($appConfig, $this->createMock(LoggerInterface::class));
	}//end twoVersionCatalogue()

	/**
	 * A request carrying the given headers and path.
	 *
	 * @param array<string, string> $headers The request headers.
	 * @param string $path The request path.
	 *
	 * @return IRequest The request double.
	 */
	private function request(array $headers = [], string $path = '/apps/openregister/api/objects'): IRequest {
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturnCallback(
			static fn (string $name): string => ($headers[$name] ?? '')
		);
		$request->method('getPathInfo')->willReturn($path);

		return $request;
	}//end request()

	public function testACallerNamingNothingGetsTheCurrentVersion(): void {
		$negotiator = new ApiVersionNegotiator($this->twoVersionCatalogue());

		$outcome = $negotiator->negotiate(request: $this->request());

		$this->assertSame('2', $outcome->version->id);
		$this->assertNull($outcome->requestedId);
		$this->assertFalse($outcome->isExplicit());
		$this->assertFalse($outcome->isUnknown());
	}//end testACallerNamingNothingGetsTheCurrentVersion()

	public function testTheHeaderPinsTheContract(): void {
		$negotiator = new ApiVersionNegotiator($this->twoVersionCatalogue());

		$outcome = $negotiator->negotiate(request: $this->request(headers: ['API-Version' => '1']));

		$this->assertSame('1', $outcome->version->id);
		$this->assertSame(ApiVersionNegotiation::SOURCE_HEADER, $outcome->source);
		$this->assertTrue($outcome->isExplicit());
	}//end testTheHeaderPinsTheContract()

	public function testAVPrefixNamesTheSameContract(): void {
		$negotiator = new ApiVersionNegotiator($this->twoVersionCatalogue());

		$outcome = $negotiator->negotiate(request: $this->request(headers: ['API-Version' => 'v1']));

		$this->assertSame('1', $outcome->version->id);
	}//end testAVPrefixNamesTheSameContract()

	public function testTheAcceptParameterNamesTheContract(): void {
		$negotiator = new ApiVersionNegotiator($this->twoVersionCatalogue());

		$outcome = $negotiator->negotiate(
			request: $this->request(headers: ['Accept' => 'application/json; version=1'])
		);

		$this->assertSame('1', $outcome->version->id);
		$this->assertSame(ApiVersionNegotiation::SOURCE_ACCEPT, $outcome->source);
	}//end testTheAcceptParameterNamesTheContract()

	public function testAPlainAcceptNamesNoContract(): void {
		$negotiator = new ApiVersionNegotiator($this->twoVersionCatalogue());

		$outcome = $negotiator->negotiate(request: $this->request(headers: ['Accept' => 'application/json']));

		$this->assertSame('2', $outcome->version->id);
		$this->assertSame(ApiVersionNegotiation::SOURCE_DEFAULT, $outcome->source);
	}//end testAPlainAcceptNamesNoContract()

	public function testThePathSegmentNamesTheContract(): void {
		$negotiator = new ApiVersionNegotiator($this->twoVersionCatalogue());

		$outcome = $negotiator->negotiate(
			request: $this->request(path: '/apps/openregister/api/mcp/v1/discover/tools')
		);

		$this->assertSame('1', $outcome->version->id);
		$this->assertSame(ApiVersionNegotiation::SOURCE_PATH, $outcome->source);
	}//end testThePathSegmentNamesTheContract()

	public function testAnObjectPathContainingV2IsNotAVersionDeclaration(): void {
		$negotiator = new ApiVersionNegotiator($this->twoVersionCatalogue());

		$outcome = $negotiator->negotiate(
			request: $this->request(path: '/apps/openregister/api/objects/zaken/v2meldingen')
		);

		$this->assertSame(ApiVersionNegotiation::SOURCE_DEFAULT, $outcome->source);
	}//end testAnObjectPathContainingV2IsNotAVersionDeclaration()

	public function testTheHeaderWinsOverAcceptAndOverThePath(): void {
		$negotiator = new ApiVersionNegotiator($this->twoVersionCatalogue());

		$outcome = $negotiator->negotiate(
			request: $this->request(
				headers: ['API-Version' => '2', 'Accept' => 'application/json; version=1'],
				path: '/apps/openregister/api/mcp/v1/discover/tools',
			)
		);

		$this->assertSame('2', $outcome->version->id);
		$this->assertSame(ApiVersionNegotiation::SOURCE_HEADER, $outcome->source);
	}//end testTheHeaderWinsOverAcceptAndOverThePath()

	public function testATypoIsUnknownRatherThanTheDefault(): void {
		$negotiator = new ApiVersionNegotiator($this->twoVersionCatalogue());

		$outcome = $negotiator->negotiate(request: $this->request(headers: ['API-Version' => 'two']));

		$this->assertTrue($outcome->isUnknown(), 'Falling back to the current contract would let a typo look like a pin.');
		$this->assertNull($outcome->version);
		$this->assertSame('two', $outcome->requestedId);
	}//end testATypoIsUnknownRatherThanTheDefault()

	public function testAnUndeclaredVersionIsUnknown(): void {
		$negotiator = new ApiVersionNegotiator($this->twoVersionCatalogue());

		$outcome = $negotiator->negotiate(request: $this->request(headers: ['API-Version' => '9']));

		$this->assertTrue($outcome->isUnknown());
	}//end testAnUndeclaredVersionIsUnknown()

	public function testAWithdrawnVersionResolvesSoTheRefusalCanNameItsSuccessor(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn(
			json_encode(
				[
					['id' => '1', 'status' => 'withdrawn', 'successor' => '2'],
					['id' => '2', 'status' => 'supported'],
				]
			)
		);
		$negotiator = new ApiVersionNegotiator(
			new ApiVersionCatalogue($appConfig, $this->createMock(LoggerInterface::class))
		);

		$outcome = $negotiator->negotiate(request: $this->request(headers: ['API-Version' => '1']));

		$this->assertFalse($outcome->isUnknown());
		$this->assertFalse($outcome->version->isServed());
		$this->assertSame(['2'], $negotiator->acceptableIdentifiers());
	}//end testAWithdrawnVersionResolvesSoTheRefusalCanNameItsSuccessor()
}//end class
