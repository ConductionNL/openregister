<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\OpenRegister\Tests\Unit\Service;

use Exception;
use OCA\DAV\CardDAV\CardDavBackend;
use OCA\OpenRegister\Db\ContactLink;
use OCA\OpenRegister\Db\ContactLinkMapper;
use OCA\OpenRegister\Service\ContactService;
use OCA\OpenRegister\Service\LinkSessionRuntime;
use OCP\IURLGenerator;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Pins what deleteLinksForObject() does for each kind of link: the CardDAV reads
 * and writes, the logged lines and the final delete, in call order.
 *
 * @covers \OCA\OpenRegister\Service\ContactService
 * @uses   \OCA\OpenRegister\Db\ContactLink
 */
class ContactServiceDeleteLinksGoldenTest extends TestCase {

	/**
	 * @var list<array>
	 */
	private array $calls = [];

	/**
	 * Every kind of link keeps its calls, in order, and the rows are deleted at the end.
	 *
	 * @return void
	 */
	public function testEveryKindOfLinkIsCleanedBestEffortAndTheRowsAreDeleted(): void {
		$card = "BEGIN:VCARD\nVERSION:3.0\nUID:u1\nFN:Jan\nX-OPENREGISTER-ROLE:old\nX-OPENREGISTER-OBJECT:obj-1\nEND:VCARD\n";

		$cardDav = $this->createMock(CardDavBackend::class);
		$cardDav->method('getCard')->willReturnCallback(function ($addressbookId, $uri) use ($card) {
			$this->calls[] = ['card.get', $addressbookId, $uri];
			if ($uri === 'broken.vcf') {
				throw new Exception('dav down');
			}
			if ($uri === 'gone.vcf') {
				return false;
			}
			return ['carddata' => $card];
		});
		$cardDav->method('updateCard')->willReturnCallback(function ($addressbookId, $uri, $data) {
			$this->calls[] = ['card.update', $addressbookId, $uri, str_replace("\r\n", "\n", $data)];
			return 'etag';
		});

		$links = [];
		foreach ([['jan.vcf', null], ['user', 'alice'], ['gone.vcf', null], ['broken.vcf', null]] as [$uri, $userId]) {
			$link = new ContactLink();
			$link->setObjectUuid('obj-1');
			$link->setAddressbookId(3);
			$link->setContactUri($uri);
			$link->setContactUid('uid-' . $uri);
			if ($userId !== null) {
				$link->setUserId($userId);
			}
			$links[] = $link;
		}

		$mapper = $this->getMockBuilder(ContactLinkMapper::class)
			->disableOriginalConstructor()
			->onlyMethods(['findByObjectUuid', 'deleteByObjectUuid'])
			->getMock();
		$mapper->method('findByObjectUuid')->willReturn($links);
		$mapper->method('deleteByObjectUuid')->willReturnCallback(function ($uuid) {
			$this->calls[] = ['links.delete', $uuid];
			return 4;
		});

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['debug', 'warning'] as $level) {
			$logger->method($level)->willReturnCallback(function ($message) use ($level) {
				$this->calls[] = ['log.' . $level, $message];
			});
		}

		$service = new ContactService(
			$mapper,
			$cardDav,
			new LinkSessionRuntime($this->createMock(IUserSession::class), $logger),
			$this->createMock(IURLGenerator::class)
		);
		$service->deleteLinksForObject('obj-1');

		$this->assertSame(
			[
				['card.get', 3, 'jan.vcf'],
				['card.update', 3, 'jan.vcf', "BEGIN:VCARD\nVERSION:3.0\nUID:u1\nFN:Jan\nEND:VCARD\n"],
				['log.debug', 'User link, no vCard to clean: uid-user'],
				['card.get', 3, 'gone.vcf'],
				['card.get', 3, 'broken.vcf'],
				['log.warning', 'Failed to clean vCard for contact uid-broken.vcf: dav down'],
				['links.delete', 'obj-1'],
			],
			$this->calls
		);
	}
}
