<?php

/**
 * The mail thread contract dossiq reads: the route, the answer's keys, and the granted resolve.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Contract
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/reply-threading-by-headers/specs/integration-email/spec.md#requirement-a-message-is-read-with-the-messages-around-it-req-rth-011
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Contract;

use OCA\OpenRegister\Contract\ReplyThreadResolutionInterface;
use OCA\OpenRegister\Files\Db\MailHeader;
use OCA\OpenRegister\Files\Db\MailHeaderMapper;
use OCA\OpenRegister\Notifications\Service\ReplyThreadService;
use OCA\OpenRegister\Service\Mail\MailThreadAssembler;
use OCA\OpenRegister\Service\Mail\MailThreadService;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * @covers \OCA\OpenRegister\Service\Mail\MailThreadService
 * @uses   \OCA\OpenRegister\Service\Mail\MailThreadAssembler
 * @uses   \OCA\OpenRegister\Files\Db\MailHeader
 */
final class MailThreadContractTest extends TestCase {

	public function testTheRouteIsFixed(): void {
		$routes = require __DIR__ . '/../../../appinfo/routes.php';
		$found = array_values(
			array_filter(
				$routes['routes'],
				static fn (array $route): bool => $route['name'] === 'fileSidebar#getThread' && $route['url'] === '/api/files/{fileId}/thread'
			)
		);

		$this->assertCount(1, $found, 'the canonical route; ApiPathAliases adds its alias beside it');
		$this->assertSame(['GET', ['fileId' => '[0-9]+']], [$found[0]['verb'], $found[0]['requirements']]);
	}

	public function testTheAnswerCarriesExactlyThreadAndPositionAndEachNodeTheContractKeys(): void {
		$file = $this->createMock(File::class);
		$folder = $this->createMock(Folder::class);
		$file->method('getId')->willReturn(7);
		$file->method('getName')->willReturn('verzoek.eml');
		$file->method('getParent')->willReturn($folder);
		$folder->method('getDirectoryListing')->willReturn([$file]);
		$root = $this->createMock(IRootFolder::class);
		$root->method('getById')->willReturn([$file]);
		$headers = $this->createMock(MailHeaderMapper::class);
		$headers->method('findByFileIds')->willReturn(
			[MailHeader::fromHeaders(fileId: 7, headers: ['messageId' => 'a2@x', 'inReplyTo' => 'a1@x', 'date' => 'Mon, 02 Mar 2026 10:00:00 +0000'])]
		);

		$answer = (new MailThreadService(headers: $headers, rootFolder: $root, assembler: new MailThreadAssembler()))
			->threadOf(fileId: 7, mayRead: static fn (int $id): bool => true);

		$this->assertSame(['thread', 'position'], array_keys($answer));
		$this->assertIsInt($answer['position']);
		foreach ($answer['thread'] as $node) {
			$this->assertSame(['fileId', 'messageId', 'parent', 'sentAt', 'from', 'subject', 'gap'], array_keys($node));
			$this->assertIsBool($node['gap']);
		}

		$this->assertSame([null, true], [$answer['thread'][0]['fileId'], $answer['thread'][0]['gap']], 'the parent outside the set is a gap');
		$this->assertSame(7, $answer['thread'][$answer['position']]['fileId']);
	}

	public function testTheGrantedResolveSignatureIsFixedAndBound(): void {
		$method = new ReflectionMethod(ReplyThreadResolutionInterface::class, 'resolveAsSystem');

		$this->assertSame('array', (string) $method->getReturnType());
		$this->assertSame(['headers'], array_map(static fn ($p) => $p->getName(), $method->getParameters()));
		$this->assertSame('array', (string) $method->getParameters()[0]->getType());
		$this->assertTrue(is_subclass_of(ReplyThreadService::class, ReplyThreadResolutionInterface::class));
	}
}
