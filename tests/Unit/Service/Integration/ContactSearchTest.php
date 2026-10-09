<?php

/**
 * The contacts leaf's name search (contacts-leaf-cases-panel task 1.2).
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Integration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Integration;

use OCA\OpenRegister\Controller\ContactSearchController;
use OCA\OpenRegister\Service\Integration\ContactSearch;
use OCP\Contacts\IManager;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Search through IManager, and the route over it.
 */
class ContactSearchTest extends TestCase {

	private IManager&MockObject $contacts;

	protected function setUp(): void {
		parent::setUp();
		$this->contacts = $this->createMock(IManager::class);
		$this->contacts->method('isEnabled')->willReturn(true);
	}//end setUp()

	private function search(): ContactSearch {
		return new ContactSearch(contactsManager: $this->contacts, logger: $this->createMock(LoggerInterface::class));
	}//end search()

	/**
	 * A partial name finds the contact, on name, e-mail and organisation, and
	 * the row carries what the detail surface needs to open it.
	 *
	 * @return void
	 */
	public function testAPartialNameFindsTheContact(): void {
		$this->contacts->expects($this->once())->method('search')
			->with('jans', ['FN', 'EMAIL', 'ORG'], $this->arrayHasKey('limit'))
			->willReturn(
				[
					['UID' => 'c-1', 'FN' => 'Jansen, Piet', 'EMAIL' => ['piet@example.nl', 'p@werk.nl'], 'ORG' => 'Gemeente', 'addressbook-key' => '3'],
				]
			);

		$rows = $this->search()->search(query: 'jans');

		$this->assertSame(
			[['uid' => 'c-1', 'fullName' => 'Jansen, Piet', 'email' => 'piet@example.nl', 'organisation' => 'Gemeente', 'addressbook' => '3']],
			$rows
		);
	}//end testAPartialNameFindsTheContact()

	/**
	 * A contact without a UID cannot be opened, so it is left out; one
	 * contact found in two address books is listed once.
	 *
	 * @return void
	 */
	public function testRowsWithoutAUidAreLeftOutAndDuplicatesListedOnce(): void {
		$this->contacts->method('search')->willReturn(
			[
				['FN' => 'Zonder uid'],
				['UID' => 'c-1', 'FN' => 'Jansen, Piet', 'addressbook-key' => '3'],
				['UID' => 'c-1', 'FN' => 'Jansen, Piet', 'addressbook-key' => '4'],
			]
		);

		$rows = $this->search()->search(query: 'jansen');

		$this->assertCount(1, $rows);
		$this->assertSame('c-1', $rows[0]['uid']);
	}//end testRowsWithoutAUidAreLeftOutAndDuplicatesListedOnce()

	/**
	 * A query shorter than two characters asks the address books nothing.
	 *
	 * @return void
	 */
	public function testAOneLetterQueryAsksNothing(): void {
		$this->contacts->expects($this->never())->method('search');

		$this->assertSame([], $this->search()->search(query: ' j '));
	}//end testAOneLetterQueryAsksNothing()

	/**
	 * The route answers the rows, and 400 for a query that is too short.
	 *
	 * @return void
	 */
	public function testTheRouteAnswersTheRowsAndRefusesAShortQuery(): void {
		$this->contacts->method('search')->willReturn([['UID' => 'c-1', 'FN' => 'Jansen, Piet']]);

		$request = $this->createMock(IRequest::class);
		$queries = ['jans', 'j'];
		$request->method('getParam')->willReturnCallback(
			static function (string $key, $default = null) use (&$queries) {
				return ($key === 'q' ? array_shift($queries) : $default);
			}
		);
		$user = $this->createMock(\OCP\IUser::class);
		$session = $this->createMock(\OCP\IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$controller = new ContactSearchController('openregister', $request, $this->search(), $session);

		$found = $controller->search();
		$refused = $controller->search();

		$this->assertSame(200, $found->getStatus());
		$this->assertSame(1, $found->getData()['total']);
		$this->assertSame('c-1', $found->getData()['results'][0]['uid']);
		$this->assertSame(400, $refused->getStatus());
	}//end testTheRouteAnswersTheRowsAndRefusesAShortQuery()
}//end class
