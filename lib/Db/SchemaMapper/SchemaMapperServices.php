<?php

/**
 * OpenRegister SchemaMapperServices
 *
 * What a schema save runs through: the event dispatcher, the property validator and the logger.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Db
 * @package  OCA\OpenRegister\Db\SchemaMapper
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/register-slug-resolution/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db\SchemaMapper;

use OCA\OpenRegister\Service\Schemas\PropertyValidatorHandlerInterface;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\ICacheFactory;
use Psr\Log\LoggerInterface;

/**
 * What a schema save runs through: the event dispatcher, the property validator and the logger.
 *
 * @spec openspec/specs/register-slug-resolution/spec.md
 */
final readonly class SchemaMapperServices {
	/**
	 * Bundle the collaborators.
	 *
	 * @param IEventDispatcher                  $eventDispatcher Event dispatcher for schema events and for collecting contributed declaration checks.
	 * @param PropertyValidatorHandlerInterface $validator       Schema property validator for validation.
	 * @param LoggerInterface                   $logger          Structured logger (surfaces unknown annotation keys).
	 * @param ICacheFactory|null                $cacheFactory    Builds the cross-request row cache; null reads full rows every time.
	 */
	public function __construct(
		public IEventDispatcher $eventDispatcher,
		public PropertyValidatorHandlerInterface $validator,
		public LoggerInterface $logger,
		public ?ICacheFactory $cacheFactory = null,
	) {
	}//end __construct()
}//end class
