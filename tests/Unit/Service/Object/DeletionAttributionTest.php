<?php

/**
 * DeletionAttribution: who deleted an object, under which organisation.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;
use DateTime;
use DateTimeImmutable;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\OrganisationMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Deletion\DeletionWindowService;
use OCA\OpenRegister\Service\Object\DeletionAttribution;
use OCA\OpenRegister\Service\Object\DeletionTarget;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The deleted metadata and the acting user, with and without a session user, organisation or window service.
 *
 * @covers \OCA\OpenRegister\Service\Object\DeletionAttribution
 * @uses \OCA\OpenRegister\Service\Object\DeletionTarget
 * @uses \OCA\OpenRegister\Service\WritePhaseProbe
 * @uses \OCA\OpenRegister\Db\ObjectEntity
 * @uses \OCA\OpenRegister\Db\Schema
 */
final class DeletionAttributionTest extends TestCase {

	/**
	 * The warnings logged.
	 *
	 * @var list<string>
	 */
	private array $warnings = [];

	/**
	 * An attribution for that session user, whose organisation lookup answers or throws.
	 *
	 * @param string|null                $uid     The session user id, or null without a session user.
	 * @param string|\Throwable|null     $org     The active organisation, or what the lookup throws.
	 * @param DeletionWindowService|null $windows The window service, when wired.
	 *
	 * @return DeletionAttribution
	 */
	private function attribution(?string $uid, string|\Throwable|null $org, ?DeletionWindowService $windows = null): DeletionAttribution {
		$session = $this->createMock(originalClassName: IUserSession::class);
		$user    = null;
		if ($uid !== null) {
			$user = $this->createMock(originalClassName: IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session->method('getUser')->willReturn($user);

		$organisations = $this->createMock(originalClassName: OrganisationMapperInterface::class);
		if ($org instanceof \Throwable) {
			$organisations->method('getActiveOrganisationWithFallback')->willThrowException($org);
		} else {
			$organisations->method('getActiveOrganisationWithFallback')->with($uid)->willReturn($org);
		}

		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string $message): void {
				$this->warnings[] = $message;
			}
		);

		return new DeletionAttribution(userSession: $session, organisationMapper: $organisations, logger: $logger, windowService: $windows);
	}//end attribution()

	/**
	 * A target object with a uuid.
	 *
	 * @param Schema|null $schema The schema.
	 *
	 * @return DeletionTarget
	 */
	private static function target(?Schema $schema = null): DeletionTarget {
		$object = new ObjectEntity();
		$object->setUuid('obj-1');

		return new DeletionTarget(object: $object, register: null, schema: $schema);
	}//end target()

	/**
	 * The metadata names the session user, the object and the organisation, stamped now in ATOM form.
	 *
	 * @return void
	 */
	public function testTheMetadataNamesUserObjectAndOrganisation(): void {
		$before = new DateTimeImmutable('-1 second');
		$data   = $this->attribution(uid: 'alice', org: 'org-1')->deletedMetadata(target: self::target());
		$after  = new DateTimeImmutable('+1 second');

		$this->assertSame(expected: ['deletedBy', 'deletedAt', 'objectId', 'organisation'], actual: array_keys($data));
		$this->assertSame(expected: ['alice', 'obj-1', 'org-1'], actual: [$data['deletedBy'], $data['objectId'], $data['organisation']]);
		$stamped = DateTimeImmutable::createFromFormat(DateTime::ATOM, $data['deletedAt']);
		$this->assertNotFalse(condition: $stamped);
		$this->assertTrue(condition: $stamped >= $before && $stamped <= $after);
	}//end testTheMetadataNamesUserObjectAndOrganisation()

	/**
	 * Without a session user the deleter is system and no organisation is looked up.
	 *
	 * @return void
	 */
	public function testWithoutAUserTheDeleterIsSystem(): void {
		$data = $this->attribution(uid: null, org: new RuntimeException('must not be asked'))->deletedMetadata(target: self::target());

		$this->assertSame(expected: ['system', null], actual: [$data['deletedBy'], $data['organisation']]);
		$this->assertSame(expected: [], actual: $this->warnings);
	}//end testWithoutAUserTheDeleterIsSystem()

	/**
	 * A failing organisation lookup, even an Error, is logged and leaves the organisation empty.
	 *
	 * @return void
	 */
	public function testAFailingOrganisationLookupIsLogged(): void {
		$data = $this->attribution(uid: 'alice', org: new \Error('no database'))->deletedMetadata(target: self::target());

		$this->assertSame(expected: ['alice', null], actual: [$data['deletedBy'], $data['organisation']]);
		$this->assertSame(expected: ['[DeleteObject] Failed to get active organisation during delete'], actual: $this->warnings);
	}//end testAFailingOrganisationLookupIsLogged()

	/**
	 * A wired window service adds the recovery window for the target's schema at the same moment.
	 *
	 * @return void
	 */
	public function testAWindowServiceAddsTheRecoveryWindow(): void {
		$schema  = new Schema();
		$windows = $this->createMock(originalClassName: DeletionWindowService::class);
		$seen    = [];
		$windows->expects($this->once())->method('openWindow')->willReturnCallback(
			function (?Schema $schema, ?DateTimeImmutable $deletedAt) use (&$seen): array {
				$seen = [$schema, $deletedAt];
				return ['destroyableFrom' => 'later', 'retentionDays' => 30];
			}
		);

		$data = $this->attribution(uid: 'alice', org: 'org-1', windows: $windows)->deletedMetadata(target: self::target(schema: $schema));

		$this->assertSame(expected: $schema, actual: $seen[0]);
		$this->assertSame(expected: $data['deletedAt'], actual: $seen[1]->format(DateTime::ATOM));
		$this->assertSame(expected: ['later', 30, 'org-1'], actual: [$data['destroyableFrom'], $data['retentionDays'], $data['organisation']]);
	}//end testAWindowServiceAddsTheRecoveryWindow()

	/**
	 * The acting user is the session user with the organisation, or system with none.
	 *
	 * @return void
	 */
	public function testTheActingUser(): void {
		$this->assertSame(expected: ['alice', 'org-1'], actual: $this->attribution(uid: 'alice', org: 'org-1')->actingUser());
		$this->assertSame(expected: ['system', null], actual: $this->attribution(uid: null, org: null)->actingUser());
		$this->assertSame(expected: ['alice', null], actual: $this->attribution(uid: 'alice', org: new RuntimeException('down'))->actingUser());
	}//end testTheActingUser()
}//end class
