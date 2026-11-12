<?php

/**
 * Unit test for SourcePayload.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Controller\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Controller\Support;

use OCA\OpenRegister\Controller\Support\SourcePayload;
use OCP\Security\ICrypto;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Create and update payloads of a source lose internal and immutable keys and never carry secrets.
 *
 * @covers \OCA\OpenRegister\Controller\Support\SourcePayload
 */
class SourcePayloadTest extends TestCase {
	/**
	 * The crypto under test or its double.
	 *
	 * @var ICrypto&MockObject
	 */
	private ICrypto&MockObject $crypto;

	/**
	 * The payload under test or its double.
	 *
	 * @var SourcePayload
	 */
	private SourcePayload $payload;

	protected function setUp(): void {
		$this->crypto = $this->createMock(ICrypto::class);
		$this->crypto->method('encrypt')->willReturnCallback(static fn (string $plain): string => 'enc(' . $plain . ')');
		$this->payload = new SourcePayload(crypto: $this->crypto);
	}

	public function testForCreateDropsUnderscoreKeysAndTheId(): void {
		$result = $this->payload->forCreate(['id' => 5, '_route' => 'x', 'title' => 'A', 'owner' => 'bob']);

		$this->assertSame(['title' => 'A', 'owner' => 'bob'], $result);
	}

	public function testForUpdateDropsEveryImmutableField(): void {
		$result = $this->payload->forUpdate(
			['id' => 5, 'organisation' => 'o', 'owner' => 'bob', 'created' => 'now', '_x' => 1, 'title' => 'B']
		);

		$this->assertSame(['title' => 'B'], $result);
	}

	public function testANonDatabaseSourceEncryptsItsDatabaseUrl(): void {
		$result = $this->payload->forCreate(['type' => 'api', 'databaseUrl' => 'pgsql://u:p@h/db']);

		$this->assertSame('enc(pgsql://u:p@h/db)', $result['databaseUrl']);
	}

	public function testAnEmptyDatabaseUrlIsNotEncrypted(): void {
		$this->crypto->expects($this->never())->method('encrypt');

		$result = $this->payload->forCreate(['type' => 'api', 'databaseUrl' => '']);

		$this->assertSame('', $result['databaseUrl']);
	}

	public function testADatabaseSourceLosesItsUrlAndAuthSecrets(): void {
		$this->crypto->expects($this->never())->method('encrypt');

		$result = $this->payload->forUpdate(
			[
				'type'        => 'database',
				'databaseUrl' => 'mysql://root:secret@db/app',
				'authConfig'  => ['username' => 'root', 'password' => 'secret', 'secret' => 's', 'mode' => 'basic'],
			]
		);

		$this->assertSame('', $result['databaseUrl']);
		$this->assertSame(['username' => 'root', 'mode' => 'basic'], $result['authConfig']);
	}

	public function testADatabaseSourceWithoutAUrlGetsAnEmptyOne(): void {
		$result = $this->payload->forCreate(['type' => 'database']);

		$this->assertArrayHasKey('databaseUrl', $result);
		$this->assertSame('', $result['databaseUrl']);
	}
}
