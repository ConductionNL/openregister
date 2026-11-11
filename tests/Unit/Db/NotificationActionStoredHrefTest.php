<?php

/**
 * An update that leaves a stored unlinkable url action as it was only warns about it.
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

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Notifications\Service\Notification\NotificationDeclarationException;
use OCA\OpenRegister\Service\Schemas\PropertyValidatorHandler;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Db\SchemaMapper\SchemaMapperServices;
use OCA\OpenRegister\Db\Tenancy\TenancyServices;

final class NotificationActionStoredHrefTest extends TestCase {
	use RecordingQueryBuilderTrait;

	/**
	 * The notification warnings the mapper logged.
	 *
	 * @var list<string>
	 */
	private array $warnings = [];

	private static function notifications(string $href): array {
		return [
			'opened' => [
				'trigger' => ['type' => 'created'],
				'channels' => ['nc-notification'],
				'recipients' => [['kind' => 'users', 'users' => ['admin']]],
				'subject' => 'Opened',
				'actions' => [['label' => ['nl' => 'Open'], 'target' => ['kind' => 'url', 'href' => $href]]],
			],
		];
	}//end notifications()

	private function storeRowWith(string $href): void {
		$configuration = json_encode(['x-openregister-notifications' => self::notifications($href)]);
		$this->queuedRows = [[['id' => 7, 'uuid' => 'u-7', 'title' => 'Old', 'version' => '1.0.0', 'configuration' => $configuration]]];
	}//end storeRowWith()

	private function edited(string $href): Schema {
		$schema = new Schema();
		$schema->setId(7);
		$schema->setTitle('New');
		$schema->setConfiguration(['x-openregister-notifications' => self::notifications($href)]);

		return $schema;
	}//end edited()

	public function testAnEditThatKeepsTheStoredActionSavesWithAWarning(): void {
		$this->storeRowWith('call');
		$mapper = $this->mapper();

		$mapper->update($this->edited('call'));

		$this->assertCount(1, $this->warnings);
		$this->assertStringContainsString('target.href "call" is not an absolute URL', $this->warnings[0]);
		$this->assertFalse($mapper->warnOnUnlinkableNotificationActions(), 'the mapper is back to refusing');
	}//end testAnEditThatKeepsTheStoredActionSavesWithAWarning()

	public function testAnEditThatWritesANewUnlinkableActionIsRefused(): void {
		$this->storeRowWith('https://example.test/call');

		$this->expectException(NotificationDeclarationException::class);
		$this->mapper()->update($this->edited('call'));
	}//end testAnEditThatWritesANewUnlinkableActionIsRefused()

	public function testAnEditThatChangesAStoredUnlinkableActionIsRefused(): void {
		$this->storeRowWith('call');

		$this->expectException(NotificationDeclarationException::class);
		$this->mapper()->update($this->edited('ftp://example.test/call'));
	}//end testAnEditThatChangesAStoredUnlinkableActionIsRefused()

	public function testAnImportKeepsWarningAfterAnEditThatKeepsTheAction(): void {
		$this->storeRowWith('call');
		$mapper = $this->mapper();
		$mapper->warnOnUnlinkableNotificationActions();

		$mapper->update($this->edited('call'));

		$this->assertTrue($mapper->warnOnUnlinkableNotificationActions(), 'the import\'s setting stays');
	}//end testAnImportKeepsWarningAfterAnEditThatKeepsTheAction()

	private function mapper(): SchemaMapper {
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(fn () => $this->recordingBuilder());
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string|\Stringable $message): void {
				if (str_contains((string) $message, 'x-openregister-notifications') === true) {
					$this->warnings[] = (string) $message;
				}
			}
		);

		return new SchemaMapper(
			db: $db,
			tenancy: new TenancyServices(
				organisationMapper: $this->createMock(OrganisationMapper::class),
				userSession: $this->createMock(IUserSession::class),
				groupManager: $this->createMock(IGroupManager::class)
			),
			appConfig: $this->createMock(IAppConfig::class),
			services: new SchemaMapperServices(
				eventDispatcher: $this->createMock(IEventDispatcher::class),
				validator: $this->createMock(PropertyValidatorHandler::class),
				logger: $logger
			)
		);
	}//end mapper()
}//end class
