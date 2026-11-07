<?php

/**
 * The schema save refuses a `url` action the dispatcher would drop.
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

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\SchemaMapper\NotificationAnnotationCheck;
use OCA\OpenRegister\Notifications\Service\Notification\NotificationDeclarationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\Db\SchemaMapper\NotificationAnnotationCheck
 * @covers \OCA\OpenRegister\Db\SchemaMapper
 * @covers \OCA\OpenRegister\Notifications\Service\Notification\NotificationDeclarationException
 * @covers \OCA\OpenRegister\Notifications\Service\Notification\LinkableUrl
 * @uses   \OCA\OpenRegister\Db\Schema
 */
final class NotificationActionHrefRefusalTest extends TestCase {

	private static function schemaWith(array $target): Schema {
		$schema = new Schema();
		$schema->setSlug('case');
		$schema->setConfiguration([
			'x-openregister-notifications' => [
				'opened' => [
					'trigger' => ['type' => 'created'],
					'channels' => ['nc-notification'],
					'recipients' => [['kind' => 'users', 'users' => ['admin']]],
					'subject' => 'Opened',
					'actions' => [['label' => ['nl' => 'Open'], 'target' => $target]],
				],
			],
		]);

		return $schema;
	}//end schemaWith()

	public static function refused(): array {
		return [
			'script through a host form' => ['javascript://x/%0aalert(1)'],
			'script' => ['javascript:alert(1)'],
			'other scheme' => ['custom://example.test/call'],
			'file transfer' => ['ftp://example.test/call'],
			'phone' => ['tel:+31201234567'],
			'not a url' => ['call'],
			'broken host' => ['http://exa mple.test/call'],
			'empty' => [''],
		];
	}//end refused()

	#[DataProvider('refused')]
	public function testARefusedHrefFailsTheSave(string $href): void {
		$checks = new NotificationAnnotationCheck(logger: $this->createMock(LoggerInterface::class));

		try {
			$checks->check(schema: self::schemaWith(['kind' => 'url', 'href' => $href]));
			$this->fail('The save went through.');
		} catch (NotificationDeclarationException $refusal) {
			$this->assertSame('notification-action-bad-href', $refusal->getErrors()[0]['code']);
			$this->assertStringContainsString('"opened"', $refusal->getMessage());
		}
	}//end testARefusedHrefFailsTheSave()

	public function testAMissingHrefFailsTheSave(): void {
		$this->expectException(NotificationDeclarationException::class);
		$checks = new NotificationAnnotationCheck(logger: $this->createMock(LoggerInterface::class));
		$checks->check(schema: self::schemaWith(['kind' => 'url']));
	}//end testAMissingHrefFailsTheSave()

	public static function accepted(): array {
		return [
			'https' => [['kind' => 'url', 'href' => 'https://example.test/call']],
			'http' => [['kind' => 'url', 'href' => 'http://example.test/call']],
			'upper case scheme' => [['kind' => 'url', 'href' => 'HTTPS://example.test/call']],
			'mail' => [['kind' => 'url', 'href' => 'mailto:info@example.test']],
			'other kind' => [['kind' => 'object-detail']],
		];
	}//end accepted()

	#[DataProvider('accepted')]
	public function testALinkableTargetSaves(array $target): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('warning');

		(new NotificationAnnotationCheck(logger: $logger))->check(schema: self::schemaWith($target));
	}//end testALinkableTargetSaves()

	public function testAnImportOnlyWarnsAboutARefusedHref(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with($this->stringContains('target.href "call" is not an absolute URL'));

		(new NotificationAnnotationCheck(logger: $logger))->warn(schema: self::schemaWith(['kind' => 'url', 'href' => 'call']));
	}//end testAnImportOnlyWarnsAboutARefusedHref()

	public function testTheMapperWarnsOnlyWhileAnImportAsksIt(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning');
		$mapper = (new \ReflectionClass(SchemaMapper::class))->newInstanceWithoutConstructor();
		$check = function (Schema $schema): void {
			$this->checkDataDeclarations(schema: $schema);
		};
		(function (LoggerInterface $logger): void {
			$this->logger = $logger;
		})->call($mapper, $logger);
		$schema = self::schemaWith(['kind' => 'url', 'href' => 'call']);

		$this->assertFalse($mapper->warnOnUnlinkableNotificationActions());
		$check->call($mapper, $schema);
		$this->assertTrue($mapper->warnOnUnlinkableNotificationActions());
		$mapper->refuseUnlinkableNotificationActions();

		$this->expectException(NotificationDeclarationException::class);
		$check->call($mapper, $schema);
	}//end testTheMapperWarnsOnlyWhileAnImportAsksIt()

	public function testAnotherFaultStaysAWarning(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning');

		(new NotificationAnnotationCheck(logger: $logger))->check(schema: self::schemaWith(['kind' => 'nonsense']));
	}//end testAnotherFaultStaysAWarning()
}//end class
