<?php

/**
 * OpenRegister SchemaImportInstaller.
 *
 * Installs what an imported schema declares when the import changed nothing.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Configuration
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

namespace OCA\OpenRegister\Service\Configuration;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Listener\SchemaFlowImportListener;
use OCA\OpenRegister\Service\Notification\NotificationsAnnotationInstaller;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Runs the schema installers for an import that fired no SchemaUpdatedEvent.
 *
 * Two SchemaUpdatedEvent listeners rebuild stored state from the schema's
 * annotations: the flow importer (`x-openregister-flows`) and the
 * notification webhook installer (`x-openregister-notifications`). Both used
 * to run on every import, because every import fired the event, and that is
 * what brought back a shipped flow or webhook somebody deleted. Now an import
 * of an identical schema fires nothing, so the import calls them itself.
 *
 * The other listeners only invalidate caches, warn, or drop rows a changed
 * schema no longer declares; an unchanged schema gives them nothing to do.
 *
 * Both installers are idempotent upserts. A failure is logged and never fails
 * the import, the same contract the listeners keep.
 *
 * @spec openspec/changes/events-at-the-level-of-change/specs/event-driven-architecture/spec.md#requirement-an-import-of-an-unchanged-schema-still-installs-what-it-declares
 */
class SchemaImportInstaller {
	/**
	 * Wire the two installers.
	 *
	 * @param SchemaFlowImportListener $flows Imports the flows a schema declares.
	 * @param NotificationsAnnotationInstaller $notifications Installs the webhooks a schema's notifications declare.
	 * @param LoggerInterface $logger Logger.
	 *
	 * @spec openspec/changes/events-at-the-level-of-change/specs/event-driven-architecture/spec.md#requirement-an-import-of-an-unchanged-schema-still-installs-what-it-declares
	 */
	public function __construct(
		private readonly SchemaFlowImportListener $flows,
		private readonly NotificationsAnnotationInstaller $notifications,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Install what the schema declares.
	 *
	 * @param Schema $schema The imported schema.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/events-at-the-level-of-change/specs/event-driven-architecture/spec.md#requirement-an-import-of-an-unchanged-schema-still-installs-what-it-declares
	 */
	public function install(Schema $schema): void {
		try {
			$this->flows->importFor(schema: $schema);
		} catch (Throwable $e) {
			$this->logger->warning('[SchemaImportInstaller] Flows not installed for schema ' . $schema->getSlug() . ': ' . $e->getMessage());
		}

		try {
			$this->notifications->installSchema(schema: $schema);
		} catch (Throwable $e) {
			$this->logger->warning('[SchemaImportInstaller] Notification webhooks not installed for schema ' . $schema->getSlug() . ': ' . $e->getMessage());
		}
	}//end install()
}//end class
