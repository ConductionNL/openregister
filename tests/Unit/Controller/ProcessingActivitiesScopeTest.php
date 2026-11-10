<?php

/**
 * The processing activity list and detail answer within the caller's active organisation.
 *
 * A non-admin is given the activities of their active organisation only, the
 * same rule as the accountability report. Membership of another organisation
 * does not widen it, and neither does an `organisation` query parameter. A
 * non-admin without an active organisation is given nothing. An administrator
 * is given every activity, as recorded in the golden fixture.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/verwerkingsregister-api/spec.md#requirement-the-system-must-provide-a-crud-rest-surface-over-the-dedicated-verwerkingsactiviteiten-catalog
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\VerwerkingsactiviteitenController;
use OCA\OpenRegister\Db\Organisation;
use OCA\OpenRegister\Db\Verwerkingsactiviteit;
use OCA\OpenRegister\Db\VerwerkingsactiviteitMapper;
use OCA\OpenRegister\Service\OrganisationService;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Who is given which processing activities.
 */
final class ProcessingActivitiesScopeTest extends TestCase {

	/**
	 * The recording of what an administrator is given.
	 *
	 * @var string
	 */
	private const FIXTURE = __DIR__ . '/fixtures/processing-activities-admin-golden.json';

	/**
	 * How often the activity catalog was read.
	 *
	 * @var int
	 */
	private int $catalogReads = 0;

	/**
	 * The catalog: two activities of org-a, one of org-b, one without an organisation.
	 *
	 * @return array<int, Verwerkingsactiviteit> The activities.
	 */
	private function catalog(): array {
		$rows = [];
		foreach ([[1, 'va-a1', 'org-a'], [2, 'va-a2', 'org-a'], [3, 'va-b1', 'org-b'], [4, 'va-none', null]] as [$id, $uuid, $org]) {
			$activity = new Verwerkingsactiviteit();
			$activity->setId($id);
			$activity->setUuid($uuid);
			$activity->setCode('VA-' . $id);
			$activity->setName('Activiteit ' . $id);
			$activity->setOrganisationId($org);
			$activity->setStatus('published');
			$rows[] = $activity;
		}

		return $rows;
	}//end catalog()

	/**
	 * An organisation with a uuid.
	 *
	 * @param string $uuid The uuid.
	 *
	 * @return Organisation The organisation.
	 */
	private function organisation(string $uuid): Organisation {
		$organisation = new Organisation();
		$organisation->setUuid($uuid);

		return $organisation;
	}//end organisation()

	/**
	 * The controller for one caller.
	 *
	 * @param string $uid   The caller.
	 * @param array  $world `admin` (bool), `active` (?string), `memberOf` (string[]), `params` (array).
	 *
	 * @return VerwerkingsactiviteitenController The controller.
	 */
	private function controller(string $uid, array $world = []): VerwerkingsactiviteitenController {
		$catalog = $this->catalog();
		$mapper = $this->createMock(VerwerkingsactiviteitMapper::class);
		$mapper->method('findAll')->willReturnCallback(
			function (?string $organisationId = null) use ($catalog): array {
				$this->catalogReads++;
				if ($organisationId === null) {
					return $catalog;
				}

				return array_values(array_filter($catalog, static fn (Verwerkingsactiviteit $a): bool => $a->getOrganisationId() === $organisationId));
			}
		);
		$mapper->method('find')->willReturnCallback(static fn (int $id): Verwerkingsactiviteit => $catalog[$id - 1]);
		$mapper->method('resolveReference')->willReturnCallback(
			static function (string $reference) use ($catalog): ?Verwerkingsactiviteit {
				foreach ($catalog as $activity) {
					if ($activity->getUuid() === $reference || $activity->getCode() === $reference) {
						return $activity;
					}
				}

				return null;
			}
		);

		$params = ($world['params'] ?? []);
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn (string $key, $default = null) => ($params[$key] ?? $default));

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('getUserGroupIds')->willReturn((($world['admin'] ?? false) === true) ? ['admin'] : ['users']);

		$organisations = $this->createMock(OrganisationService::class);
		$active = ($world['active'] ?? null);
		$organisations->method('getActiveOrganisation')->willReturn(($active === null) ? null : $this->organisation($active));
		$organisations->method('getUserOrganisations')->willReturn(
			array_map(fn (string $uuid): Organisation => $this->organisation($uuid), ($world['memberOf'] ?? []))
		);

