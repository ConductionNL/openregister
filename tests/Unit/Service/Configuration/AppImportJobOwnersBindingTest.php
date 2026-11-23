<?php

/**
 * The Core port for an import job's owning app is answered by the app import
 * job recorder, bound in Configuration's own bindings.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Configuration
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Configuration;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.

use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\Core\AppImportJobOwnersInterface;
use OCA\OpenRegister\Service\Configuration\AppImportJobRecorder;
use OCA\OpenRegister\Service\ContractBindings;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @covers \OCA\OpenRegister\Service\Configuration\AppImportJobRecorder
 */
final class AppImportJobOwnersBindingTest extends TestCase {

	public function testTheRecorderAnswersThePort(): void {
		self::assertTrue(is_subclass_of(AppImportJobRecorder::class, AppImportJobOwnersInterface::class));
		self::assertSame(AppImportJobRecorder::class, ContractBindings::BINDINGS[AppImportJobOwnersInterface::class] ?? null);
	}

	public function testThePortNamesTheAppThatRecordedTheJob(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getKeys')->willReturn(['import_jobs_myapp', 'other_key']);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key): string => $key === 'import_jobs_myapp'
				? (string) json_encode(['appId' => 'myapp', 'jobs' => [['jobId' => 'j1']]])
				: ''
		);

		$owners = new AppImportJobRecorder(auditTrailMapper: $this->createMock(AuditTrailMapperInterface::class), appConfig: $config, logger: new NullLogger());

		self::assertInstanceOf(AppImportJobOwnersInterface::class, $owners);
		self::assertSame('myapp', $owners->appForJob(importJobId: 'j1'));
		self::assertNull($owners->appForJob(importJobId: 'unknown'));
	}
}
