<?php

/**
 * Unit tests for FormLinkService::createAndLinkForm() with NC Forms present.
 *
 * Runs in separate processes: it declares the NC Forms classes, which the
 * other FormLinkService tests need to be absent.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
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
 * @covers \OCA\OpenRegister\Service\FormLinkService
 * @covers \OCA\OpenRegister\Service\FormLinkScope
 * @uses   \OCA\OpenRegister\Db\FormLink
 * @uses   \OCA\OpenRegister\Service\LinkServiceRuntime
 * @uses   \OCA\OpenRegister\Service\NewForm
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class FormLinkServiceCreateFormTest extends TestCase {

	/**
	 * Declare a recording NC Forms Form entity and an empty FormMapper.
	 *
	 * @return void
	 */
	private function declareForms(): void {
		if (class_exists('OCA\\Forms\\Db\\Form') === false) {
			eval(
				'namespace OCA\\Forms\\Db; class Form { public array $set = []; '
				. 'public function __call(string $name, array $args) { $this->set[$name] = $args[0] ?? null; return null; } '
				. 'public function getId(): string { return "31"; } }'
			);
		}

		if (class_exists('OCA\\Forms\\Db\\FormMapper') === false) {
			eval('namespace OCA\\Forms\\Db; class FormMapper {}');
		}
	}//end declareForms()

	/**
	 * Build the service around a container that resolves (or fails to resolve) the Forms services.
	 *
	 * @param object|null     $formsMapper The NC Forms mapper, or null to make the container throw.
	 * @param LoggerInterface $logger      The logger.
	 * @param FormLinkMapper  $mapper      The form-link mapper.
	 *
	 * @return FormLinkService
	 */
	private function service(?object $formsMapper, LoggerInterface $logger, FormLinkMapper $mapper): FormLinkService {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isInstalled')->willReturnMap([['forms', true]]);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('carol');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$hashService = new class {
			public function generateFormHash(): string {
				return 'gen-hash';
			}
		};
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($formsMapper, $hashService): object {
				if ($formsMapper === null) {
					throw new RuntimeException('container down');
				}

				if ($id === '\\OCA\\Forms\\Db\\FormMapper') {
					return $formsMapper;
				}

				return $hashService;
			}
		);
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willThrowException(new RuntimeException('no table'));

		return new FormLinkService($mapper, $container, $db, new LinkServiceRuntime($appManager, $session, $logger));
	}//end service()

	/**
	 * A form-link mapper that echoes inserts.
	 *
	 * @return FormLinkMapper
	 */
	private function linkMapper(): FormLinkMapper {
		$mapper = $this->getMockBuilder(FormLinkMapper::class)
			->disableOriginalConstructor()
			->onlyMethods(['findFormLink', 'insert'])
			->getMock();
		$mapper->method('insert')->willReturnArgument(0);
		return $mapper;
	}//end linkMapper()

	/**
	 * The new form is drafted private and non-anonymous, inserted, then linked by its id.
	 *
	 * @return void
	 */
	public function testCreateAndLinkFormDraftsInsertsAndLinks(): void {
		$this->declareForms();
		$formsMapper = new class {
			public ?object $inserted = null;

			public function insert(object $form): object {
				$this->inserted = $form;
				return $form;
			}
		};
		$mapper = $this->linkMapper();
		$mapper->expects($this->once())->method('findFormLink')->with('obj-9', 31)->willReturn(null);

		$link = $this->service($formsMapper, $this->createMock(LoggerInterface::class), $mapper)
			->createAndLinkForm((new FormLinkScope('obj-9', 2))->inSchema(4), new NewForm('Survey', 'About'));

		$this->assertNotNull($formsMapper->inserted);
		$this->assertSame(
			[
				'setOwnerId' => 'carol',
				'setHash' => 'gen-hash',
				'setTitle' => 'Survey',
				'setDescription' => 'About',
				'setAccess' => ['permitAllUsers' => false, 'showToAllUsers' => false],
				'setSubmitMultiple' => false,
				'setAllowEditSubmissions' => false,
				'setShowExpiration' => false,
				'setExpires' => 0,
				'setIsAnonymous' => false,
			],
			$formsMapper->inserted->set
		);
		$this->assertInstanceOf(FormLink::class, $link);
		$this->assertSame(31, $link->getFormId());
		$this->assertSame('obj-9', $link->getObjectUuid());
		$this->assertSame(4, $link->getSchemaId());
		$this->assertSame('carol', $link->getLinkedBy());
		$this->assertNull($link->getTitle());
	}//end testCreateAndLinkFormDraftsInsertsAndLinks()

	/**
	 * A container that cannot resolve the Forms services is logged and refused with 503.
	 *
	 * @return void
	 */
	public function testCreateAndLinkFormRefusesWhenServicesCannotBeResolved(): void {
		$this->declareForms();
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error')
			->with('Failed to resolve NC Forms services: container down');
		$mapper = $this->linkMapper();
		$mapper->expects($this->never())->method('insert');

		try {
			$this->service(null, $logger, $mapper)->createAndLinkForm(new FormLinkScope('obj-9', 2), new NewForm('Survey'));
			$this->fail('Expected an exception.');
		} catch (Exception $e) {
			$this->assertSame('Could not bootstrap NC Forms services', $e->getMessage());
			$this->assertSame(503, $e->getCode());
		}
	}//end testCreateAndLinkFormRefusesWhenServicesCannotBeResolved()
}//end class
