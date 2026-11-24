<?php

/**
 * Golden test for the AuditTrail entity's serialised form, which is the
 * canonical form the audit hash chain seals: its exact keys, order and values,
 * the hash a sealed row gets, and the purge flag and string form.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use DateTime;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Service\AuditHashService;
use OCP\IDBConnection;
use OCP\IAppConfig;
use OCP\Lock\ILockingProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Pins the sealed form of an audit trail row.
 */
class AuditTrailSealedFormGoldenTest extends TestCase {

	/**
	 * The field types, in registration order.
	 *
	 * @return void
	 */
	public function testFieldTypesInOrder(): void {
		$this->assertSame(
			'{"id":"integer","uuid":"string","schema":"integer","register":"integer","object":"integer","objectUuid":"string","registerUuid":"string","schemaUuid":"string","action":"string","changed":"json","user":"string","userName":"string","session":"string","request":"string","ipAddress":"string","version":"string","created":"datetime","organisationId":"string","organisationIdType":"string","processingActivityId":"string","processingActivityUrl":"string","processingId":"string","confidentiality":"string","retentionPeriod":"string","size":"integer","expires":"datetime","hash":"string","previousHash":"string","importJobId":"string","toolId":"string","paramsDigest":"string","resultSummary":"json","purgedAt":"datetime","cause":"string","causeRun":"string","flowRun":"string","flowNode":"string","flowStep":"integer","purpose":"string","consumer":"string","triggerObject":"string"}',
			json_encode((new AuditTrail())->getFieldTypes())
		);
	}//end testFieldTypesInOrder()

	/**
	 * A row with every field set serialises to exactly these bytes, without the unsealed fields.
	 *
	 * @return void
	 */
	public function testFilledRowSerialisesExactly(): void {
		$this->assertSame(
			'{"id":5,"uuid":"u-1","schema":2,"register":3,"object":4,"objectUuid":"o-1","registerUuid":"r-1","schemaUuid":"s-1","action":"update","changed":{"title":{"old":"a","new":"b"}},"user":"alice","userName":"Alice","session":"sess","request":"req","ipAddress":"10.0.0.1","version":"1.2.3","created":"2026-01-02T03:04:05+00:00","organisationId":"org","organisationIdType":"uuid","processingActivityId":"pa","processingActivityUrl":"https://pa","processingId":"p","confidentiality":"internal","retentionPeriod":"P1Y","size":42,"expires":"2027-01-02T03:04:05+00:00","hash":"h","previousHash":"ph","toolId":"tool","paramsDigest":"pd","resultSummary":{"ok":true},"cause":"flow","causeRun":"cr","flowRun":"fr","flowNode":"fn","flowStep":7}',
			json_encode($this->filledRow()->jsonSerialize(), JSON_UNESCAPED_SLASHES)
		);
	}//end testFilledRowSerialisesExactly()

	/**
	 * An empty row serialises every sealed key as null.
	 *
	 * @return void
	 */
	public function testEmptyRowSerialisesExactly(): void {
		$this->assertSame(
			'{"id":null,"uuid":null,"schema":null,"register":null,"object":null,"objectUuid":null,"registerUuid":null,"schemaUuid":null,"action":null,"changed":null,"user":null,"userName":null,"session":null,"request":null,"ipAddress":null,"version":null,"created":null,"organisationId":null,"organisationIdType":null,"processingActivityId":null,"processingActivityUrl":null,"processingId":null,"confidentiality":null,"retentionPeriod":null,"size":null,"expires":null,"hash":null,"previousHash":null,"toolId":null,"paramsDigest":null,"resultSummary":null,"cause":null,"causeRun":null,"flowRun":null,"flowNode":null,"flowStep":null}',
			json_encode((new AuditTrail())->jsonSerialize())
		);
	}//end testEmptyRowSerialisesExactly()

