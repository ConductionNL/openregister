<?php

declare(strict_types=1);

namespace Unit\Service\Registry;

use OCA\OpenRegister\Service\Registry\RegistryOwnedPropertyGuard;
use PHPUnit\Framework\TestCase;

class RegistryOwnedPropertyGuardTest extends TestCase {
	private RegistryOwnedPropertyGuard $guard;

	protected function setUp(): void {
		$this->guard = new RegistryOwnedPropertyGuard();
	}

	public function testAllSuppliedPropertiesOwnedIsAllowed(): void {
		$result = $this->guard->check(
			owned: ['address', 'givenNames'],
			supplied: ['address' => 'Dam 1'],
		);
		$this->assertTrue($result['allowed']);
		$this->assertSame([], $result['rejected']);
	}

	public function testASuppliedPropertyOutsideOwnedIsRejected(): void {
		$result = $this->guard->check(
			owned: ['address'],
			supplied: ['address' => 'Dam 1', 'notes' => 'secret'],
		);
		$this->assertFalse($result['allowed']);
		$this->assertSame(['notes'], $result['rejected']);
	}

	public function testEveryOffendingKeyIsNamedNotJustTheFirst(): void {
		$result = $this->guard->check(
			owned: ['address'],
			supplied: ['notes' => 'a', 'internalFlag' => true],
		);
		$this->assertFalse($result['allowed']);
		$this->assertSame(['notes', 'internalFlag'], $result['rejected']);
	}

	public function testEmptySuppliedIsAllowed(): void {
		$result = $this->guard->check(owned: ['address'], supplied: []);
		$this->assertTrue($result['allowed']);
	}

	public function testEmptyOwnedRejectsAnySuppliedProperty(): void {
		$result = $this->guard->check(owned: [], supplied: ['address' => 'Dam 1']);
		$this->assertFalse($result['allowed']);
		$this->assertSame(['address'], $result['rejected']);
	}
}
