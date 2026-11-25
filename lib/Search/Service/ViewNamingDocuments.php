<?php

/**
 * ViewNamingDocuments: every saved view as the document a rename preview reads.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Search\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Search\Service;

use OCA\OpenRegister\Kernel\Contracts\ViewNamingDocumentsInterface;
use OCA\OpenRegister\Kernel\Contracts\ViewInterface;
use OCA\OpenRegister\Search\Db\ViewMapperInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reads the saved views the caller may read, as their query and presentation.
 *
 * @spec openspec/changes/modelling-rename-without-loss/tasks.md#1.2
 */
class ViewNamingDocuments implements ViewNamingDocumentsInterface {

	/**
	 * Constructor.
	 *
	 * @param ViewMapperInterface $views  The saved views.
	 * @param LoggerInterface     $logger Logs an unreadable list.
	 */
	public function __construct(
		private readonly ViewMapperInterface $views,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Every readable saved view as a document; none when the list cannot be read.
	 *
	 * @return list<array{kind: string, id: mixed, name: string|null, content: array<mixed>}>
	 *
	 * @spec openspec/changes/modelling-rename-without-loss/tasks.md#1.2
	 */
	public function namingDocuments(): array {
		try {
			$views = $this->views->findAll();
		} catch (Throwable $e) {
			$this->logger->warning(
				message: '[ViewNamingDocuments] Could not list the saved views; the rename preview does not check them',
				context: ['error' => $e->getMessage()]
			);
			return [];
		}

		$documents = [];
		foreach ($views as $view) {
			if (($view instanceof ViewInterface) === false) {
				continue;
			}

			$documents[] = [
				'kind' => 'view',
				'id' => ($view->getUuid() ?? $view->getId()),
				'name' => $view->getName(),
				'content' => ['query' => ($view->getQuery() ?? []), 'presentation' => ($view->getPresentation() ?? [])],
			];
		}

		return $documents;
	}//end namingDocuments()
}//end class
