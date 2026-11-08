<?php

/**
 * TenancyCollaborators: the tenancy helpers the Db layer builds, behind their interfaces.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
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

namespace OCA\OpenRegister\Service;

use OCP\IAppConfig;
use OCP\IDBConnection;

/**
 * Builds the tenancy helpers the mappers' tenancy traits use.
 *
 * The traits live in the Db layer and build these with `new`, from the
 * connection and the app config they already hold. This class does exactly
 * that and hands the instance back typed against its interface, so the Db
 * layer names the interface and never the class that implements it.
 *
 * Types only: what is checked, logged or refused stays with the caller.
 */
final class TenancyCollaborators {

	/**
	 * A fresh shared-master-data resolver over the given connection.
	 *
	 * @param IDBConnection $db The connection the declarations are read through.
	 *
	 * @return SharedMasterDataServiceInterface
	 */
	public static function sharedMasterData(IDBConnection $db): SharedMasterDataServiceInterface {
		return new SharedMasterDataService(db: $db);
	}//end sharedMasterData()

	/**
	 * A fresh tenant log redactor.
	 *
	 * @param IAppConfig|null $appConfig The app config, when available.
	 *
	 * @return TenantLogRedactorInterface
	 */
	public static function tenantLogRedactor(?IAppConfig $appConfig): TenantLogRedactorInterface {
		return new TenantLogRedactor(appConfig: $appConfig);
	}//end tenantLogRedactor()
}//end class
