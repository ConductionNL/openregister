<?php

/**
 * RedactionGuaranteeContractTest
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Contract
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/redaction-release-safeguards/tasks.md#task-6.1
 */

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Contract;

use OCA\OpenRegister\Db\EntityRelationMapper;
use OCA\OpenRegister\Exception\ReviewRequiredException;
use OCA\OpenRegister\Files\Controller\FileTextController;
use OCA\OpenRegister\Files\Service\File\ManualEntityService;
use OCA\OpenRegister\Files\Service\TextExtractionService;
use OCA\OpenRegister\Service\Anonymisation\RedactionIrreversibilityVerifier;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Tests\Unit\Controller\Support\FileTextControllerFactory;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * What opencatalogi and filinq read from the anonymise endpoint and from a direct call.
 *
 * The anonymise response carries `verification` with these keys; a file that
 * is not checked is refused with 409 and this body, and a direct caller gets
 * `ReviewRequiredException` with the same fields.
 *
 * @covers \OCA\OpenRegister\Files\Controller\FileTextController
 * @covers \OCA\OpenRegister\Exception\ReviewRequiredException
 * @uses   \OCA\OpenRegister\Files\Service\File\CallerFileAccess
 * @uses   \OCA\OpenRegister\Service\File\DocumentAnonymization
 * @uses   \OCA\OpenRegister\Controller\Support\ControllerSession
 * @uses   \OCA\OpenRegister\Files\Controller\Support\FileTextControllerServices
 * @uses   \OCA\OpenRegister\Files\Controller\Support\FileTextMappers
 * @uses   \OCA\OpenRegister\Files\Controller\Support\FileTextDomain
 * @uses   \OCA\OpenRegister\Files\Controller\Support\FileTextPlatform
 */
class RedactionGuaranteeContractTest extends TestCase {

	/**
	 * A controller whose file service answers anonymizeDocument() with $outcome.
	 *
	 * @param \Throwable|null           $outcome      Thrown by anonymizeDocument(), or null to write a copy.
	 * @param array<string, mixed>|null $verification What getLastVerification() returns.
	 */
	private function controller(?\Throwable $outcome, ?array $verification = null): FileTextController {
		$source = $this->createMock(File::class);
		$source->method('getName')->willReturn('besluit.pdf');
		$copy = $this->createMock(File::class);
		$copy->method('getId')->willReturn(77);
		$copy->method('getPath')->willReturn('/files/besluit_anonymized.pdf');

		$fileService = $this->createMock(FileService::class);
		$fileService->method('getFileById')->willReturn($source);
		if ($outcome !== null) {
			$fileService->method('anonymizeDocument')->willThrowException($outcome);
		} else {
			$fileService->method('anonymizeDocument')->willReturn($copy);
		}

		$fileService->method('getLastResidualEntities')->willReturn([]);
		$fileService->method('getLastVerification')->willReturn($verification);

		$relations = $this->createMock(EntityRelationMapper::class);
		$relations->method('findEntitiesForAnonymization')->willReturn([['entity_value' => 'Jan Jansen', 'entity_type' => 'PERSON']]);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('j.devries');
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);
		$folder = $this->createMock(Folder::class);
		$folder->method('getById')->willReturn([$this->createMock(Node::class)]);
		$root = $this->createMock(IRootFolder::class);
		$root->method('getUserFolder')->willReturn($folder);

		return FileTextControllerFactory::create(
			'openregister',
			$this->createMock(IRequest::class),
			$this->createMock(TextExtractionService::class),
			$fileService,
			$relations,
			$this->createMock(LoggerInterface::class),
			$this->createMock(ManualEntityService::class),
			$userSession,
			$root,
			$this->createMock(IGroupManager::class)
		);
	}//end controller()

	public function testTheAnonymiseResponseCarriesTheVerification(): void {
		$verification = [
			'verdict' => RedactionIrreversibilityVerifier::LEAKING,
			'mayBePublished' => false,
			'outputMode' => 'pdf',
			'routesChecked' => ['text_under_mark', 'embedded_preview', 'xmp', 'exif', 'incremental_update', 'annotations_and_fields', 'embedded_attachment'],
			'findings' => [['route' => 'incremental_update', 'count' => 1]],
		];

		$data = $this->controller(outcome: null, verification: $verification)->anonymizeFile(fileId: 42)->getData();

		$this->assertSame(['verdict', 'mayBePublished', 'outputMode', 'routesChecked', 'findings'], array_keys($data['verification']));
		$this->assertSame(['route', 'count'], array_keys($data['verification']['findings'][0]));
		$this->assertSame(['clean', 'leaking', 'unverifiable'], [RedactionIrreversibilityVerifier::CLEAN, RedactionIrreversibilityVerifier::LEAKING, RedactionIrreversibilityVerifier::UNVERIFIABLE]);
	}//end testTheAnonymiseResponseCarriesTheVerification()

	public function testAnUncheckedFileIsRefusedWith409(): void {
		$refusal = new ReviewRequiredException(reason: ReviewRequiredException::STALE_MARK, undecided: 0, message: 'This document must be checked again.');

		$response = $this->controller(outcome: $refusal)->anonymizeFile(fileId: 42);

		$this->assertSame(409, $response->getStatus());
		$this->assertSame(
			['error' => 'review-required', 'reason' => 'stale-mark', 'undecided' => 0, 'message' => 'This document must be checked again.'],
			$response->getData()
		);
		$this->assertSame(['no-mark', 'undecided-entities', 'stale-mark'], [ReviewRequiredException::NO_MARK, ReviewRequiredException::UNDECIDED_ENTITIES, ReviewRequiredException::STALE_MARK]);
		$this->assertSame('stale-mark', $refusal->reason);
		$this->assertSame(0, $refusal->undecided);
	}//end testAnUncheckedFileIsRefusedWith409()
}//end class
