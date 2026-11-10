<?php

/**
 * What the Contacts object source answers when the contacts manager fails,
 * and how an EMAIL value of any shape becomes one address.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\ObjectSource
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ObjectSource;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\ObjectSource\ContactsObjectSourceProvider;
use OCP\App\IAppManager;
use OCP\Contacts\IManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Failure and email-shape tests for {@see ContactsObjectSourceProvider}.
 */
class ContactsObjectSourceProviderFailureTest extends TestCase {

	/**
	 * A failing contacts manager reads as no contacts, logged.
	 *
	 * @return void
	 */
	public function testAFailingContactsManagerReadsAsNoContacts(): void {
		$manager = $this->createMock(IManager::class);
		$manager->method('search')->willThrowException(new RuntimeException('dav down'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->exactly(2))->method('warning')->with('[ObjectSource:contacts-source] could not list contacts: dav down');
		$provider = new ContactsObjectSourceProvider(contactsManager: $manager, app: new \OCA\OpenRegister\Service\ObjectSource\OptionalAppBridge(appManager: $this->createMock(IAppManager::class), container: $this->createMock(\Psr\Container\ContainerInterface::class), logger: $logger));

		$this->assertSame([], $provider->findAll(register: new Register(), schema: new Schema()));
		$this->assertNull($provider->find(register: new Register(), schema: new Schema(), id: 'c-1'));
	}//end testAFailingContactsManagerReadsAsNoContacts()

	/**
	 * A string email is kept, a list yields its first string, a list whose
	 * first entry is not a string and a non-string value yield ''.
	 *
	 * @return void
	 */
	public function testEveryEmailShapeBecomesOneAddress(): void {
		$manager = $this->createMock(IManager::class);
		$manager->method('search')->with('', ['FN', 'EMAIL', 'ORG', 'UID'])->willReturn(
			[
				['UID' => 'a', 'EMAIL' => 'a@example.org'],
				['UID' => 'b', 'EMAIL' => ['b1@example.org', 'b2@example.org']],
				['UID' => 'c', 'EMAIL' => [['type' => 'work']]],
				['UID' => 'd', 'EMAIL' => 42],
			]
		);
		$provider = new ContactsObjectSourceProvider(contactsManager: $manager, app: new \OCA\OpenRegister\Service\ObjectSource\OptionalAppBridge(appManager: $this->createMock(IAppManager::class), container: $this->createMock(\Psr\Container\ContainerInterface::class), logger: $this->createMock(LoggerInterface::class)));

		$emails = array_map(static fn ($object): string => $object->getObject()['email'], $provider->findAll(register: new Register(), schema: new Schema()));

		$this->assertSame(['a@example.org', 'b1@example.org', '', ''], $emails);
	}//end testEveryEmailShapeBecomesOneAddress()
}//end class
