<?php

/**
 * Party matching against a case's own data: the two shapes, the key order,
 * and the loud refusal when the case names nobody.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Portal
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Portal;

use OCA\OpenRegister\Db\AbstractObjectMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Exception\PortalPartyNotFoundException;
use OCA\OpenRegister\Service\Portal\PortalPartyResolver;
use OCA\OpenRegister\Service\Portal\PortalSubject;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Tests for {@see PortalPartyResolver}.
 *
 * @covers \OCA\OpenRegister\Service\Portal\PortalPartyResolver
 * @covers \OCA\OpenRegister\Service\Portal\PortalSubject
 * @covers \OCA\OpenRegister\Exception\PortalPartyNotFoundException
 */
class PortalPartyResolverTest extends TestCase {

	/**
	 * Case shapes and the reference each resolves to.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: string, 2: string}>
	 */
	public static function caseShapes(): array {
		return [
			'a scalar field named for the role' => [['initiator' => 'bsn-123'], 'initiator', 'party:bsn-123'],
			'an object field with a subjectRef' => [['initiator' => ['subjectRef' => 'sub-1', 'bsn' => 'ignored']], 'initiator', 'party:sub-1'],
			'an object field falling back to bsn' => [['initiator' => ['name' => 'Jan', 'bsn' => '999']], 'initiator', 'party:999'],
			'a nested identification' => [['initiator' => ['betrokkeneIdentificatie' => ['inpBsn' => 'x', 'bsn' => '555']]], 'initiator', 'party:555'],
			'a ZGW rollen list' => [
				['rollen' => [['roltype' => 'behandelaar', 'betrokkene' => 'u1'], ['roltype' => 'Initiator', 'betrokkeneIdentificatie' => ['bsn' => '777']]]],
				'initiator',
				'party:777',
			],
			'a parties list with a custom role' => [
				['parties' => [['role' => 'applicant', 'subjectRef' => 'kvk-1']]],
				'applicant',
				'party:kvk-1',
			],
			'a blank role falls back to initiator' => [['initiator' => 'a'], '', 'party:a'],
		];
	}//end caseShapes()

	/**
	 * Each shape resolves to the expected party reference.
	 *
	 * @param array<string, mixed> $case The case data.
	 * @param string $role The role.
	 * @param string $expected The party reference.
	 *
	 * @return void
	 */
	#[DataProvider('caseShapes')]
	public function testTheCaseShapesResolve(array $case, string $role, string $expected): void {
		$this->assertSame($expected, (new PortalPartyResolver())->resolve(case: $case, role: $role, caseUuid: 'c-1'));
	}//end testTheCaseShapesResolve()

	/**
	 * A case naming nobody for the role refuses, naming the role and the case.
	 *
	 * @return void
	 */
	public function testACaseNamingNobodyRefusesNamingRoleAndCase(): void {
		$resolver = new PortalPartyResolver();
		foreach ([[], ['initiator' => ''], ['initiator' => ['name' => 'nobody']], ['rollen' => [['roltype' => 'behandelaar', 'bsn' => '1']]]] as $case) {
			try {
				$resolver->resolve(case: $case, role: 'initiator', caseUuid: 'case-7');
				$this->fail('Expected a refusal for ' . json_encode($case));
			} catch (PortalPartyNotFoundException $refused) {
				$this->assertStringContainsString("role 'initiator'", $refused->getMessage());
				$this->assertStringContainsString("'case-7'", $refused->getMessage());
			}
		}
	}//end testACaseNamingNobodyRefusesNamingRoleAndCase()

	/**
	 * Resolution by uuid reads the case through the object store, without
	 * the session's RBAC (the node runs in a background worker).
	 *
	 * @return void
	 */
	public function testResolutionByUuidReadsTheCaseFromTheStore(): void {
		$object = $this->createMock(ObjectEntity::class);
		$object->method('getObject')->willReturn(['initiator' => ['subjectRef' => 'sub-9']]);
		$objects = $this->createMock(AbstractObjectMapper::class);
		$objects->expects($this->once())
			->method('find')
			->with('case-7', null, null, false, false, false)
			->willReturn($object);

		$this->assertSame('party:sub-9', (new PortalPartyResolver(objects: $objects))->resolveFromObject(objectUuid: 'case-7', role: 'initiator'));
	}//end testResolutionByUuidReadsTheCaseFromTheStore()

	/**
	 * An unreadable case and an absent store both refuse rather than match nobody quietly.
	 *
	 * @return void
	 */
	public function testAnUnreadableCaseOrAbsentStoreRefuses(): void {
		$objects = $this->createMock(AbstractObjectMapper::class);
		$objects->method('find')->willThrowException(new DoesNotExistException('gone'));
		try {
			(new PortalPartyResolver(objects: $objects))->resolveFromObject(objectUuid: 'case-7', role: 'initiator');
			$this->fail('Expected a refusal.');
		} catch (PortalPartyNotFoundException $refused) {
			$this->assertStringContainsString('could not be read', $refused->getMessage());
		}

		$this->expectException(PortalPartyNotFoundException::class);
		$this->expectExceptionMessageMatches('/no object store/');
		(new PortalPartyResolver())->resolveFromObject(objectUuid: 'case-7', role: 'initiator');
	}//end testAnUnreadableCaseOrAbsentStoreRefuses()

	/**
	 * The subject value: its party reference and actor are the prefixed subjectRef.
	 *
	 * @return void
	 */
	public function testTheSubjectValueBuildsThePartyReference(): void {
		$subject = new PortalSubject(subjectRef: 'sub-1', audience: 'client', organisation: 'org', trust: 'substantial', jti: 'j');
		$this->assertSame('party:sub-1', $subject->partyReference());
		$this->assertSame('party:sub-1', $subject->actor());
		$this->assertSame('party:sub-1', PortalSubject::partyReferenceFor(reference: ' sub-1 '));
	}//end testTheSubjectValueBuildsThePartyReference()
}//end class
