<?php

/**
 * Minimal Doctrine DBAL stubs for PHPUnit in the bare php:8.3-cli CI environment.
 *
 * The `nextcloud/ocp` v31 stubs reference several Doctrine DBAL 3.x/4.x classes
 * in their `use` statements and interface-constant initialisers.  Because
 * doctrine/dbal is not a direct dependency of this app, those classes are absent
 * in the bare-composer install and cause PHP to emit "Class not found" fatal
 * errors before the first test even runs.
 *
 * These stubs replicate only the surface that the OCP stubs actually touch:
 *
 *   • ParameterType   – backed enum (4.x shape) used for IQueryBuilder constants
 *   • ArrayParameterType – backed enum for PARAM_INT_ARRAY / PARAM_STR_ARRAY
 *   • Types           – class with string-typed constants
 *   • Exception       – extends \RuntimeException (used in @throws)
 *   • Connection, AbstractPlatform, ExpressionBuilder, Schema, Type, SQLLogger
 *     – empty stubs sufficient for interface/method signatures in the OCP shims
 *
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

// -----------------------------------------------------------------
// Doctrine\DBAL\ParameterType  (backed enum in DBAL 4.x)
// -----------------------------------------------------------------
if (class_exists(\Doctrine\DBAL\ParameterType::class) === false
    && interface_exists(\Doctrine\DBAL\ParameterType::class) === false
) {
    // DBAL 4 turned this into a backed int enum.  The OCP IQueryBuilder
    // interface uses it only for constant initialisation
    // (PARAM_NULL = ParameterType::NULL, etc.) so the stub only needs to
    // expose the correct enum cases with integer values that match DBAL 4.
    eval('namespace Doctrine\DBAL;
    enum ParameterType: int {
        case NULL         = 0;
        case INTEGER      = 1;
        case STRING       = 2;
        case LARGE_OBJECT = 3;
        case BOOLEAN      = 4;
        case BINARY       = 16;
    }');
}//end if

// -----------------------------------------------------------------
// Doctrine\DBAL\ArrayParameterType  (backed enum in DBAL 4.x)
// -----------------------------------------------------------------
if (class_exists(\Doctrine\DBAL\ArrayParameterType::class) === false
    && interface_exists(\Doctrine\DBAL\ArrayParameterType::class) === false
) {
    eval('namespace Doctrine\DBAL;
    enum ArrayParameterType: int {
        case INTEGER = 101;
        case STRING  = 102;
        case ASCII   = 117;
        case BINARY  = 116;
    }');
}//end if

// -----------------------------------------------------------------
// Doctrine\DBAL\Types\Types  (class with string constants in DBAL 3+)
// -----------------------------------------------------------------
if (class_exists(\Doctrine\DBAL\Types\Types::class) === false) {
    eval('namespace Doctrine\DBAL\Types;
    final class Types {
        public const ARRAY              = "array";
        public const ASCII_STRING       = "ascii_string";
        public const BIGINT             = "bigint";
        public const BINARY             = "binary";
        public const BLOB               = "blob";
        public const BOOLEAN            = "boolean";
        public const DATE_IMMUTABLE     = "date_immutable";
        public const DATE_MUTABLE       = "date";
        public const DATEINTERVAL       = "dateinterval";
        public const DATETIME_IMMUTABLE = "datetime_immutable";
        public const DATETIME_MUTABLE   = "datetime";
        public const DATETIMETZ_IMMUTABLE = "datetimetz_immutable";
        public const DATETIMETZ_MUTABLE = "datetimetz";
        public const DECIMAL            = "decimal";
        public const FLOAT              = "float";
        public const GUID               = "guid";
        public const INTEGER            = "integer";
        public const JSON               = "json";
        public const OBJECT             = "object";
        public const SIMPLE_ARRAY       = "simple_array";
        public const SMALLINT           = "smallint";
        public const STRING             = "string";
        public const TEXT               = "text";
        public const TIME_IMMUTABLE     = "time_immutable";
        public const TIME_MUTABLE       = "time";
    }');
}//end if

// -----------------------------------------------------------------
// Doctrine\DBAL\Exception
// -----------------------------------------------------------------
if (class_exists(\Doctrine\DBAL\Exception::class) === false) {
    eval('namespace Doctrine\DBAL;
    class Exception extends \RuntimeException {}');
}//end if

// -----------------------------------------------------------------
// Doctrine\DBAL\Connection  (used only in type-hints / @throws)
// -----------------------------------------------------------------
if (class_exists(\Doctrine\DBAL\Connection::class) === false) {
    eval('namespace Doctrine\DBAL;
    class Connection {}');
}//end if

// -----------------------------------------------------------------
// Doctrine\DBAL\Platforms\AbstractPlatform
// -----------------------------------------------------------------
if (class_exists(\Doctrine\DBAL\Platforms\AbstractPlatform::class) === false) {
    eval('namespace Doctrine\DBAL\Platforms;
    abstract class AbstractPlatform {
        /** @deprecated */
        public function getName(): string { return ""; }
    }');
}//end if

