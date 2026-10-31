<?php

/**
 * An explicit-reveal property leaves masked on every route, for admins too,
 * and is shown only to the single-object read that grants it.
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

use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Exception\ExplicitRevealQueryException;
use OCA\OpenRegister\Service\Audit\HistoryEntryRedactor;
use OCA\OpenRegister\Service\ConditionMatcher;
use OCA\OpenRegister\Service\Mcp\McpResourcesService;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\PropertyRbacHandler;
use OCA\OpenRegister\Service\Rbac\ExplicitReveal;
use OCA\OpenRegister\Service\Rbac\ExplicitRevealMasker;
use OCA\OpenRegister\Service\Rbac\RevealCollector;
use OCA\OpenRegister\Service\Search\PropertySearchProfile;
use OCA\OpenRegister\Service\SystemOperationContext;
use OCA\OpenRegister\Tests\Support\BuildsStateFieldRuleResolver;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;

/**
 * @covers \OCA\OpenRegister\Service\Rbac\ExplicitReveal
 * @covers \OCA\OpenRegister\Service\Rbac\ExplicitRevealMasker
 * @covers \OCA\OpenRegister\Service\PropertyRbacHandler
 */
class ExplicitRevealTest extends TestCase {
	use BuildsStateFieldRuleResolver;

	private const BSN = '123457609';

	private const MASK = '*****7609';

	/**
	 * A person schema whose BSN is read-restricted, audited and explicit-reveal.
	 *
	 * @return Schema The schema.
	 */
	private function schema(): Schema {
		$schema = new Schema();
		$schema->setId(7);
		$schema->setSlug('brpPerson');
		$schema->setProperties([
			'displayName' => ['type' => 'string'],
			'citizenServiceNumber' => [
				'type' => 'string',
				'authorization' => ['read' => [['group' => 'sensitive']], 'audit' => true, 'reveal' => 'explicit'],
			],
		]);

		return $schema;
	}//end schema()

	/**
	 * The read rule for a caller in these groups.
	 *
	 * @param array<int, string> $groups  The caller's groups.
	 * @param RevealCollector    $reveals The reveal collector.
	 *
	 * @return array{0: PropertyRbacHandler, 1: IUserSession} The handler and the session.
	 */
	private function rules(array $groups, RevealCollector $reveals): array {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('pieter');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('getUserGroupIds')->willReturn($groups);
		$groupManager->method('isAdmin')->willReturn(in_array('admin', $groups, true));

		$handler = new PropertyRbacHandler(
			$session,
			$groupManager,
			$this->createMock(ConditionMatcher::class),
			$this->createMock(LoggerInterface::class),
			self::stateFieldRuleResolver($session, $groupManager),
			$reveals
		);

		return [$handler, $session];
	}//end rules()

	/**
	 * A masker for a caller in these groups.
	 *
	 * @param array<int, string> $groups  The caller's groups.
	 * @param ExplicitReveal     $reveal  The grants.
	 * @param RevealCollector    $reveals The reveal collector.
	 *
	 * @return ExplicitRevealMasker The masker.
	 */
	private function masker(array $groups, ExplicitReveal $reveal, RevealCollector $reveals): ExplicitRevealMasker {
		[$handler, $session] = $this->rules(groups: $groups, reveals: $reveals);

		return new ExplicitRevealMasker($reveal, $handler, $session, $this->createMock(LoggerInterface::class), $reveals);
	}//end masker()

	/**
	 * The mask keeps the last four characters.
	 *
	 * @return void
	 */
	public function testTheMaskKeepsTheLastFour(): void {
		$this->assertSame(self::MASK, ExplicitReveal::mask(value: self::BSN));
		$this->assertSame('', ExplicitReveal::mask(value: ''));
	}//end testTheMaskKeepsTheLastFour()

	/**
	 * A read without a grant gets the mask and records nothing, admins included.
	 *
	 * @return void
	 */
	public function testAnUngrantedReadIsMaskedForAdminsToo(): void {
		foreach ([['sensitive'], ['admin']] as $groups) {
			$reveals = new RevealCollector();
			[$out, $masked] = $this->masker($groups, new ExplicitReveal(), $reveals)->maskObject(
				schema: $this->schema(),
				object: ['id' => '11111111-1111-4111-8111-111111111111', 'citizenServiceNumber' => self::BSN]
			);

			$this->assertSame(self::MASK, $out['citizenServiceNumber']);
			$this->assertSame(['citizenServiceNumber'], $masked);
			$this->assertSame(0, $reveals->count());
		}
	}//end testAnUngrantedReadIsMaskedForAdminsToo()

