<?php

declare(strict_types=1);

/**
 * DeletionIntegrity and DeletionAttribution unit tests.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
 */

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\OrganisationMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Dto\DeletionAnalysis;
use OCA\OpenRegister\Exception\ReferentialIntegrityException;
use OCA\OpenRegister\Service\Object\DeletionAttribution;
use OCA\OpenRegister\Service\Object\DeletionIntegrity;
use OCA\OpenRegister\Service\Object\DeletionTarget;
use OCA\OpenRegister\Service\Object\ReferentialIntegrityService;
use OCA\OpenRegister\Service\Object\RefusedDeletion;
use OCA\OpenRegister\Db\Register;
use OCP\IDBConnection;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The transaction, refusal and attribution behaviour DeleteObject relies on.
 */
class DeletionCollaboratorsTest extends TestCase {

	public function testAtomicallyCommitsWhatTheWorkReturns(): void {
		$db = $this->createMock(IDBConnection::class);
		$calls = [];
		$db->method('beginTransaction')->willReturnCallback(function () use (&$calls) { $calls[] = 'begin'; });
		$db->method('commit')->willReturnCallback(function () use (&$calls) { $calls[] = 'commit'; });
		$db->expects($this->never())->method('rollBack');

		$integrity = new DeletionIntegrity($this->createMock(ReferentialIntegrityService::class), $db);
		$result = $integrity->atomically(work: function () use (&$calls): bool {
			$calls[] = 'work';
			return true;
		});

		$this->assertTrue($result);
		$this->assertSame(['begin', 'work', 'commit'], $calls);
	}

	public function testAtomicallyRollsBackAndRethrows(): void {
		$db = $this->createMock(IDBConnection::class);
		$db->expects($this->once())->method('beginTransaction');
		$db->expects($this->never())->method('commit');
		$db->expects($this->once())->method('rollBack');

		$integrity = new DeletionIntegrity($this->createMock(ReferentialIntegrityService::class), $db);

		$this->expectException(RuntimeException::class);
		$integrity->atomically(work: fn (): bool => throw new RuntimeException('cascade failed'));
	}

	public function testGovernsOnlyASchemaWithIncomingReferences(): void {
		$service = $this->createMock(ReferentialIntegrityService::class);
		$service->method('hasIncomingOnDeleteReferences')->willReturnMap([['7', true], ['8', false]]);
		$integrity = new DeletionIntegrity($service, $this->createMock(IDBConnection::class));

		$this->assertTrue($integrity->governs(schemaId: '7'));
		$this->assertFalse($integrity->governs(schemaId: '8'));
		$this->assertFalse($integrity->governs(schemaId: null));
	}

	public function testRefuseLogsTheBlockAndThrows(): void {
		$analysis = new DeletionAnalysis(deletable: false, blockers: [['schema' => '9']]);
		$refused = new RefusedDeletion(objectUuid: 'u-1', schemaId: '7', userId: 'alice');
		$service = $this->createMock(ReferentialIntegrityService::class);
		$service->expects($this->once())->method('logRestrictBlock')->with($refused, $analysis);
		$integrity = new DeletionIntegrity($service, $this->createMock(IDBConnection::class));

		$this->expectException(ReferentialIntegrityException::class);
		$integrity->refuse(refused: $refused, analysis: $analysis);
	}

	public function testActingUserIsSystemWithoutASession(): void {
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn(null);
		$attribution = new DeletionAttribution($session, $this->createMock(OrganisationMapperInterface::class), $this->createMock(LoggerInterface::class));

		$this->assertSame(['system', null], $attribution->actingUser());
	}

	public function testDeletedMetadataNamesTheUserAndOrganisation(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$organisations = $this->createMock(OrganisationMapperInterface::class);
		$organisations->method('getActiveOrganisationWithFallback')->with('alice')->willReturn('org-1');
		$attribution = new DeletionAttribution($session, $organisations, $this->createMock(LoggerInterface::class));

		$object = new ObjectEntity();
		$object->setUuid('u-1');
		$metadata = $attribution->deletedMetadata(
			target: new DeletionTarget(object: $object, register: new Register(), schema: new Schema())
		);

		$this->assertSame('alice', $metadata['deletedBy']);
		$this->assertSame('u-1', $metadata['objectId']);
		$this->assertSame('org-1', $metadata['organisation']);
		$this->assertArrayHasKey('deletedAt', $metadata);
	}
}
