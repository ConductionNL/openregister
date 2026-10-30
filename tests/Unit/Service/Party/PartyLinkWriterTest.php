<?php

/**
 * The party link writer records who linked a party: the signed-in user, or
 * the named linker when nobody is signed in, and refuses when neither is there.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/example-set-parties/specs/example-sets/spec.md#requirement-a-fragment-may-put-parties-on-its-objects
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Party;

use Exception;
use OCA\OpenRegister\Db\ContactLink;
use OCA\OpenRegister\Db\ContactLinkMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Party\PartyDefinition;
use OCA\OpenRegister\Service\Party\PartyLinkWriter;
use OCA\OpenRegister\Service\Party\PartyService;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Party\PartyLinkWriter
 */
class PartyLinkWriterTest extends TestCase {

	/**
	 * A writer over one person party, with the given signed-in user.
	 *
	 * @param string|null $signedIn    The signed-in uid, or null for none.
	 * @param bool        $rbacSeesIt  Whether the RBAC-scoped find returns the party.
	 *
	 * @return PartyLinkWriter
	 */
	private function writer(?string $signedIn, bool $rbacSeesIt=true): PartyLinkWriter {
		$party = new ObjectEntity();
		$party->setUuid('party-a');

		$parties = $this->createMock(originalClassName: PartyService::class);
		$scoped  = null;
		if ($rbacSeesIt === true) {
			$scoped = $party;
		}

		$parties->method('find')->willReturn(value: $scoped);
		$parties->method('findUnscoped')->willReturn(value: $party);
		$parties->method('definitionFor')->willReturn(value: new PartyDefinition(kind: 'person'));
		$parties->method('addressesOfKind')->willReturn(value: []);
		$parties->method('addresses')->willReturn(value: []);

		$links = $this->getMockBuilder(className: ContactLinkMapper::class)
			->disableOriginalConstructor()
			->onlyMethods(['findByObjectContactAndRole', 'insert', 'update'])
			->getMock();
		$links->method('findByObjectContactAndRole')->willReturn(value: null);
		$links->method('insert')->willReturnArgument(0);

		$session = $this->createMock(originalClassName: IUserSession::class);
		$user    = null;
		if ($signedIn !== null) {
			$user = $this->createMock(originalClassName: IUser::class);
			$user->method('getUID')->willReturn(value: $signedIn);
		}

		$session->method('getUser')->willReturn(value: $user);

		return new PartyLinkWriter(links: $links, parties: $parties, session: $session);
	}//end writer()

	/**
	 * Nobody signed in: the named linker is recorded.
	 *
	 * @return void
	 */
	public function testTheNamedLinkerIsRecordedWhenNobodyIsSignedIn(): void {
		$writer = $this->writer(signedIn: null);
		$link   = $writer->write(objectUuid: 'case-1', registerId: 3, schemaId: 9, partyUuid: 'party-a', role: 'gemachtigde', linkedBy: 'dossiq');

		$this->assertSame(expected: 'dossiq', actual: $link->getLinkedBy());
	}//end testTheNamedLinkerIsRecordedWhenNobodyIsSignedIn()

	/**
	 * Somebody signed in: they are the linker, whatever name is passed.
	 *
	 * @return void
	 */
	public function testTheSignedInUserWins(): void {
		$writer = $this->writer(signedIn: 'pieter');
		$link   = $writer->write(objectUuid: 'case-1', registerId: 3, schemaId: 9, partyUuid: 'party-a', role: 'gemachtigde', linkedBy: 'dossiq');

		$this->assertSame(expected: 'pieter', actual: $link->getLinkedBy());
	}//end testTheSignedInUserWins()

	/**
	 * Nobody signed in and nobody named: refused with 401.
	 *
	 * @return void
	 */
	public function testNoLinkerIsRefused(): void {
		$this->expectException(exception: Exception::class);
		$this->expectExceptionCode(code: 401);

		$this->writer(signedIn: null)->write(objectUuid: 'case-1', registerId: 3, schemaId: 9, partyUuid: 'party-a', role: 'gemachtigde');
	}//end testNoLinkerIsRefused()

	/**
	 * A trusted import (nobody signed in, a linker named) links a party that
	 * the RBAC-scoped read does not return to "Anonymous".
	 *
	 * @return void
	 */
	public function testATrustedImportFindsThePartyWithoutRbac(): void {
		$writer = $this->writer(signedIn: null, rbacSeesIt: false);
		$link   = $writer->write(objectUuid: 'case-1', registerId: 3, schemaId: 9, partyUuid: 'party-a', role: 'gemachtigde', linkedBy: 'dossiq');

		$this->assertSame(expected: 'dossiq', actual: $link->getLinkedBy());
	}//end testATrustedImportFindsThePartyWithoutRbac()

	/**
	 * A signed-in caller reads with their own rights: a party they cannot
	 * see is not found, even when a linker is named.
	 *
	 * @return void
	 */
	public function testASignedInCallerFindsThePartyWithTheirRights(): void {
		$this->expectException(exception: Exception::class);
		$this->expectExceptionCode(code: 404);

		$writer = $this->writer(signedIn: 'pieter', rbacSeesIt: false);
		$writer->write(objectUuid: 'case-1', registerId: 3, schemaId: 9, partyUuid: 'party-a', role: 'gemachtigde', linkedBy: 'dossiq');
	}//end testASignedInCallerFindsThePartyWithTheirRights()
}//end class
