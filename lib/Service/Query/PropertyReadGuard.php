<?php

/**
 * Answers whether the caller may read a property, for filters that match on it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Query
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/row-field-level-security/spec.md#requirement-a-property-read-rule-holds-on-every-route-that-returns-its-value
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Query;

use OCA\OpenRegister\Service\PropertyAccessRequest;
use OCA\OpenRegister\Service\SchemaProperty;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\PropertyRbacHandler;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * A filter on a property is a boolean oracle on its value.
 *
 * Whether a row matches `field = x` tells the caller what the field holds, one
 * guess at a time, whether or not the response ever contains the field. So the
 * property read rule has to hold for a property a filter NAMES, not only for
 * one a response returns. Facets and aggregates already withhold a property the
 * caller cannot read; this is the same question asked for the filters that
 * reach through a reference or into an object property.
 *
 * The question is asked the way the facet path asks it: the property read rule
 * with an EMPTY object, because a filter is not about one record. A rule that
 * depends on the record therefore does not admit the filter, which is the safe
 * direction. It FAILS CLOSED: when the handler cannot be resolved or throws,
 * the property is treated as unreadable.
 *
 * @spec openspec/specs/row-field-level-security/spec.md#requirement-a-property-read-rule-holds-on-every-route-that-returns-its-value
 */
class PropertyReadGuard {

	/**
	 * The guard.
	 *
	 * @param ContainerInterface|null $container Resolves the property read rule lazily (it is built after the query layer).
	 * @param LoggerInterface         $logger    The logger.
	 */
	public function __construct(
		private readonly ?ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Whether the caller may read a property of a schema at all.
	 *
	 * @param Schema $schema   The schema the property belongs to.
	 * @param string $property The property name.
	 *
	 * @return bool False when the property read rule withholds it, or cannot be evaluated.
	 *
	 * @spec openspec/specs/row-field-level-security/spec.md#requirement-a-property-read-rule-holds-on-every-route-that-returns-its-value
	 */
	public function mayRead(Schema $schema, string $property): bool {
		if ($schema->hasPropertyAuthorization() === false) {
			// Nothing on this schema is governed at property level.
			return true;
		}

		if ($this->container === null) {
			return $this->refuseWithoutContainer(property: $property);
		}

		return $this->checkedRead(property: new SchemaProperty(schema: $schema, name: $property), container: $this->container);
	}//end mayRead()

	/**
	 * Refuse a governed property when there is no container to resolve its read rule.
	 *
	 * @param string $property The property name.
	 *
	 * @return bool Always false.
	 */
	private function refuseWithoutContainer(string $property): bool {
		$this->logger->warning(
			message: '[PropertyReadGuard] No container to resolve the property read rule; treating the property as unreadable',
			context: ['file' => __FILE__, 'line' => __LINE__, 'property' => $property]
		);
		return false;
	}//end refuseWithoutContainer()

	/**
	 * Ask the property read rule; a rule that cannot be evaluated refuses.
	 *
	 * @param SchemaProperty     $property  The schema and the property name.
	 * @param ContainerInterface $container Resolves the property RBAC handler.
	 *
	 * @return bool Whether the caller may read the property.
	 */
	private function checkedRead(SchemaProperty $property, ContainerInterface $container): bool {
		try {
			$rbac = $container->get(PropertyRbacHandler::class);

			return $rbac->canReadProperty(property: $property, request: new PropertyAccessRequest(object: []));
		} catch (Throwable $e) {
			return $this->refuseUncheckable(property: $property->name, error: $e);
		}
	}//end checkedRead()

	/**
	 * Refuse a property whose read rule could not be evaluated, saying why.
	 *
	 * @param string    $property The property name.
	 * @param Throwable $error    Why the rule could not be evaluated.
	 *
	 * @return bool Always false.
	 */
	private function refuseUncheckable(string $property, Throwable $error): bool {
		$this->logger->warning(
			message: '[PropertyReadGuard] Could not check the property read rule; treating the property as unreadable',
			context: [
				'file'      => __FILE__,
				'line'      => __LINE__,
				'property'  => $property,
				'exception' => $error->getMessage(),
			]
		);
		return false;
	}//end refuseUncheckable()
}//end class
