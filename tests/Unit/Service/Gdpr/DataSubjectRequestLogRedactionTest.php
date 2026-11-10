<?php

declare(strict_types=1);

/**
 * DataSubjectRequestService log redaction test.
 *
 * A failed GdprEntity lookup is logged as a warning. The warning must not
 * carry the data subject's identifier: the app log is not a place for
 * personal data, and neither is a driver message that can quote the bound
 * parameters back.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Gdpr
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

namespace OCA\OpenRegister\Tests\Unit\Service\Gdpr;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Archival\ArchivalRetentionGuard;
use OCA\OpenRegister\Service\DsarService;
use OCA\OpenRegister\Service\Gdpr\DataSubjectDeadline;
use OCA\OpenRegister\Service\Gdpr\DataSubjectRequestService;
use OCA\OpenRegister\Service\Gdpr\SubjectQuery;
use OCA\OpenRegister\Service\Gdpr\SubjectRecords;
use OCA\OpenRegister\Service\Gdpr\Subject\SubjectEntityIndex;
use OCA\OpenRegister\Service\Gdpr\Subject\SubjectObjectLoader;
use OCA\OpenRegister\Service\Gdpr\Subject\SubjectObjectWriter;
use OCA\OpenRegister\Service\Gdpr\Subject\SubjectRetentionGate;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\RetentionService;
use OCP\IDBConnection;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The lookup-failure warning names no data subject.
 */
class DataSubjectRequestLogRedactionTest extends TestCase {
	/**
	 * A failed lookup logs a warning without the subject identifier and finds nothing.
	 *
	 * @return void
	 */
	public function testLookupFailureWarningOmitsSubject(): void {
		$subject = 'jan.jansen@example.org';
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willThrowException(new RuntimeException('query failed with params ["' . $subject . '"]'));

		$contexts = [];
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string $message, array $context = []) use (&$contexts): void {
				$contexts[] = $message . ' ' . json_encode($context);
			}
		);

		$service = new DataSubjectRequestService(new SubjectRecords(new SubjectEntityIndex($db, $logger), new SubjectObjectLoader($this->createMock(MagicMapper::class), $this->createMock(LoggerInterface::class)), new SubjectObjectWriter(
				$this->createMock(ObjectService::class),
				$this->createMock(DsarService::class),
				$this->createMock(IUserSession::class),
				$this->createMock(LoggerInterface::class)
			)), new SubjectRetentionGate(
				$this->createMock(RetentionService::class),
				new ArchivalRetentionGuard(
					$this->createMock(SchemaMapper::class),
					$this->createMock(LoggerInterface::class)
				)
			), new DataSubjectDeadline(), $this->createMock(LoggerInterface::class));

		$result = $service->findSubjectObjects(new SubjectQuery($subject));

		$this->assertSame([], $result);
		$this->assertCount(1, $contexts);
		$this->assertStringContainsString('[DSR] GdprEntity lookup failed', $contexts[0]);
		$this->assertStringNotContainsString('jan.jansen', $contexts[0]);
	}//end testLookupFailureWarningOmitsSubject()
}//end class