	/**
	 * The granted read of that object sees the value and is recorded, admins included;
	 * another object in the same request stays masked.
	 *
	 * @return void
	 */
	public function testAGrantRevealsThatObjectOnlyAndIsRecorded(): void {
		foreach ([['sensitive'], ['admin']] as $groups) {
			$reveal = new ExplicitReveal();
			$reveal->grantFor(objectId: '11111111-1111-4111-8111-111111111111', reveal: 'citizenServiceNumber');
			$reveals = new RevealCollector();
			$masker = $this->masker($groups, $reveal, $reveals);

			[$own] = $masker->maskObject(schema: $this->schema(), object: ['id' => '11111111-1111-4111-8111-111111111111', 'citizenServiceNumber' => self::BSN]);
			[$other] = $masker->maskObject(schema: $this->schema(), object: ['id' => '22222222-2222-4222-8222-222222222222', 'citizenServiceNumber' => '999990000']);

			$this->assertSame(self::BSN, $own['citizenServiceNumber']);
			$this->assertSame('*****0000', $other['citizenServiceNumber']);
			$this->assertSame(1, $reveals->count());
		}
	}//end testAGrantRevealsThatObjectOnlyAndIsRecorded()

	/**
	 * A grant gives nothing to a reader without read rights: the check runs first.
	 *
	 * @return void
	 */
	public function testAGrantWithoutReadRightsStaysMasked(): void {
		$reveal = new ExplicitReveal();
		$reveal->grantFor(objectId: '11111111-1111-4111-8111-111111111111', reveal: 'citizenServiceNumber');
		$reveals = new RevealCollector();

		[$out] = $this->masker(['frontdesk'], $reveal, $reveals)->maskObject(
			schema: $this->schema(),
			object: ['id' => '11111111-1111-4111-8111-111111111111', 'citizenServiceNumber' => self::BSN]
		);

		$this->assertSame(self::MASK, $out['citizenServiceNumber']);
		$this->assertSame(0, $reveals->count());
	}//end testAGrantWithoutReadRightsStaysMasked()

	/**
	 * Trusted server code and a system operation see the value.
	 *
	 * @return void
	 */
	public function testTrustedCodeIsNotMasked(): void {
		$reveal = new ExplicitReveal();
		$masker = $this->masker(['sensitive'], $reveal, new RevealCollector());
		$object = ['id' => '11111111-1111-4111-8111-111111111111', 'citizenServiceNumber' => self::BSN];

		[$trusted] = $reveal->trustedDuring(fn () => $masker->maskObject(schema: $this->schema(), object: $object));
		[$system] = SystemOperationContext::run(fn () => $masker->maskObject(schema: $this->schema(), object: $object));

		$this->assertSame(self::BSN, $trusted['citizenServiceNumber']);
		$this->assertSame(self::BSN, $system['citizenServiceNumber']);
	}//end testTrustedCodeIsNotMasked()

	/**
	 * The read filter records no reveal of an explicit-reveal property: the masker owns that.
	 *
	 * @return void
	 */
	public function testTheReadFilterRecordsNoExplicitReveal(): void {
		$reveals = new RevealCollector();
		[$handler] = $this->rules(groups: ['sensitive'], reveals: $reveals);

		$handler->filterReadableProperties(schema: $this->schema(), object: ['id' => '11111111-1111-4111-8111-111111111111', 'citizenServiceNumber' => self::BSN]);

		$this->assertSame(0, $reveals->count());
	}//end testTheReadFilterRecordsNoExplicitReveal()

