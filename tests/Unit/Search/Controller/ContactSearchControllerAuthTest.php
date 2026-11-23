<?php

/**
 * ContactSearchController refuses an anonymous caller before searching.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Search\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Search\Controller;

use OCA\OpenRegister\Search\Controller\ContactSearchController;
use OCA\OpenRegister\Search\Service\Integration\ContactSearch;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * The authentication gate of the contact search route.
 */
class ContactSearchControllerAuthTest extends TestCase {

	private function controller(?IUser $user, ContactSearch $search): ContactSearchController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturn('jansen');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return new ContactSearchController($request, new \OCA\OpenRegister\Search\Controller\Support\ContactSearchControllerServices($search, $session));
	}

	public function testAnonymousCallerIs401AndNothingIsSearched(): void {
		$search = $this->createMock(ContactSearch::class);
		$search->expects($this->never())->method('search');

		$response = $this->controller(null, $search)->search();

		$this->assertSame(401, $response->getStatus());
		$this->assertSame(['error' => 'Authentication required', 'results' => [], 'total' => 0], $response->getData());
	}

	public function testSignedInCallerIsSearchedWithTheTrimmedQuery(): void {
		$search = $this->createMock(ContactSearch::class);
		$search->expects($this->once())->method('search')->with('jansen')->willReturn([['uid' => 'c-1'], ['uid' => 'c-2']]);

		$response = $this->controller($this->createMock(IUser::class), $search)->search();

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['results' => [['uid' => 'c-1'], ['uid' => 'c-2']], 'total' => 2], $response->getData());
	}
}
