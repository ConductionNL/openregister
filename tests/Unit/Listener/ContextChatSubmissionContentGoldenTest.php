<?php

/**
 * Characterisation test pinning the exact ContentItem the Context Chat
 * submission listener builds: id, provider, title, rendered content,
 * document type, last-modified and audience, plus the no-uuid skip.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Listener;

use DateTime;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Mcp\Listener\ContextChatSubmissionListener;
use OCA\OpenRegister\Mcp\Listener\ContextChatSubmissionServices;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCP\ContextChat\ContentItem;
use OCP\ContextChat\IContentManager;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Golden ContentItem of ContextChatSubmissionListener::submitIfEligible().
 *
 * @covers \OCA\OpenRegister\Mcp\Listener\ContextChatSubmissionListener
 */
class ContextChatSubmissionContentGoldenTest extends TestCase {

	/**
	 * Items the content manager received.
	 *
	 * @var array<int,array{0:string,1:array<int,ContentItem>}>
	 */
	private array $submitted = [];

	/**
	 * Build a listener whose object is publicly readable by alice.
	 *
	 * @return ContextChatSubmissionListener
	 */
	private function listener(): ContextChatSubmissionListener {
		$contentManager = $this->createMock(IContentManager::class);
		$contentManager->method('submitContent')->willReturnCallback(function (string $appId, array $items): void {
			$this->submitted[] = [$appId, $items];
		});
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(fn (string $id) => $id === IContentManager::class ? $contentManager : null);

		$permissions = $this->createMock(PermissionHandler::class);
		$permissions->method('resolveAuthorization')->willReturn(['read' => ['public']]);
		$permissions->method('hasGroupPermission')->willReturn(true);
		$permissions->method('getReadableByUsers')->willReturn(['alice']);

		return new ContextChatSubmissionListener(
			new ContextChatSubmissionServices(
				$container,
				$this->createMock(SchemaMapper::class),
				$this->createMock(IUserManager::class),
				$this->createMock(LoggerInterface::class),
			),
			$permissions,
		);
	}//end listener()

	/**
	 * An opted-in schema with a write-only `secret` property.
	 *
	 * @return Schema
	 */
	private function schema(): Schema {
		$schema = new Schema();
		$schema->setId(3);
		$schema->setConfiguration(['x-openregister-contextchat' => true]);
		$schema->setProperties(['secret' => ['type' => 'string', 'writeOnly' => true], 'title' => ['type' => 'string']]);

		return $schema;
	}//end schema()

	/**
	 * The item renders scalar, non-write-only properties only.
	 *
	 * @return void
	 */
	public function testContentItemIsExact(): void {
		$updated = new DateTime('2020-01-02T03:04:05+00:00');
		$object = new ObjectEntity();
		$object->setUuid('u-9');
		$object->setSchema('3');
		$object->setUpdated($updated);
		$object->setObject(['summary' => 'Sum', 'title' => 'T', 'secret' => 's3', 'n' => 4, 'nested' => ['a' => 1], '@self' => ['id' => 'x']]);

		$this->assertTrue($this->listener()->submitIfEligible($object, $this->schema()));

		$this->assertCount(1, $this->submitted);
		[$appId, $items] = $this->submitted[0];
		$this->assertSame('openregister', $appId);
		$this->assertCount(1, $items);
		$item = $items[0];
		$this->assertSame('u-9', $item->itemId);
		$this->assertSame('openregister_objects', $item->providerId);
		$this->assertSame('Sum', $item->title);
		$this->assertSame('id: u-9' . PHP_EOL . 'summary: Sum' . PHP_EOL . 'title: T' . PHP_EOL . 'n: 4', $item->content);
		$this->assertSame('text/plain', $item->documentType);
		$this->assertSame($updated, $item->lastModified);
		$this->assertSame(['alice'], $item->users);
	}//end testContentItemIsExact()

	/**
	 * An object without a uuid is eligible but nothing is submitted.
	 *
	 * @return void
	 */
	public function testObjectWithoutUuidSubmitsNothing(): void {
		$object = new ObjectEntity();
		$object->setSchema('3');
		$object->setObject(['title' => 'T']);

		$this->assertTrue($this->listener()->submitIfEligible($object, $this->schema()));
		$this->assertSame([], $this->submitted);
	}//end testObjectWithoutUuidSubmitsNothing()
}//end class
