<?php

declare(strict_types=1);

namespace Unit\Service\Registry;

use OCA\OpenRegister\Db\RegistrySubscription;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Registry\RegistryOwnedPropertyGuard;
use OCA\OpenRegister\Service\Registry\RegistryUpdateTargetGuard;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @spec openspec/changes/registry-subscriptions/specs/registry-subscriptions/spec.md#requirement-an-inbound-registry-update-writes-owned-properties-only
 */
class RegistryUpdateTargetGuardTest extends TestCase {
	private RegistryUpdateTargetGuard $guard;
	private SchemaMapper&MockObject $schemaMapper;

	protected function setUp(): void {
		$this->schemaMapper = $this->createMock(SchemaMapper::class);
		$this->guard = new RegistryUpdateTargetGuard($this->schemaMapper, new RegistryOwnedPropertyGuard());
	}

	private function brpSchema(): Schema {
		$schema = new Schema();
		$schema->setConfiguration([
			'x-openregister-registry' => [
				'registry' => 'brp',
				'identity' => 'bsn',
				'owned' => ['address', 'givenNames'],
			],
		]);

		return $schema;
	}

	private function row(): RegistrySubscription {
		$row = new RegistrySubscription();
		$row->setSchema('brpPerson');

		return $row;
	}

	public function testAnOwnedPropertyIsAllowed(): void {
		$this->schemaMapper->method('find')->willReturn($this->brpSchema());

		$result = $this->guard->evaluate($this->row(), ['address' => 'Dam 1']);

		$this->assertTrue($result['allowed']);
	}

	public function testAPropertyOutsideOwnedIsRejected(): void {
		$this->schemaMapper->method('find')->willReturn($this->brpSchema());

		$result = $this->guard->evaluate($this->row(), ['notes' => 'secret']);

		$this->assertFalse($result['allowed']);
		$this->assertSame(['notes'], $result['rejected']);
	}

	public function testASchemaWithoutTheAnnotationRejectsEverySuppliedProperty(): void {
		$this->schemaMapper->method('find')->willReturn(new Schema());

		$result = $this->guard->evaluate($this->row(), ['address' => 'Dam 1']);

		$this->assertFalse($result['allowed']);
		$this->assertSame(['address'], $result['rejected']);
	}

	public function testASchemaThatNoLongerResolvesRejectsEverySuppliedProperty(): void {
		$this->schemaMapper->method('find')->willThrowException(new \Exception('gone'));

		$result = $this->guard->evaluate($this->row(), ['address' => 'Dam 1']);

		$this->assertFalse($result['allowed']);
		$this->assertSame(['address'], $result['rejected']);
	}
}
