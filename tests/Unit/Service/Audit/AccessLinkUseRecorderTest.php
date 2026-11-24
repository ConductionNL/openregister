<?php

/**
 * Tests for AccessLinkUseRecorder.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Audit
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Audit;

use DateTime;
use OCA\OpenRegister\Db\AccessLink;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\Core\AccessLinkUseRecorderInterface;
use OCA\OpenRegister\Service\Audit\AccessLinkUseRecorder;
use OCA\OpenRegister\Service\Sharing\AccessLinkUse;
use OCA\OpenRegister\Tests\Support\AuditTrailEvents;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Service\Audit\AccessLinkUseRecorder
 */
class AccessLinkUseRecorderTest extends TestCase {

	/**
	 * A link with a label, two capabilities and an object subject.
	 *
	 * @param string|null $label The link's label.
	 *
	 * @return AccessLink The link.
	 */
	private function link(?string $label = 'Advice request'): AccessLink {
		$link = new AccessLink();
		$link->setUuid('7a1f0f2e-0000-4000-8000-000000000001');
		$link->setSubjectType(AccessLink::SUBJECT_OBJECT);
		$link->setSubjectId('object-uuid');
		$link->setCapabilities('read,upload');
		$link->setLabel($label);
		$link->setCreatedBy('owner');
		$link->setExpiresAt(new DateTime('+1 day'));

		return $link;
	}//end link()

	/**
	 * The recorder is the audit trail's side of the Core port.
	 *
	 * @return void
	 */
	public function testItImplementsThePortCoreOwns(): void {
		$recorder = new AccessLinkUseRecorder(auditTrail: $this->createMock(originalClassName: AuditTrailMapperInterface::class));

		$this->assertInstanceOf(expected: AccessLinkUseRecorderInterface::class, actual: $recorder);
	}//end testItImplementsThePortCoreOwns()

	/**
	 * One object entry, the link as the actor, the link's own keys over the caller's.
	 *
	 * @return void
	 */
	public function testAUseWritesOneObjectEntryNamingTheLink(): void {
		$object = new ObjectEntity();
		$trail  = $this->createMock(originalClassName: AuditTrailMapperInterface::class);
		$trail->expects($this->once())
			->method('recordObjectEvent')
			->with(
				AuditTrailEvents::matching(
					[
						$object,
						'accessLink.upload',
						[
							'fileName' => 'advies.pdf',
							'subjectId' => 'object-uuid',
							'accessLink' => '7a1f0f2e-0000-4000-8000-000000000001',
							'capabilities' => ['read', 'upload'],
							'subjectType' => 'object',
							'ipAddress' => '198.51.100.7',
						],
						'link:7a1f0f2e-0000-4000-8000-000000000001',
						'Advice request',
						'198.51.100.7',
					]
				)
			)
			->willReturn(new AuditTrail());

		(new AccessLinkUseRecorder(auditTrail: $trail))->recordLinkUse(
			link: $this->link(),
			use: new AccessLinkUse(
				act: 'accessLink.upload',
				object: $object,
				ipAddress: '198.51.100.7',
				context: ['fileName' => 'advies.pdf', 'subjectId' => 'caller-said-something-else']
			)
		);
	}//end testAUseWritesOneObjectEntryNamingTheLink()

	/**
	 * A use without an object writes nothing.
	 *
	 * @return void
	 */
	public function testAUseWithoutAnObjectWritesNothing(): void {
		$trail = $this->createMock(originalClassName: AuditTrailMapperInterface::class);
		$trail->expects($this->never())->method('recordObjectEvent');

		(new AccessLinkUseRecorder(auditTrail: $trail))->recordLinkUse(link: $this->link(), use: new AccessLinkUse(act: 'accessLink.read'));
	}//end testAUseWithoutAnObjectWritesNothing()

	/**
	 * A failed write reaches the caller, which decides what the use still means.
	 *
	 * @return void
	 */
	public function testAFailedWriteIsThrownToTheCaller(): void {
		$trail = $this->createMock(originalClassName: AuditTrailMapperInterface::class);
		$trail->method('recordObjectEvent')->willThrowException(new RuntimeException('db down'));

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('db down');

		(new AccessLinkUseRecorder(auditTrail: $trail))->recordLinkUse(
			link: $this->link(),
			use: new AccessLinkUse(act: 'accessLink.read', object: new ObjectEntity())
		);
	}//end testAFailedWriteIsThrownToTheCaller()
}//end class
