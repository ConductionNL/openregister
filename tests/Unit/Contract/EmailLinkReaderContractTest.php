<?php

/**
 * The published read contract for the emails linked to an object.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Contract
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/changes/email-link-reader-contract/specs/generic-integrations/spec.md
 */

declare(strict_types=1);

namespace Unit\Contract;

use DateTime;
use OCA\OpenRegister\Contract\EmailLinkReaderInterface;
use OCA\OpenRegister\Db\EmailLink;
use OCA\OpenRegister\Db\EmailLinkMapper;
use OCA\OpenRegister\Service\EmailLinkService;
use OCP\App\IAppManager;
use OCP\IDBConnection;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * A consuming app reads an object's linked emails through the interface.
 */
class EmailLinkReaderContractTest extends TestCase {

	/**
	 * The link service is the published reader.
	 *
	 * @return void
	 */
	public function testTheLinkServiceIsTheReader(): void {
		$this->assertTrue(condition: is_a(EmailLinkService::class, EmailLinkReaderInterface::class, true));
	}//end testTheLinkServiceIsTheReader()

	/**
	 * The container answers the interface with the link service, so an app
	 * can inject the interface.
	 *
	 * @return void
	 */
	public function testTheContainerBindsTheInterface(): void {
		$source = (string)file_get_contents(filename: __DIR__ . '/../../../lib/Service/ServiceRegistrar.php');

		$this->assertMatchesRegularExpression(
			pattern: '/registerService\(\s*EmailLinkReaderInterface::class,\s*function \(\$c\) \{\s*return \$c->get\(EmailLinkService::class\);/',
			string: $source
		);
	}//end testTheContainerBindsTheInterface()

	/**
	 * Read through the interface, the linked emails come in the documented shape.
	 *
	 * @return void
	 */
	public function testReadingThroughTheInterface(): void {
		$link = new EmailLink();
		$link->setId(4);
		$link->setObjectUuid('client-1');
		$link->setSubject('Subsidieformulier en de lijst met documenten');
		$link->setSender('fatma@zuiddrecht.nl');
		$link->setMailDate(new DateTime('2026-10-06T16:05:00+00:00'));

		$mapper = $this->getMockBuilder(className: EmailLinkMapper::class)
			->disableOriginalConstructor()
			->onlyMethods(['findByObjectUuid', 'countByObjectUuid'])
			->getMock();
		$mapper->method('findByObjectUuid')->with('client-1', 51, 0)->willReturn([$link]);
		$mapper->method('countByObjectUuid')->willReturn(1);

		$reader = new EmailLinkService(
			$mapper,
			$this->createMock(originalClassName: IAppManager::class),
			$this->createMock(originalClassName: IDBConnection::class),
			$this->createMock(originalClassName: IUserSession::class),
			$this->createMock(originalClassName: LoggerInterface::class)
		);

		$result = $this->readThrough(reader: $reader);

		$this->assertSame(expected: 1, actual: $result['total']);
		$this->assertNull(actual: $result['nextCursor']);
		$this->assertSame(expected: 'Subsidieformulier en de lijst met documenten', actual: $result['items'][0]['subject']);
		$this->assertSame(expected: 'fatma@zuiddrecht.nl', actual: $result['items'][0]['sender']);
		$this->assertSame(expected: '2026-10-06T16:05:00+00:00', actual: $result['items'][0]['mailDate']);
	}//end testReadingThroughTheInterface()

	/**
	 * Call the reader as a consumer does: typed on the interface only.
	 *
	 * @param EmailLinkReaderInterface $reader The reader.
	 *
	 * @return array The linked emails.
	 */
	private function readThrough(EmailLinkReaderInterface $reader): array {
		return $reader->getLinkedEmails(objectUuid: 'client-1');
	}//end readThrough()
}//end class
