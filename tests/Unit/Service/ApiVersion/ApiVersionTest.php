<?php

/**
 * ApiVersionTest — a declaration that cannot be honoured is refused here.
 *
 * The two refusals are the point: a deprecated version with no end date is a
 * removal nobody can plan around, and a withdrawn version with no successor is
 * an outage with a status code. Both were possible to declare before this
 * class existed, because nothing could declare a version at all.
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

use InvalidArgumentException;
use OCA\OpenRegister\Service\ApiVersion\ApiVersion;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\ApiVersion\ApiVersion
 */
class ApiVersionTest extends TestCase {

	public function testSupportedVersionNeedsNoDates(): void {
		$version = new ApiVersion(id: '1', status: ApiVersion::STATUS_SUPPORTED);

		$this->assertSame('1', $version->id);
		$this->assertTrue($version->isServed());
		$this->assertFalse($version->isDeprecated());
		$this->assertNull($version->sunset);
	}//end testSupportedVersionNeedsNoDates()

	public function testDeprecatedWithoutSunsetIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessageMatches('/deprecated without a sunset date/');

		new ApiVersion(id: '1', status: ApiVersion::STATUS_DEPRECATED, deprecatedOn: '2026-09-01');
	}//end testDeprecatedWithoutSunsetIsRefused()

	public function testWithdrawnWithoutSuccessorIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessageMatches('/withdrawn without a successor/');

		new ApiVersion(id: '1', status: ApiVersion::STATUS_WITHDRAWN);
	}//end testWithdrawnWithoutSuccessorIsRefused()

	public function testWithdrawnCannotSucceedItself(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessageMatches('/cannot succeed itself/');

		new ApiVersion(id: '1', status: ApiVersion::STATUS_WITHDRAWN, successor: '1');
	}//end testWithdrawnCannotSucceedItself()

	public function testDeprecatedVersionIsStillServed(): void {
		$version = new ApiVersion(
			id: '1',
			status: ApiVersion::STATUS_DEPRECATED,
			deprecatedOn: '2026-09-01',
			sunset: '2027-03-01',
			successor: '2',
		);

		$this->assertTrue($version->isServed(), 'A deprecated version that stops answering is a breaking change with extra steps.');
		$this->assertTrue($version->isDeprecated());
	}//end testDeprecatedVersionIsStillServed()

	public function testWithdrawnVersionIsNotServed(): void {
		$version = new ApiVersion(id: '1', status: ApiVersion::STATUS_WITHDRAWN, successor: '2');

		$this->assertFalse($version->isServed());
	}//end testWithdrawnVersionIsNotServed()

	/**
	 * @dataProvider provideRefusedDeclarations
	 */
	public function testMalformedDeclarationIsRefused(array $declaration): void {
		$this->expectException(InvalidArgumentException::class);

		ApiVersion::fromArray(declaration: $declaration);
	}//end testMalformedDeclarationIsRefused()

	public static function provideRefusedDeclarations(): array {
		return [
			'no identifier' => [['status' => 'supported']],
			'identifier is not digits' => [['id' => 'two', 'status' => 'supported']],
			'identifier too long' => [['id' => '1234', 'status' => 'supported']],
			'unknown status' => [['id' => '1', 'status' => 'retired']],
			'sunset is not a date' => [['id' => '1', 'status' => 'deprecated', 'sunset' => 'soon']],
			'sunset is a month' => [['id' => '1', 'status' => 'deprecated', 'sunset' => '2027-13-01']],
			'no path prefix' => [['id' => '1', 'status' => 'supported', 'pathPrefixes' => []]],
		];
	}//end provideRefusedDeclarations()

	public function testFromArrayAcceptsACompleteDeprecation(): void {
		$version = ApiVersion::fromArray(
			declaration: [
				'id' => '1',
				'status' => 'deprecated',
				'deprecatedOn' => '2026-09-01',
				'sunset' => '2027-03-01',
				'successor' => '2',
				'pathPrefixes' => ['/objects', 'registers/'],
				'description' => '  The first contract.  ',
			]
		);

		$this->assertSame('2027-03-01', $version->sunset);
		$this->assertSame('2', $version->successor);
		$this->assertSame(['/objects', '/registers'], $version->pathPrefixes);
		$this->assertSame('The first contract.', $version->description);
	}//end testFromArrayAcceptsACompleteDeprecation()

	public function testServesPathHonoursDeclaredPrefixes(): void {
		$narrow = new ApiVersion(id: '2', status: ApiVersion::STATUS_SUPPORTED, pathPrefixes: ['/objects']);

		$this->assertTrue($narrow->servesPath(path: '/objects/zaken/meldingen'));
		$this->assertTrue($narrow->servesPath(path: 'objects/zaken'));
		$this->assertFalse($narrow->servesPath(path: '/registers/1'));
	}//end testServesPathHonoursDeclaredPrefixes()

	public function testRootPrefixServesEverything(): void {
		$whole = new ApiVersion(id: '1', status: ApiVersion::STATUS_SUPPORTED, pathPrefixes: ['/']);

		$this->assertTrue($whole->servesPath(path: '/registers/1'));
		$this->assertTrue($whole->servesPath(path: '/anything/at/all'));
	}//end testRootPrefixServesEverything()

	public function testHttpDateRendersAnIsoDate(): void {
		$this->assertSame('Mon, 01 Mar 2027 00:00:00 GMT', ApiVersion::toHttpDate(isoDate: '2027-03-01'));
		$this->assertNull(ApiVersion::toHttpDate(isoDate: null));
		$this->assertNull(ApiVersion::toHttpDate(isoDate: 'not a date'));
	}//end testHttpDateRendersAnIsoDate()

	public function testPublishedShapeDropsAbsentKeys(): void {
		$supported = new ApiVersion(id: '1', status: ApiVersion::STATUS_SUPPORTED);

		$published = $supported->jsonSerialize();

		$this->assertSame(['version' => '1', 'status' => 'supported'], $published);
		$this->assertArrayNotHasKey('sunset', $published);
		$this->assertArrayNotHasKey('successor', $published);
	}//end testPublishedShapeDropsAbsentKeys()

	public function testPublishedShapeCarriesTheDeadline(): void {
		$deprecated = new ApiVersion(
			id: '1',
			status: ApiVersion::STATUS_DEPRECATED,
			deprecatedOn: '2026-09-01',
			sunset: '2027-03-01',
			successor: '2',
		);

		$published = $deprecated->jsonSerialize();

		$this->assertSame('2027-03-01', $published['sunset']);
		$this->assertSame('2026-09-01', $published['deprecatedOn']);
		$this->assertSame('2', $published['successor']);
	}//end testPublishedShapeCarriesTheDeadline()
}//end class
