<?php

/**
 * The triggering object of a cascade as the indexed `trigger_object` column:
 * written on a cascade row from its context, absent elsewhere, and outside the
 * sealed form so no hash changes.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/records-restore-with-cascade/specs/deletion-audit-trail/spec.md#requirement-the-cascade-evidence-is-findable-and-lasts-as-long-as-the-recovery-window
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrail\AuditedChange;
use OCA\OpenRegister\Db\AuditTrail\AuditTrailFieldTypes;
use OCA\OpenRegister\Db\AuditTrail\AuditTrailMapperServices;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\IDBConnection;
use OCP\IRequest;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\Db\AuditTrailMapper
 * @uses \OCA\OpenRegister\Db\AuditTrail
 * @uses \OCA\OpenRegister\Db\ObjectEntity
 * @uses \OCA\OpenRegister\Db\AuditFlowAttribution
 * @uses \OCA\OpenRegister\Db\AuditTrailPayloadHelper
 * @uses \OCA\OpenRegister\Service\Audit\PurposeAttribution
 * @uses \OCA\OpenRegister\Service\Audit\TokenAttribution
 * @uses \OCA\OpenRegister\Service\WriteCause
 * @uses \OCA\OpenRegister\Service\Audit\OrganisationContextAttribution
 * @uses \OCA\OpenRegister\Service\OrganisationContext
 * @uses \OCA\OpenRegister\Service\ObjectAction\ObjectActionElevationScope
 * @uses \OCA\OpenRegister\Db\AuditTrail\AuditTrailMapperServices
 * @uses \OCA\OpenRegister\Db\AuditTrail\AuditTrailSealedForm
 * @uses \OCA\OpenRegister\Db\AuditTrail\AuditedChange
 * @uses \OCA\OpenRegister\Service\Audit\AuditRowAttribution
 * @uses \OCA\OpenRegister\Service\Audit\AuditTrailCollaborators
 *
 * @spec openspec/changes/records-restore-with-cascade/specs/deletion-audit-trail/spec.md#requirement-the-cascade-evidence-is-findable-and-lasts-as-long-as-the-recovery-window
 */
class AuditTrailTriggerObjectTest extends TestCase {

	private const ROOT = '9c1f0e4b-1d2a-4b3c-8d4e-5f6a7b8c9d01';

	private function mapper(): AuditTrailMapper {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new \RuntimeException('not registered'));
		$request = $this->createMock(IRequest::class);
		$request->method('getId')->willReturn('req-1');
		$request->method('getRemoteAddress')->willReturn('127.0.0.1');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn(null);

		return new AuditTrailMapper($this->createMock(IDBConnection::class), new AuditTrailMapperServices($container, $this->createMock(LoggerInterface::class), $session, $request));
	}

	private static function object(): ObjectEntity {
		$object = new ObjectEntity();
		$object->setId(7);
		$object->setUuid('u-7');
		$object->setRegister('1');
		$object->setSchema('2');
		$object->setObject(['title' => 'Activity']);
		$object->setVersion('1.0.1');

		return $object;
	}

	private function build(?array $cascade): AuditTrail {
		return $this->mapper()->buildEntry(change: new AuditedChange(old: self::object(), new: null, action: 'delete', cascadeContext: $cascade));
	}

	public function testACascadeRowCarriesItsTriggerInTheColumn(): void {
		$row = $this->build(cascade: ['triggerObject' => self::ROOT, 'triggerSchema' => 'lead', 'property' => 'lead']);

		$this->assertSame(self::ROOT, $row->getTriggerObject());
		$this->assertSame(self::ROOT, $row->getChanged()['cascadeContext']['triggerObject'], 'the column projects the sealed payload');
	}

	public function testARowOutsideACascadeHasNoTrigger(): void {
		$this->assertNull($this->build(cascade: null)->getTriggerObject());
		$this->assertNull($this->build(cascade: ['triggerObject' => '  ', 'triggerSchema' => 'lead'])->getTriggerObject());
	}

	public function testTheColumnIsOutsideTheSealedFormSoNoHashChanges(): void {
		$this->assertArrayHasKey('triggerObject', AuditTrailFieldTypes::TYPES);
		$this->assertNotContains('triggerObject', AuditTrailFieldTypes::SEALED_FIELDS);

		$row = new AuditTrail();
		$row->setUuid('a-1');
		$row->setAction('referential_integrity.set_null');
		$row->setChanged(['triggerObject' => self::ROOT]);
		$without = $row->jsonSerialize();

		$row->setTriggerObject(self::ROOT);

		$this->assertSame($without, $row->jsonSerialize(), 'the serialised (sealed) form is unchanged by the column');
		$this->assertArrayNotHasKey('triggerObject', $row->jsonSerialize());
	}

	public function testTheProjectionReadsOnlyAUsableUuid(): void {
		$this->assertSame(self::ROOT, AuditTrailMapper::triggerObjectOf(cascadeContext: ['triggerObject' => self::ROOT]));
		$this->assertNull(AuditTrailMapper::triggerObjectOf(cascadeContext: null));
		$this->assertNull(AuditTrailMapper::triggerObjectOf(cascadeContext: ['triggerObject' => 42]));
		$this->assertNull(AuditTrailMapper::triggerObjectOf(cascadeContext: []));
	}
}//end class
