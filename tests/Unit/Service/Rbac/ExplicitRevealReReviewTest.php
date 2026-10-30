<?php

/**
 * The #4843 re-review: related-row filters, the revert answer, the accepted
 * declaration form, uuid-only grants and the administrator's trail lists.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/property-explicit-reveal/specs/row-field-level-security/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\Rbac;

use OCA\OpenRegister\Controller\RevertController;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\MagicMapper\MagicRbacHandler;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Exception\ExplicitRevealQueryException;
use OCA\OpenRegister\Service\Object\RevertHandler;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\PropertyRbacHandler;
use OCA\OpenRegister\Service\Query\PropertyReadGuard;
use OCA\OpenRegister\Service\Query\RelatedRowAccess;
use OCA\OpenRegister\Service\Query\RelatedRowFilter;
use OCA\OpenRegister\Service\Rbac\ExplicitReveal;
use OCA\OpenRegister\Service\Rbac\ExplicitRevealMasker;
use OCA\OpenRegister\Service\Rbac\ExplicitRevealTrailMasker;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IRequest;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\Service\Query\RelatedRowAccess
 * @covers \OCA\OpenRegister\Controller\RevertController
 * @covers \OCA\OpenRegister\Service\Rbac\ExplicitReveal
 * @covers \OCA\OpenRegister\Service\Rbac\ExplicitRevealMasker
 * @covers \OCA\OpenRegister\Service\Rbac\ExplicitRevealTrailMasker
 */
class ExplicitRevealReReviewTest extends TestCase {

	private const BSN = '123457609';

	private const UUID = '11111111-1111-4111-8111-111111111111';

	/**
	 * A person schema whose BSN is explicit-reveal.
	 *
	 * @param array<string, mixed> $bsn The BSN property definition.
	 *
	 * @return Schema The schema.
	 */
	private function schema(array $bsn = ['type' => 'string', 'authorization' => ['read' => [['group' => 'sensitive']], 'audit' => true, 'reveal' => 'explicit']]): Schema {
		$schema = new Schema();
		$schema->setId(7);
		$schema->setSlug('brpPerson');
		$schema->setProperties(['displayName' => ['type' => 'string'], 'citizenServiceNumber' => $bsn]);

		return $schema;
	}//end schema()

	/**
	 * A `_related` condition on an explicit-reveal property of the related schema is refused.
	 *
	 * @return void
	 */
	public function testARelatedRowFilterOnItIsRefused(): void {
		$guard = $this->createMock(PropertyReadGuard::class);
		$guard->method('mayRead')->willReturn(true);
		$access = new RelatedRowAccess($this->createMock(MagicRbacHandler::class), $this->createMock(LoggerInterface::class), $guard);
		$filter = new RelatedRowFilter(schema: 'brpPerson', foreignKey: 'displayName', conditions: [['field' => 'citizenServiceNumber', 'value' => self::BSN]]);

		$this->expectException(ExplicitRevealQueryException::class);
		$access->assertReadable(filter: $filter, schema: $this->schema());
	}//end testARelatedRowFilterOnItIsRefused()

	/**
	 * The revert answer is the rendered object, not the stored entity.
	 *
	 * @return void
	 */
	public function testTheRevertAnswerIsRendered(): void {
		$entity = new ObjectEntity();
		$entity->setUuid(self::UUID);
		$entity->setObject(['citizenServiceNumber' => self::BSN]);
		$revert = $this->createMock(RevertHandler::class);
		$revert->method('revert')->willReturn($entity);
		$objects = $this->createMock(ObjectService::class);
		$objects->expects($this->once())->method('renderEntity')->willReturn(['citizenServiceNumber' => '*****7609']);
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn(['version' => '1.0.0']);

		$response = (new RevertController('openregister', $request, $revert, $objects))->revert('dossiq', 'brpPerson', self::UUID);

		$this->assertSame(['citizenServiceNumber' => '*****7609'], $response->getData());
	}//end testTheRevertAnswerIsRendered()

	/**
	 * Only `authorization.reveal` makes a property explicit-reveal.
	 *
	 * @return void
	 */
	public function testOnlyTheAuthorizationFormCounts(): void {
		$this->assertSame(['citizenServiceNumber'], ExplicitReveal::explicitProperties(schema: $this->schema()));
		$this->assertSame([], ExplicitReveal::explicitProperties(schema: $this->schema(['type' => 'string', 'reveal' => 'explicit'])));
	}//end testOnlyTheAuthorizationFormCounts()

	/**
	 * A grant matches the object's uuid only: a numeric id or slug shared with
	 * the route reveals nothing.
	 *
	 * @return void
	 */
	public function testAGrantMatchesTheUuidOnly(): void {
		$properties = $this->createMock(PropertyRbacHandler::class);
		$properties->method('canReadProperty')->willReturn(true);
		$reveal = new ExplicitReveal();
		$reveal->grantFor(objectId: '42', reveal: 'citizenServiceNumber');
		$masker = new ExplicitRevealMasker($reveal, $properties, $this->createMock(IUserSession::class), $this->createMock(LoggerInterface::class));

		[$byId] = $masker->maskObject(schema: $this->schema(), object: ['id' => '42', 'citizenServiceNumber' => self::BSN], objectIds: ['42']);
		$this->assertSame('*****7609', $byId['citizenServiceNumber']);

		$reveal->grantFor(objectId: self::UUID, reveal: 'citizenServiceNumber');
		[$byUuid] = $masker->maskObject(schema: $this->schema(), object: ['id' => self::UUID, 'citizenServiceNumber' => self::BSN]);
		$this->assertSame(self::BSN, $byUuid['citizenServiceNumber']);
	}//end testAGrantMatchesTheUuidOnly()

	/**
	 * The administrator's trail lists show it masked.
	 *
	 * @return void
	 */
	public function testTheAdminTrailShowsItMasked(): void {
		$schemas = $this->createMock(SchemaMapper::class);
		$schemas->method('find')->willReturn($this->schema());
		$entry = new AuditTrail();
		$entry->setSchema(7);
		$entry->setChanged(['citizenServiceNumber' => ['old' => null, 'new' => self::BSN]]);

		$rows = (new ExplicitRevealTrailMasker($schemas))->mask(entries: [$entry]);

		$this->assertSame('*****7609', $rows[0]['changed']['citizenServiceNumber']['new']);
		$this->assertNull($rows[0]['changed']['citizenServiceNumber']['old']);
	}//end testTheAdminTrailShowsItMasked()

	/**
	 * A trail entry whose schema no longer exists does not show its values in clear.
	 *
	 * @return void
	 */
	public function testAnEntryOfADeletedSchemaIsMasked(): void {
		$schemas = $this->createMock(originalClassName: SchemaMapper::class);
		$schemas->method('find')->willThrowException(new DoesNotExistException(msg: 'gone'));
		$entry = new AuditTrail();
		$entry->setSchema(7);
		$entry->setChanged(['citizenServiceNumber' => ['old' => null, 'new' => self::BSN], 'pin' => '4321']);

		$rows = (new ExplicitRevealTrailMasker($schemas))->mask(entries: [$entry]);

		$this->assertSame(expected: '*****7609', actual: $rows[0]['changed']['citizenServiceNumber']['new']);
		$this->assertSame(expected: '*****', actual: $rows[0]['changed']['pin']);
		$this->assertStringNotContainsString(needle: self::BSN, haystack: (string)json_encode(value: $rows));
	}//end testAnEntryOfADeletedSchemaIsMasked()
}//end class
