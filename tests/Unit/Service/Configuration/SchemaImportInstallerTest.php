<?php

/**
 * Tests for {@see \OCA\OpenRegister\Service\Configuration\SchemaImportInstaller}:
 * both installers run for an imported schema, and a failure in one is logged
 * without stopping the other.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Configuration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Configuration;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Listener\SchemaFlowImportListenerInterface;
use OCA\OpenRegister\Service\Configuration\SchemaImportInstaller;
use OCA\OpenRegister\Service\Notification\NotificationsAnnotationInstaller;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * SchemaImportInstallerTest.
 */
class SchemaImportInstallerTest extends TestCase {
	private SchemaFlowImportListenerInterface&MockObject $flows;
	private NotificationsAnnotationInstaller&MockObject $notifications;
	private LoggerInterface&MockObject $logger;
	private Schema $schema;

	protected function setUp(): void {
		$this->flows = $this->createMock(SchemaFlowImportListenerInterface::class);
		$this->notifications = $this->createMock(NotificationsAnnotationInstaller::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->schema = new Schema();
		$this->schema->setSlug('case');
	}

	private function install(): void {
		(new SchemaImportInstaller($this->flows, $this->notifications, $this->logger))->install($this->schema);
	}

	public function testBothInstallersRunForTheSchemaWithoutWarnings(): void {
		$this->flows->expects($this->once())->method('importFor')->with($this->schema);
		$this->notifications->expects($this->once())->method('installSchema')->with($this->schema);
		$this->logger->expects($this->never())->method('warning');

		$this->install();
	}

	public function testAFlowFailureIsLoggedAndNotificationsStillInstall(): void {
		$this->flows->method('importFor')->willThrowException(new RuntimeException('bad flow'));
		$this->notifications->expects($this->once())->method('installSchema')->with($this->schema);
		$this->logger->expects($this->once())->method('warning')
			->with('[SchemaImportInstaller] Flows not installed for schema case: bad flow');

		$this->install();
	}

	public function testANotificationFailureIsLogged(): void {
		$this->notifications->method('installSchema')->willThrowException(new RuntimeException('no webhook'));
		$this->logger->expects($this->once())->method('warning')
			->with('[SchemaImportInstaller] Notification webhooks not installed for schema case: no webhook');

		$this->install();
	}
}
