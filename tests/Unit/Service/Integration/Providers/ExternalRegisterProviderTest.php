<?php

/**
 * Unit tests for ExternalRegisterProvider — the `external-register` leaf's
 * registry entry and the read its widget and tab call.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Integration\Providers
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/external-register-view-leaf/specs/object-source-providers/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Integration\Providers;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Kernel\Contracts\ObjectRef;
use OCA\OpenRegister\Service\Integration\ExternalRegisterLookupInterface;
use OCA\OpenRegister\Service\Integration\Providers\ExternalRegisterProvider;
use OCP\IL10N;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Integration\Providers\ExternalRegisterProvider
 * @uses \OCA\OpenRegister\Kernel\Contracts\ObjectRef
 */
final class ExternalRegisterProviderTest extends TestCase {

	private ExternalRegisterLookupInterface&MockObject $lookup;

	protected function setUp(): void {
		$this->lookup = $this->createMock(ExternalRegisterLookupInterface::class);
	}

	private function provider(): ExternalRegisterProvider {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => 'L:' . $text);
		return new ExternalRegisterProvider($this->lookup, $l10n);
	}

	public function testItIsAnExternalDataLeafThatNeedsNoApp(): void {
		$provider = $this->provider();

		$this->assertSame('external-register', $provider->getId());
		$this->assertSame('L:External register', $provider->getLabel());
		$this->assertSame('DatabaseSearchOutline', $provider->getIcon());
		$this->assertSame('data', $provider->getGroup());
		$this->assertNull($provider->getRequiredApp());
		$this->assertSame('external', $provider->getStorageStrategy());
		$this->assertTrue($provider->isEnabled());
	}

	public function testThePlacementNeedsASchemaAndAKey(): void {
		$this->assertSame(['schema', 'key'], ExternalRegisterProvider::PLACEMENT_SCHEMA['required']);
		$this->assertSame(['register', 'schema', 'key', 'display'], array_keys(ExternalRegisterProvider::PLACEMENT_SCHEMA['properties']));
		$this->assertFalse(ExternalRegisterProvider::PLACEMENT_SCHEMA['additionalProperties']);
	}

	public function testListReadsThePlacementInTheHostsRegister(): void {
		$answer = ['state' => 'ok', 'record' => ['straat' => 'Dam']];
		$this->lookup->expects($this->once())->method('read')->with('7', 'bag-adres', '0363', false)->willReturn($answer);

		$rows = $this->provider()->list(new ObjectRef(7, 3, 'case-1'), ['schema' => 'bag-adres', 'key' => '0363']);

		$this->assertSame([['id' => '7/bag-adres/0363', 'state' => 'ok', 'record' => ['straat' => 'Dam']]], $rows);
	}

	public function testAPlacementMayNameAnotherRegisterAndAskForARefresh(): void {
		$this->lookup->expects($this->once())->method('read')->with('basisregistraties', 'bag-adres', 'k', true)->willReturn(['state' => 'not-found']);

		$rows = $this->provider()->list(new ObjectRef(7, 3, 'case-1'), ['register' => 'basisregistraties', 'schema' => 'bag-adres', 'key' => 'k', '_refresh' => '1']);

		$this->assertSame('not-found', $rows[0]['state']);
	}

	public function testNoSchemaAsksNothing(): void {
		$this->lookup->expects($this->never())->method('read');

		$this->assertSame([], $this->provider()->list(new ObjectRef(7, 3, 'case-1'), ['key' => 'k']));
	}

	public function testASchemaTheCallerCannotReadGivesNoRow(): void {
		$this->lookup->method('read')->willReturn(null);

		$this->assertSame([], $this->provider()->list(new ObjectRef(7, 3, 'case-1'), ['schema' => 'bag-adres', 'key' => 'k']));
	}
}
