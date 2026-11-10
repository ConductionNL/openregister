<?php

/**
 * OAuth2ConnectionRepositoryInterface: what callers outside the component use of OAuth2ConnectionRepository.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Credential
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Credential;

use InvalidArgumentException;
use OCA\OpenRegister\Db\ObjectEntity;
use Throwable;

/**
 * The OAuth2 connections the OAuth2 endpoints find, disable and discard.
 *
 * Declares only what callers outside the component use; the rest of the
 * class stays its own.
 */
interface OAuth2ConnectionRepositoryInterface {

	/**
	 * Delete a connection's stored token set and mark it disabled.
	 *
	 * The custody delete happens FIRST. A disconnect whose object write failed leaves
	 * a credential that looks connected and holds nothing, which fails closed at
	 * broker time; the other order would leave a credential that looks disconnected
	 * and still holds a live token.
	 *
	 * @param string $credentialId The credential UUID.
	 * @param array<string, mixed> $data The credential's property bag.
	 * @param string $lastError A secret-free reason the upstream revoke failed, or an empty string.
	 *
	 * @return void
	 *
	 * @throws Throwable When the custody delete or the object write fails.
	 *
	 * @spec openspec/specs/credential-oauth2-connect/spec.md#requirement-disconnecting-revokes-upstream-where-it-can-and-disables-locally
	 */
	public function disable(string $credentialId, array $data, string $lastError): void;

	/**
	 * Delete a credential outright: its stored secret, then the object.
	 *
	 * For a client credential a connect start minted and then could not use. The
	 * secret goes first, for the reason disable() gives: a failure halfway leaves
	 * an object that holds nothing, never a secret nothing points at.
	 *
	 * @param string $credentialId The credential UUID.
	 * @param string $scope The scope its secret is stored in.
	 *
	 * @return void
	 *
	 * @throws Throwable When the custody delete or the object delete fails.
	 *
	 * @spec openspec/specs/credential-oauth2-connect/spec.md#requirement-bluesky-is-its-own-client-and-mastodon-registers-per-instance
	 */
	public function discard(string $credentialId, string $scope): void;

	/**
	 * Load a connection the caller may manage, or null.
	 *
	 * @param string $credentialId The credential UUID.
	 * @param string $uid The caller.
	 *
	 * @return ObjectEntity|null The credential, or null when absent or not manageable.
	 *
	 * @spec openspec/specs/credential-oauth2-connect/spec.md#requirement-re-authorisation-overrides-the-same-credential
	 */
	public function findManageable(string $credentialId, string $uid): ?ObjectEntity;

	/**
	 * Resolve and gate the organisation an organisation-scoped connect belongs to.
	 *
	 * @param string $uid The initiating user.
	 * @param string $requestedScope The requested credential scope.
	 *
	 * @return string|null The organisation UUID, or null for a personal connect.
	 *
	 * @throws InvalidArgumentException When there is no active organisation.
	 * @throws CredentialAccessDeniedException When the caller does not administer the organisation.
	 *
	 * @spec openspec/specs/credential-oauth2-connect/spec.md#requirement-starting-a-connection-returns-an-authorization-url-bound-to-the-caller
	 */
	public function gatedOrganisation(string $uid, string $requestedScope): ?string;
}//end interface