		return new VerwerkingsactiviteitenController(
			$request,
			new \OCA\OpenRegister\Controller\Support\VerwerkingsactiviteitenControllerServices(
				$mapper,
				new \OCA\OpenRegister\Controller\Support\ControllerSession($session, $groups, $this->createMock(\Psr\Log\LoggerInterface::class)),
				$this->createMock(IDBConnection::class),
				$organisations,
			)
		);
	}//end controller()

	public function testAnAdministratorIsGivenEveryActivityAsRecorded(): void {
		$admin = ['admin' => true];
		$actual = [
			'index' => $this->controller(uid: 'admin', world: $admin)->index()->getData(),
			'showB' => $this->controller(uid: 'admin', world: $admin)->show('va-b1')->getData(),
			'showNone' => $this->controller(uid: 'admin', world: $admin)->show('4')->getData(),
		];
		$actual = json_decode((string)json_encode($actual), true);

		if (getenv('UPDATE_GOLDEN') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}

		self::assertSame(json_decode((string)file_get_contents(self::FIXTURE), true), $actual);
	}//end testAnAdministratorIsGivenEveryActivityAsRecorded()

	public function testANonAdminListsTheActivitiesOfTheirActiveOrganisationOnly(): void {
		$data = $this->controller(uid: 'jan', world: ['active' => 'org-a', 'memberOf' => ['org-a', 'org-b']])->index()->getData();

		self::assertSame(2, $data['count']);
		self::assertSame(['va-a1', 'va-a2'], array_column($data['results'], 'uuid'));
	}//end testANonAdminListsTheActivitiesOfTheirActiveOrganisationOnly()

	public function testAnOrganisationParameterDoesNotWidenTheList(): void {
		$data = $this->controller(
			uid: 'jan',
			world: ['active' => 'org-a', 'memberOf' => ['org-a', 'org-b'], 'params' => ['organisation' => 'org-b']]
		)->index()->getData();

		self::assertSame([], array_column($data['results'], 'uuid'));
	}//end testAnOrganisationParameterDoesNotWidenTheList()

	public function testANonAdminCannotReadAnActivityOfAnotherOrganisationTheyBelongTo(): void {
		$response = $this->controller(uid: 'jan', world: ['active' => 'org-a', 'memberOf' => ['org-a', 'org-b']])->show('va-b1');

		self::assertSame(404, $response->getStatus());
		self::assertSame('va-b1', $response->getData()['identifier']);
	}//end testANonAdminCannotReadAnActivityOfAnotherOrganisationTheyBelongTo()

	public function testAnOrganisationParameterDoesNotOpenAnotherOrganisationsActivity(): void {
		foreach (['va-b1', '3', 'VA-3'] as $identifier) {
			$response = $this->controller(
				uid: 'jan',
				world: ['active' => 'org-a', 'memberOf' => ['org-a', 'org-b'], 'params' => ['organisation' => 'org-b']]
			)->show($identifier);

			self::assertSame(404, $response->getStatus(), 'organisation=org-b opened ' . $identifier);
			self::assertSame(['error' => 'Verwerkingsactiviteit not found', 'identifier' => $identifier], $response->getData());
		}
	}//end testAnOrganisationParameterDoesNotOpenAnotherOrganisationsActivity()

	public function testANonAdminReadsAnActivityOfTheirActiveOrganisation(): void {
		$response = $this->controller(uid: 'jan', world: ['active' => 'org-b', 'memberOf' => ['org-a', 'org-b']])->show('va-b1');

		self::assertSame(200, $response->getStatus());
		self::assertSame('va-b1', $response->getData()['uuid']);
	}//end testANonAdminReadsAnActivityOfTheirActiveOrganisation()

	public function testANonAdminWithoutAnActiveOrganisationIsGivenNothing(): void {
		$world = ['active' => null, 'memberOf' => ['org-a']];

		self::assertSame(['count' => 0, 'results' => []], $this->controller(uid: 'jan', world: $world)->index()->getData());
		self::assertSame(0, $this->catalogReads, 'Without an active organisation the catalog is not read.');
		self::assertSame(404, $this->controller(uid: 'jan', world: $world)->show('va-a1')->getStatus());
	}//end testANonAdminWithoutAnActiveOrganisationIsGivenNothing()

	public function testAnActivityWithoutAnOrganisationIsNotGivenToANonAdmin(): void {
		$world = ['active' => 'org-a', 'memberOf' => ['org-a']];

		self::assertSame(404, $this->controller(uid: 'jan', world: $world)->show('4')->getStatus());
		self::assertNotContains('va-none', array_column($this->controller(uid: 'jan', world: $world)->index()->getData()['results'], 'uuid'));
	}//end testAnActivityWithoutAnOrganisationIsNotGivenToANonAdmin()
}//end class