// -----------------------------------------------------------------
// Doctrine\DBAL\Query\Expression\ExpressionBuilder
// Constants EQ/NEQ/LT/LTE/GT/GTE are used as initialisers in
// IExpressionBuilder interface constants.
// -----------------------------------------------------------------
if (class_exists(\Doctrine\DBAL\Query\Expression\ExpressionBuilder::class) === false) {
    eval('namespace Doctrine\DBAL\Query\Expression;
    class ExpressionBuilder {
        public const EQ  = "=";
        public const NEQ = "<>";
        public const LT  = "<";
        public const LTE = "<=";
        public const GT  = ">";
        public const GTE = ">=";
    }');
}//end if

// -----------------------------------------------------------------
// Doctrine\DBAL\Schema\Schema
// -----------------------------------------------------------------
if (class_exists(\Doctrine\DBAL\Schema\Schema::class) === false) {
    eval('namespace Doctrine\DBAL\Schema;
    class Schema {}');
}//end if

// -----------------------------------------------------------------
// Doctrine\DBAL\Types\Type
// -----------------------------------------------------------------
if (class_exists(\Doctrine\DBAL\Types\Type::class) === false) {
    eval('namespace Doctrine\DBAL\Types;
    abstract class Type {}');
}//end if

// -----------------------------------------------------------------
// Doctrine\DBAL\Platforms\* — various platform stubs for mocking
// -----------------------------------------------------------------
if (class_exists(\Doctrine\DBAL\Platforms\PostgreSQLPlatform::class) === false) {
    eval('namespace Doctrine\DBAL\Platforms;
    class PostgreSQLPlatform extends AbstractPlatform {}');
}//end if
if (class_exists(\Doctrine\DBAL\Platforms\MySQLPlatform::class) === false) {
    eval('namespace Doctrine\DBAL\Platforms;
    class MySQLPlatform extends AbstractPlatform {}');
}//end if
if (class_exists(\Doctrine\DBAL\Platforms\MariaDBPlatform::class) === false) {
    eval('namespace Doctrine\DBAL\Platforms;
    class MariaDBPlatform extends AbstractPlatform {}');
}//end if
if (class_exists(\Doctrine\DBAL\Platforms\SqlitePlatform::class) === false) {
    eval('namespace Doctrine\DBAL\Platforms;
    class SqlitePlatform extends AbstractPlatform {}');
}//end if

// -----------------------------------------------------------------
// Doctrine\DBAL\Query\QueryBuilder  (used in mocks)
// -----------------------------------------------------------------
if (class_exists(\Doctrine\DBAL\Query\QueryBuilder::class) === false) {
    eval('namespace Doctrine\DBAL\Query;
    class QueryBuilder {}');
}//end if

// -----------------------------------------------------------------
// Doctrine\DBAL\Schema\Table  (used in mocks for migration tests)
// -----------------------------------------------------------------
if (class_exists(\Doctrine\DBAL\Schema\Table::class) === false) {
    eval('namespace Doctrine\DBAL\Schema;
    class Table {
        public function hasColumn(string $name): bool { return false; }
        /** @return static|Column */
        public function addColumn(string $name, string $typeName = "string", array $options = []) { return new Column($name, $typeName, $options); }
        public function dropColumn(string $name): void {}
        public function getColumns(): array { return []; }
        public function getColumn(string $name): Column { return new Column($name, "string"); }
        public function getName(): string { return ""; }
        public function hasIndex(string $indexName): bool { return false; }
        public function addIndex(array $columns, ?string $indexName = null, array $flags = [], array $options = []): void {}
        public function dropIndex(string $indexName): void {}
        public function hasPrimaryKey(): bool { return false; }
        public function getIndexes(): array { return []; }
    }');
}//end if

// -----------------------------------------------------------------
// Doctrine\DBAL\Schema\Column  (used by Table stub and mocks)
// -----------------------------------------------------------------
if (class_exists(\Doctrine\DBAL\Schema\Column::class) === false) {
    eval('namespace Doctrine\DBAL\Schema;
    class Column {
        private string $name;
        private string $type;
        private array $options;
        public function __construct(string $name, string $type, array $options = []) {
            $this->name = $name;
            $this->type = $type;
            $this->options = $options;
        }
        public function getName(): string { return $this->name; }
        public function getType(): string { return $this->type; }
        public function getOptions(): array { return $this->options; }
    }');
}//end if

// -----------------------------------------------------------------
// Doctrine\DBAL\Logging\SQLLogger
// -----------------------------------------------------------------
if (interface_exists(\Doctrine\DBAL\Logging\SQLLogger::class) === false
    && class_exists(\Doctrine\DBAL\Logging\SQLLogger::class) === false
) {
    eval('namespace Doctrine\DBAL\Logging;
    interface SQLLogger {
        public function startQuery($sql, ?array $params = null, ?array $types = null): void;
        public function stopQuery(): void;
    }');
}//end if