	/**
	 * Filtering or sorting on it is refused; trusted code may filter.
	 *
	 * @return void
	 */
	public function testFilterAndSortOnItAreRefused(): void {
		$reveal = new ExplicitReveal();
		foreach ([['citizenServiceNumber' => self::BSN], ['_order' => ['citizenServiceNumber' => 'asc']], ['_order' => '{"citizenServiceNumber":"desc"}']] as $query) {
			try {
				$reveal->refuseOracleQuery(query: $query, schema: $this->schema());
				$this->fail('refused: ' . json_encode($query));
			} catch (ExplicitRevealQueryException $refused) {
				$this->assertStringContainsString('citizenServiceNumber', $refused->getMessage());
			}
		}

		$reveal->refuseOracleQuery(query: ['displayName' => 'Sanne', '_order' => ['displayName' => 'asc']], schema: $this->schema());
		$reveal->trustedDuring(fn () => $reveal->refuseOracleQuery(query: ['citizenServiceNumber' => self::BSN], schema: $this->schema()));
		$this->addToAssertionCount(1);
	}//end testFilterAndSortOnItAreRefused()

	/**
	 * It takes no part in free-text search.
	 *
	 * @return void
	 */
	public function testItIsNotMatchedByFreeText(): void {
		$properties = $this->schema()->getProperties();

		$this->assertFalse(PropertySearchProfile::participatesInFreeText(property: $properties['citizenServiceNumber']));
		$this->assertTrue(PropertySearchProfile::participatesInFreeText(property: $properties['displayName']));
	}//end testItIsNotMatchedByFreeText()

	/**
	 * A history entry shows it masked, old and new, and withholds its `@self` copies.
	 *
	 * @return void
	 */
	public function testHistoryShowsItMasked(): void {
		$properties = $this->createMock(PropertyRbacHandler::class);
		$properties->method('filterReadableProperties')->willReturnArgument(1);
		$properties->method('stripWriteOnlyProperties')->willReturnArgument(1);

		$schema = $this->schema();
		$schema->setConfiguration(['objectNameField' => 'citizenServiceNumber']);
		$entry = new AuditTrail();
		$entry->setChanged([
			'citizenServiceNumber' => ['old' => '999990000', 'new' => self::BSN],
			'@self' => ['new' => ['name' => self::BSN]],
		]);
		$object = new ObjectEntity();
		$object->setObject(['citizenServiceNumber' => self::BSN]);

		$rows = (new HistoryEntryRedactor($properties))->redact(rows: [$entry], schema: $schema, object: $object);

		$this->assertSame(['old' => '*****0000', 'new' => self::MASK], $rows[0]['changed']['citizenServiceNumber']);
		$this->assertNull($rows[0]['changed']['@self']['new']['name']);
	}//end testHistoryShowsItMasked()

	/**
	 * An MCP resource read shows it masked.
	 *
	 * @return void
	 */
	public function testAnMcpResourceReadsItMasked(): void {
		$object = new ObjectEntity();
		$object->setUuid('11111111-1111-4111-8111-111111111111');
		$object->setObject(['citizenServiceNumber' => self::BSN]);
		$objects = $this->createMock(ObjectService::class);
		$objects->method('find')->willReturn($object);
		$schemas = $this->createMock(SchemaMapper::class);
		$schemas->method('find')->willReturn($this->schema());

		$service = (new ReflectionClass(McpResourcesService::class))->newInstanceWithoutConstructor();
		$reflection = new ReflectionClass($service);
		foreach (['schemaMapper' => $schemas, 'objectService' => $objects] as $name => $value) {
			$property = $reflection->getProperty($name);
			$property->setValue($service, $value);
		}

		$method = $reflection->getMethod('readObjects');
		$read = $method->invoke($service, 1, 7, '11111111-1111-4111-8111-111111111111');

		$this->assertSame(self::MASK, $read['citizenServiceNumber']);
	}//end testAnMcpResourceReadsItMasked()

	/**
	 * A write that sends the mask back keeps the stored value; a new value is written.
	 *
	 * @return void
	 */
	public function testAWriteSendingTheMaskKeepsTheValue(): void {
		$stored = ['citizenServiceNumber' => self::BSN];

		$this->assertSame(self::BSN, ExplicitReveal::restoreMasked(schema: $this->schema(), incoming: ['citizenServiceNumber' => self::MASK], stored: $stored)['citizenServiceNumber']);
		$this->assertSame('999990000', ExplicitReveal::restoreMasked(schema: $this->schema(), incoming: ['citizenServiceNumber' => '999990000'], stored: $stored)['citizenServiceNumber']);
	}//end testAWriteSendingTheMaskKeepsTheValue()
}//end class
