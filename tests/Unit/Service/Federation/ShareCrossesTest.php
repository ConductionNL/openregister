<?php

/**
 * Unit tests for ShareCrosses — what a federated share sends, defaulting to
 * the narrowest, and the payload each declaration allows.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Federation
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/platform-cloud-federation-provider/specs/federation/spec.md#requirement-a-federated-share-declares-what-crosses-req-pcf-002
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Federation;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use InvalidArgumentException;
use OCA\OpenRegister\Db\FederatedShare;
use OCA\OpenRegister\Service\Federation\ShareCrosses;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Federation\ShareCrosses
 * @uses \OCA\OpenRegister\Db\FederatedShare
 */
final class ShareCrossesTest extends TestCase {

	private const OBJECT = [
		'title' => 'Omgevingsvergunning Dorpsstraat 1',
		'internalNote' => 'call back after the hearing',
		'@self' => ['id' => 'u-1', 'schema' => '9', 'files' => [['id' => 7, 'title' => 'plan.pdf']], 'timeline' => [['id' => 3]]],
	];

	public function testNothingDeclaredIsTheNarrowest(): void {
		$this->assertSame(['data'], ShareCrosses::declared(null));
		$this->assertSame(['data'], ShareCrosses::declared([]));
		$this->assertSame(['data'], ShareCrosses::declared(''));
	}

	public function testADeclarationIsKeptInCanonicalOrderWithoutDuplicates(): void {
		$this->assertSame(['data', 'files', 'timeline'], ShareCrosses::declared(['timeline', 'files', 'data', 'files']));
		$this->assertSame(['files'], ShareCrosses::declared(['files']));
	}

	/**
	 * @return array<string, array{mixed}>
	 */
	public static function refused(): array {
		return [
			'unknown part' => [['data', 'notes']],
			'not a list' => [['data' => true]],
			'a string' => ['data'],
			'a non-string part' => [[1]],
		];
	}

	#[DataProvider('refused')]
	public function testAnUnknownOrMalformedDeclarationIsRefused(mixed $requested): void {
		$this->expectException(InvalidArgumentException::class);

		ShareCrosses::declared($requested);
	}

	public function testAStoredShareWithoutADeclarationServesTheNarrowest(): void {
		$this->assertSame(['data'], ShareCrosses::of(new FederatedShare()));
	}

	public function testAStoredDeclarationIsServedAndAnUnknownStoredPartIsNot(): void {
		$share = new FederatedShare();
		$share->setCrosses(['files', 'data', 'notes']);

		$this->assertSame(['data', 'files'], ShareCrosses::of($share));
	}

	public function testDataAloneSendsNoFilesAndNoTimeline(): void {
		$shaped = ShareCrosses::shape(self::OBJECT, ['data']);

		$this->assertSame('Omgevingsvergunning Dorpsstraat 1', $shaped['title']);
		$this->assertSame(['id' => 'u-1', 'schema' => '9'], $shaped['@self']);
	}

	public function testDataAndFilesKeepTheInternalNotesOutOfTheTimeline(): void {
		$shaped = ShareCrosses::shape(self::OBJECT, ['data', 'files']);

		$this->assertSame([['id' => 7, 'title' => 'plan.pdf']], $shaped['@self']['files']);
		$this->assertArrayNotHasKey('timeline', $shaped['@self']);
	}

	public function testFilesAloneSendsNoProperties(): void {
		$shaped = ShareCrosses::shape(self::OBJECT, ['files']);

		$this->assertSame(['@self'], array_keys($shaped));
		$this->assertSame([['id' => 7, 'title' => 'plan.pdf']], $shaped['@self']['files']);
	}

	public function testEverythingDeclaredSendsEverything(): void {
		$this->assertSame(self::OBJECT, ShareCrosses::shape(self::OBJECT, ShareCrosses::PARTS));
	}

	public function testAnObjectWithoutMetadataIsNarrowedWithoutOne(): void {
		$this->assertSame([], ShareCrosses::shape(['title' => 'x'], ['files']));
		$this->assertSame(['title' => 'x'], ShareCrosses::shape(['title' => 'x'], ['data']));
	}
}
