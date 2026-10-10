<?php

/**
 * CredentialProviderLabelsTest: the provider titles a person reads in the
 * "Add credential" picker are plain words, in their own language.
 *
 * Reads the REAL catalogue (lib/Settings/credential-providers.json) through the
 * REAL ProviderCatalogue and the REAL CredentialController::providers(), and the
 * Dutch titles from the REAL l10n/nl.json bundle. Before this change five titles
 * carried an em-dash ("Anthropic (Claude) — API key", "GitHub — repository
 * push…", "Bluesky (AT Protocol) — preview") and every title shipped in English
 * only, whatever the person's language.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
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
 * @spec openspec/changes/credential-provider-labels-in-plain-words/specs/credential-broker/spec.md
 */

declare(strict_types=1);

namespace Unit\Controller;

use OCA\OpenRegister\Controller\CredentialController;
use OCA\OpenRegister\Service\Credential\CredentialAppTokenService;
use OCA\OpenRegister\Service\Credential\CredentialBrokerService;
use OCA\OpenRegister\Service\Credential\CredentialStore;
use OCA\OpenRegister\Service\Credential\ProviderCatalogue;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\OpenRegister\Service\Sharing\SharePrincipalDeriver;
use OCP\App\IAppManager;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\Controller\CredentialController
 */
class CredentialProviderLabelsTest extends TestCase {

	/**
	 * An em-dash, an en-dash, or a hyphen standing in for one between spaces.
	 */
	private const DASH_PATTERN = '/[\x{2014}\x{2013}]| - /u';

	/**
	 * The app root, four levels above this file's directory.
	 *
	 * @return string
	 */
	private function appRoot(): string {
		return dirname(__DIR__, 3);
	}//end appRoot()

	/**
	 * The real catalogue, read from the shipped file.
	 *
	 * @return ProviderCatalogue
	 */
	private function catalogue(): ProviderCatalogue {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getAppPath')->willReturn($this->appRoot());

		return new ProviderCatalogue($appManager, $this->createMock(LoggerInterface::class));
	}//end catalogue()

	/**
	 * An IL10N that answers from the real l10n/nl.json bundle, falling back to
	 * the source string exactly as Nextcloud does for a missing key.
	 *
	 * @return IL10N
	 */
	private function dutch(): IL10N {
		$bundle = json_decode((string)file_get_contents($this->appRoot() . '/l10n/nl.json'), true);
		$translations = $bundle['translations'];

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static function (string $text) use ($translations): string {
				$value = $translations[$text] ?? $text;
				return is_string($value) === true ? $value : $text;
			}
		);

		return $l10n;
	}//end dutch()

	/**
	 * The controller over the real catalogue, signed in as one user.
	 *
	 * @param IL10N|null $l10n The translator, or null for none.
	 *
	 * @return CredentialController
	 */
	private function controller(?IL10N $l10n): CredentialController {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return new CredentialController(
			'openregister',
			$this->createMock(IRequest::class),
			$session,
			$this->createMock(IGroupManager::class),
			$this->createMock(ObjectService::class),
			$this->createMock(CredentialStore::class),
			$this->catalogue(),
			$this->createMock(CredentialBrokerService::class),
			$this->createMock(CredentialAppTokenService::class),
			$this->createMock(OrganisationService::class),
			new SharePrincipalDeriver(),
			$this->createMock(LoggerInterface::class),
			$l10n
		);
	}//end controller()

	/**
	 * The titles the providers endpoint returns, keyed by identifier.
	 *
	 * @param IL10N|null $l10n The translator, or null for none.
	 *
	 * @return array<string, string>
	 */
	private function endpointTitles(?IL10N $l10n): array {
		$data = $this->controller($l10n)->providers()->getData();
		$titles = [];
		foreach ($data['results'] as $row) {
			$titles[$row['identifier']] = $row['title'];
		}

		return $titles;
	}//end endpointTitles()

	/**
	 * Every title in the shipped catalogue is free of em-dashes and en-dashes.
	 *
	 * @return void
	 */
	public function testEveryCatalogueTitleIsFreeOfDashes(): void {
		$entries = $this->catalogue()->all();
		$this->assertNotEmpty($entries);

		foreach ($entries as $id => $entry) {
			$title = (string)($entry['title'] ?? '');
			$this->assertNotSame('', $title, $id . ' has no title');
			$this->assertDoesNotMatchRegularExpression(self::DASH_PATTERN, $title, $id . ': "' . $title . '"');
		}
	}//end testEveryCatalogueTitleIsFreeOfDashes()

	/**
	 * The picker's source, the providers endpoint, returns no dashed title, and
	 * keeps every identifier as it was.
	 *
	 * @return void
	 */
	public function testTheProvidersEndpointReturnsNoDashedTitle(): void {
		$titles = $this->endpointTitles(null);

		foreach (['anthropic', 'anthropic-oauth', 'anthropic-cli', 'github-push', 'bluesky'] as $id) {
			$this->assertArrayHasKey($id, $titles, 'identifier ' . $id . ' must stay');
		}

		foreach ($titles as $id => $title) {
			$this->assertDoesNotMatchRegularExpression(self::DASH_PATTERN, $title, $id . ': "' . $title . '"');
		}
	}//end testTheProvidersEndpointReturnsNoDashedTitle()

	/**
	 * A Dutch reader gets the Dutch titles from the endpoint, and those are
	 * dash-free too.
	 *
	 * @return void
	 */
	public function testTheProvidersEndpointTranslatesTitlesForADutchReader(): void {
		$titles = $this->endpointTitles($this->dutch());

		$this->assertSame('Anthropic Claude API-sleutel', $titles['anthropic']);
		$this->assertSame('Anthropic Claude Max-abonnement via OAuth', $titles['anthropic-oauth']);
		$this->assertSame('Anthropic Claude Max-abonnement voor de CLI', $titles['anthropic-cli']);
		$this->assertSame('GitHub push naar één repository (fijnmazig token, alleen inhoud)', $titles['github-push']);
		$this->assertSame('Bluesky (AT Protocol, proefversie)', $titles['bluesky']);
		$this->assertSame('Algemene API-sleutel (de app voegt hem in)', $titles['generic-apikey']);

		foreach ($titles as $id => $title) {
			$this->assertDoesNotMatchRegularExpression(self::DASH_PATTERN, $title, $id . ': "' . $title . '"');
		}
	}//end testTheProvidersEndpointTranslatesTitlesForADutchReader()
}//end class