	/**
	 * The hash the chain gives the filled row is unchanged.
	 *
	 * @return void
	 */
	public function testSealedHashIsUnchanged(): void {
		$service = new AuditHashService(
			$this->createMock(IDBConnection::class),
			$this->createMock(ILockingProvider::class),
			$this->createMock(LoggerInterface::class),
			$this->createMock(IAppConfig::class)
		);
		$this->assertSame(
			hash('sha256', 'prev' . '{"action":"update","cause":"flow","causeRun":"cr","changed":{"title":{"old":"a","new":"b"}},"confidentiality":"internal","created":"2026-01-02T03:04:05+00:00","expires":"2027-01-02T03:04:05+00:00","flowNode":"fn","flowRun":"fr","flowStep":7,"id":5,"ipAddress":"10.0.0.1","object":4,"objectUuid":"o-1","organisationId":"org","organisationIdType":"uuid","paramsDigest":"pd","processingActivityId":"pa","processingActivityUrl":"https://pa","processingId":"p","register":3,"registerUuid":"r-1","request":"req","resultSummary":{"ok":true},"retentionPeriod":"P1Y","schema":2,"schemaUuid":"s-1","session":"sess","size":42,"toolId":"tool","user":"alice","userName":"Alice","uuid":"u-1","version":"1.2.3"}'),
			$service->computeHash($this->filledRow(), 'prev')
		);
	}//end testSealedHashIsUnchanged()

	/**
	 * The purge flag and the string form.
	 *
	 * @return void
	 */
	public function testPurgeFlagAndStringForm(): void {
		$row = new AuditTrail();
		$this->assertFalse($row->isPurged());
		$this->assertSame('Audit Trail', (string)$row);
		$row->setId(9);
		$this->assertSame('AuditTrail #9', (string)$row);
		$row->setAction('');
		$this->assertSame('AuditTrail #9', (string)$row);
		$row->setAction('create');
		$this->assertSame('Audit: create', (string)$row);
		$row->setUuid('');
		$this->assertSame('Audit: create', (string)$row);
		$row->setUuid('u');
		$this->assertSame('u', (string)$row);
		$row->setPurgedAt(new DateTime('2026-01-01T00:00:00+00:00'));
		$this->assertTrue($row->isPurged());
		$this->assertSame([], (new AuditTrail())->getChanged());
	}//end testPurgeFlagAndStringForm()

	/**
	 * A row with every field set, the unsealed ones included.
	 *
	 * @return AuditTrail
	 */
	private function filledRow(): AuditTrail {
		$row = new AuditTrail();
		$row->hydrate(
			[
				'uuid' => 'u-1',
				'schema' => 2,
				'register' => 3,
				'object' => 4,
				'objectUuid' => 'o-1',
				'registerUuid' => 'r-1',
				'schemaUuid' => 's-1',
				'action' => 'update',
				'changed' => ['title' => ['old' => 'a', 'new' => 'b']],
				'user' => 'alice',
				'userName' => 'Alice',
				'session' => 'sess',
				'request' => 'req',
				'ipAddress' => '10.0.0.1',
				'version' => '1.2.3',
				'created' => new DateTime('2026-01-02T03:04:05+00:00'),
				'organisationId' => 'org',
				'organisationIdType' => 'uuid',
				'processingActivityId' => 'pa',
				'processingActivityUrl' => 'https://pa',
				'processingId' => 'p',
				'confidentiality' => 'internal',
				'retentionPeriod' => 'P1Y',
				'size' => 42,
				'expires' => new DateTime('2027-01-02T03:04:05+00:00'),
				'hash' => 'h',
				'previousHash' => 'ph',
				'importJobId' => 'job',
				'toolId' => 'tool',
				'paramsDigest' => 'pd',
				'resultSummary' => ['ok' => true],
				'purgedAt' => new DateTime('2026-06-01T00:00:00+00:00'),
				'cause' => 'flow',
				'causeRun' => 'cr',
				'flowRun' => 'fr',
				'flowNode' => 'fn',
				'flowStep' => 7,
				'purpose' => 'pp',
				'consumer' => 'cons',
			]
		);
		$row->setId(5);

		return $row;
	}//end filledRow()
}//end class
