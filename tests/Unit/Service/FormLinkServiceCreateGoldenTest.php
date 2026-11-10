<?php

/**
 * Characterisation test for FormLinkService::createAndLinkForm with NC Forms present.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use Exception;
use OCA\OpenRegister\Db\FormLink;
use OCA\OpenRegister\Db\FormLinkMapper;
use OCA\OpenRegister\Service\FormLinkScope;
use OCA\OpenRegister\Service\FormLinkService;
use OCA\OpenRegister\Service\LinkServiceRuntime;
use OCA\OpenRegister\Service\NewForm;
use OCP\App\IAppManager;
use OCP\IDBConnection;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Pins the NC Forms calls, their order and the link row of a form created
 * and linked in one go; runs in its own process because it declares the
 * NC Forms classes the other Forms tests need to be absent.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class FormLinkServiceCreateGoldenTest extends TestCase {

	/**
	 * Declare the NC Forms entity and mapper classes this process needs.
	 *
	 * @return void
	 */
	private function declareForms(): void {
		if (class_exists('OCA\\Forms\\Db\\Form') === false) {
			eval(
				'namespace OCA\\Forms\\Db; class Form { public static array $calls = []; '
				. 'public function __call(string $name, array $args) { self::$calls[] = [$name, $args]; return null; } '
				. 'public function getId(): int { return 77; } }'
			);
		}

		if (class_exists('OCA\\Forms\\Db\\FormMapper') === false) {
			eval('namespace OCA\\Forms\\Db; class FormMapper {}');
		}
	}//end declareForms()

	/**
	 * Build the service; the container hands out a recording mapper and hash service.
	 *
	 * @param array<int,string> $log    Receives the Forms-side calls.
	 * @param string|null       $uid    The logged-in user, or null.
	 * @param bool              $throws Whether the container fails.
	 *
	 * @return array{0:FormLinkService,1:FormLinkMapper}
	 */
	private function service(array &$log, ?string $uid, bool $throws = false): array {
		$mapper = $this->getMockBuilder(FormLinkMapper::class)
			->disableOriginalConstructor()
			->onlyMethods(['findFormLink', 'insert'])
			->getMock();
		$mapper->method('insert')->willReturnArgument(0);
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isInstalled')->willReturnCallback(
			static function (string $app) use (&$log): bool {
				$log[] = 'isInstalled(' . $app . ')';
				return true;
			}
		);
		$session = $this->createMock(IUserSession::class);
		$user    = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session->method('getUser')->willReturn($user);
		$formsMapper = new class($log) {
			public function __construct(private array &$log) {
			}

			public function insert(object $form): object {
				$this->log[] = 'formMapper.insert(' . get_class($form) . ')';
				return $form;
			}
		};
		$formsService = new class($log) {
			public function __construct(private array &$log) {
			}

			public function generateFormHash(): string {
				$this->log[] = 'generateFormHash';
				return 'hash-1';
			}
		};
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($formsMapper, $formsService, $throws, &$log): object {
				$log[] = 'container.get(' . $id . ')';
				if ($throws === true) {
					throw new RuntimeException('no forms');
				}

				if ($id === '\\OCA\\Forms\\Db\\FormMapper') {
					return $formsMapper;
				}

				return $formsService;
			}
		);
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willThrowException(new RuntimeException('no table'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('error')->willReturnCallback(
			static function (string $message) use (&$log): void {
				$log[] = 'error: ' . $message;
			}
		);

		return [new FormLinkService($mapper, $container, $db, new LinkServiceRuntime($appManager, $session, $logger)), $mapper];
	}//end service()

	/**
	 * The new form is drafted in Forms' own shape, inserted, then linked.
	 *
	 * @return void
	 */
	public function testCreateAndLinkDraftsInsertsAndLinksTheForm(): void {
		$this->declareForms();
		$log = [];
		[$service, $mapper] = $this->service($log, 'alice');
		$mapper->expects($this->once())->method('findFormLink')->with('obj', 77)->willReturn(null);

		$link = $service->createAndLinkForm((new FormLinkScope('obj', 3))->inSchema(8), new NewForm('Intake', 'Please fill'));

		$this->assertSame(
			[
				'isInstalled(forms)',
				'container.get(\\OCA\\Forms\\Db\\FormMapper)',
				'container.get(\\OCA\\Forms\\Service\\FormsService)',
				'generateFormHash',
				'formMapper.insert(OCA\\Forms\\Db\\Form)',
				'isInstalled(forms)',
			],
			$log
		);
		$this->assertSame(
			[
				['setOwnerId', ['alice']],
				['setHash', ['hash-1']],
				['setTitle', ['Intake']],
				['setDescription', ['Please fill']],
				['setAccess', [['permitAllUsers' => false, 'showToAllUsers' => false]]],
				['setSubmitMultiple', [false]],
				['setAllowEditSubmissions', [false]],
				['setShowExpiration', [false]],
				['setExpires', [0]],
				['setIsAnonymous', [false]],
			],
			\OCA\Forms\Db\Form::$calls
		);
		$this->assertInstanceOf(FormLink::class, $link);
		$row = $link->jsonSerialize();
		unset($row['linkedAt'], $row['id']);
		$this->assertSame(
			['obj', 3, 8, 77, null, null, null, 'alice'],
			[$row['objectUuid'], $row['registerId'], $row['schemaId'], $row['formId'], $row['submissionId'], $row['formHash'], $row['title'], $row['linkedBy']]
		);
	}//end testCreateAndLinkDraftsInsertsAndLinksTheForm()

	/**
	 * An existing link of the new form is returned; the default description is empty and the schema null.
	 *
	 * @return void
	 */
	public function testCreateAndLinkReturnsAnExistingLink(): void {
		$this->declareForms();
		$log = [];
		[$service, $mapper] = $this->service($log, 'alice');
		$existing = new FormLink();
		$mapper->method('findFormLink')->willReturn($existing);
		$mapper->expects($this->never())->method('insert');

		$this->assertSame($existing, $service->createAndLinkForm(new FormLinkScope('obj', 3), new NewForm('T')));
		$this->assertContains(['setDescription', ['']], \OCA\Forms\Db\Form::$calls);
	}//end testCreateAndLinkReturnsAnExistingLink()

	/**
	 * No user is refused (401) before Forms is looked at; a failing container is a 503 with an error log.
	 *
	 * @return void
	 */
	public function testCreateAndLinkRefusals(): void {
		$this->declareForms();
		$log = [];
		[$service] = $this->service($log, null);
		try {
			$service->createAndLinkForm(new FormLinkScope('obj', 3), new NewForm('T'));
			$this->fail('expected a refusal');
		} catch (Exception $e) {
			$this->assertSame(['No user logged in', 401], [$e->getMessage(), $e->getCode()]);
		}

		$this->assertSame([], $log);

		[$service] = $this->service($log, 'alice', true);
		try {
			$service->createAndLinkForm(new FormLinkScope('obj', 3), new NewForm('T'));
			$this->fail('expected a refusal');
		} catch (Exception $e) {
			$this->assertSame(['Could not bootstrap NC Forms services', 503], [$e->getMessage(), $e->getCode()]);
		}

		$this->assertSame(
			['isInstalled(forms)', 'container.get(\\OCA\\Forms\\Db\\FormMapper)', 'error: Failed to resolve NC Forms services: no forms'],
			$log
		);
	}//end testCreateAndLinkRefusals()
}//end class
