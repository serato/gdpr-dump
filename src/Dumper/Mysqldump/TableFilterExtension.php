<?php

declare(strict_types=1);

namespace Smile\GdprDump\Dumper\Mysqldump;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;
use Smile\GdprDump\Database\Metadata\Definition\Constraint\ForeignKey;
use Smile\GdprDump\Database\Metadata\MetadataInterface;
use Smile\GdprDump\Database\TableDependencyResolver;
use Smile\GdprDump\Dumper\Config\DumperConfig;
use Smile\GdprDump\Dumper\Config\Table\Filter\Filter;
use Smile\GdprDump\Dumper\Config\Table\TableConfig;
use UnexpectedValueException;

class TableFilterExtension implements ExtensionInterface
{
    /**
     * @var Connection
     */
    private $connection;

    /**
     * @var MetadataInterface
     */
    private $metadata;

    /**
     * @var DumperConfig
     */
    private $config;

    /**
     * @inheritdoc
     */
    public function register(Context $context): void
    {
        $this->connection = $context->getDatabase()->getConnection();
        $this->metadata = $context->getDatabase()->getMetadata();
        $this->config = $context->getConfig();

        $context->getDumper()->setTableWheres($this->buildTablesWhere());
    }

    /**
     * Get the filters to apply on each table.
     *
     * @return array
     */
    private function buildTablesWhere(): array
    {
        // Get the tables to sort/filter
        $tablesToFilter = $this->config->getTablesToFilter();
        $tablesToSort = $this->config->getTablesToSort();

        // Do nothing if there is no sort order/filter defined in the configuration
        if (empty($tablesToFilter) && empty($tablesToSort)) {
            return [];
        }

        $dependencies = [];
        $tableWheres = [];

        // If recursive filters are enabled, tables to query must contain
        // all tables that depend on the tables that have filters/sort order
        if ($this->config->isFilterPropagationEnabled()) {
            $dependencyResolver = new TableDependencyResolver($this->metadata, $this->config);
            $dependencies = $dependencyResolver->getDependencies($tablesToFilter);
        }

        // Tables to query are:
        // - tables with filters or sort orders declared in the config
        // - tables that depend on the tables to filter
        $tablesToQuery = array_unique(array_merge(array_keys($dependencies), $tablesToFilter, $tablesToSort));

        foreach ($tablesToQuery as $tableName) {
            // Create the query that will contain a combination of filter / sort order / limit
            $queryBuilder = $this->createQueryBuilder($tableName);

            // Add where conditions on the parent tables that also have active filters
            if (
                $this->config->isFilterPropagationEnabled()
                && $queryBuilder->getMaxResults() !== 0
                && array_key_exists($tableName, $dependencies)
            ) {
                $this->addDependentFilter($tableName, $queryBuilder, $dependencies);
            }

            // Convert the query to SQL, starting from the "WHERE" clause
            $tableWheres[$tableName] = $this->getWhereSql($queryBuilder);
        }

        // Sort by table name (easier to debug)
        ksort($tableWheres);

        return $tableWheres;
    }

    /**
     * Add a filter on the dependent tables, by iterating on all foreign keys of the table.
     *
     * Example with the following dependencies:
     * - "addresses": foreign key to "customers" (customer_id field)
     * - "customers": foreign key to "stores" (store_id field)
     *
     * With $tableName = 'addresses', this function will add the following filter to the query:
     *
     * ```
     * WHERE `customer_id` IN (
     *     SELECT * FROM (SELECT `customer_id` FROM `customers` WHERE ... AND `store_id` IN (
     *         SELECT * FROM (SELECT `store_id` FROM `stores` WHERE ...) `sub2`
     *     )) `sub1`
     * );
     * ```
     *
     * Where `...` are the filters defined in the YAML config (if any).
     *
     * Internal parameters:
     * - $processedTables: used to detect cyclic dependencies and stop the recursion
     * - $subQueryCount: used to generate unique query names
     *
     * @param string $tableName
     * @param QueryBuilder $queryBuilder
     * @param array $dependencies
     * @param array $processedTables
     * @param int $subQueryCount
     */
    private function addDependentFilter(
        string $tableName,
        QueryBuilder $queryBuilder,
        array $dependencies,
        array $processedTables = [],
        int &$subQueryCount = 0
    ): void {
        if (empty($processedTables)) {
            // Initialize $processedTables with the table name that was initially passed to the function
            // (otherwise, if this table had a cyclic dependency on itself, it would not be detected)
            $processedTables[$tableName] = true;
        }

        /** @var ForeignKey $dependency */
        foreach ($dependencies[$tableName] as $dependency) {
            $tableName = $dependency->getForeignTableName();

            // Stop recursion when a cyclic dependency is detected
            if (array_key_exists($tableName, $processedTables)) {
                continue;
            }

            $processedTables[$tableName] = true;

            $subQuery = $this->createQueryBuilder($tableName);
            $subQuery->select($this->getColumnsSql($dependency->getForeignColumns()));

            // Recursively add condition on parent tables
            if ($subQuery->getMaxResults() !== 0 && array_key_exists($tableName, $dependencies)) {
                $this->addDependentFilter($tableName, $subQuery, $dependencies, $processedTables, $subQueryCount);
            }

            // Prepare the condition data
            $subQueryCount++;
            $subQueryName = $this->connection->quoteIdentifier('sub_' . $subQueryCount);
            $columnsSql = $this->getColumnsSql($dependency->getLocalColumns(), true);

            // Filter on the foreign keys
            // (wrap the sub query in a SELECT * FROM (...),
            // because otherwise a sub query cannot declare the LIMIT clause)
            $expr = $queryBuilder
                ->expr()
                ->comparison($columnsSql, 'IN', '(SELECT * FROM (' . $subQuery . ') ' . $subQueryName . ')');

            // Allow null values
            foreach ($dependency->getLocalColumns() as $column) {
                $expr = $queryBuilder
                    ->expr()
                    ->or($expr, $subQuery->expr()->isNull($this->connection->quoteIdentifier($column)));
            }

            $queryBuilder->andWhere($expr);
        }
    }

    /**
     * Create a query builder that applies the configuration of the specified table.
     *
     * @param string $tableName
     * @return QueryBuilder
     */
    private function createQueryBuilder(string $tableName): QueryBuilder
    {
        // Create the query builder
        $queryBuilder = $this->connection->createQueryBuilder();

        // Select all columns of the table
        $queryBuilder->select('*')
            ->from($this->connection->quoteIdentifier($tableName));

        // Get the table configuration
        $tableConfig = $this->config->getTableConfig($tableName);
        if ($tableConfig !== null) {
            $this->applyTableConfigToQueryBuilder($queryBuilder, $tableConfig);
        }

        return $queryBuilder;
    }

    /**
     * Apply a table configuration to a query builder.
     *
     * @param QueryBuilder $queryBuilder
     * @param TableConfig $tableConfig
     */
    private function applyTableConfigToQueryBuilder(QueryBuilder $queryBuilder, TableConfig $tableConfig): void
    {
        // Apply filters
        foreach ($tableConfig->getFilters() as $filter) {
            $value = $this->getFilterValue($filter);

            $whereExpr = call_user_func_array(
                [$queryBuilder->expr(), $filter->getOperator()],
                [$this->connection->quoteIdentifier($filter->getColumn()), $value]
            );

            $queryBuilder->orWhere($whereExpr);
        }

        // Apply sort orders
        foreach ($tableConfig->getSortOrders() as $sortOrder) {
            $queryBuilder->addOrderBy(
                $this->connection->quoteIdentifier($sortOrder->getColumn()),
                $sortOrder->getDirection()
            );
        }

        // Apply limit
        $limit = $tableConfig->getLimit();
        if ($limit !== null) {
            $queryBuilder->setMaxResults($limit);
        }
    }

    /**
     * Get the SQL query that represents a list of columns.
     *
     * @param array $columns
     * @param bool $enclose
     * @return string
     */
    private function getColumnsSql(array $columns, bool $enclose = false): string
    {
        foreach ($columns as $index => $column) {
            $columns[$index] = $this->connection->quoteIdentifier($column);
        }

        $result = implode(',', $columns);

        if (count($columns) > 1 && $enclose) {
            // Enclose selected columns with parentheses
            $result = '(' . $result . ')';
        }

        return $result;
    }

    /**
     * Get the query as SQL, starting from the WHERE clause.
     *
     * @param QueryBuilder $queryBuilder
     * @return string
     */
    public function getWhereSql(QueryBuilder $queryBuilder): string
    {
        $wherePart = $queryBuilder->getQueryPart('where');

        if (empty($wherePart)) {
            $queryBuilder->where(1);
        }

        $sql = $queryBuilder->getSQL();

        return substr($sql, strpos($sql, ' WHERE ') + 7);
    }

    /**
     * Get a filter value.
     *
     * @param Filter $filter
     * @return mixed
     */
    private function getFilterValue(Filter $filter)
    {
        $value = $filter->getValue();

        if (is_array($value)) {
            foreach ($value as $k => $v) {
                $value[$k] = $this->quoteValue($v);
            }

            return $value;
        }

        return $this->quoteValue($value);
    }

    /**
     * Quote a value so that it can be safely injected in a SQL query.
     * (we can't use query params because Mysqldump library doesn't allow it)
     *
     * @param mixed $value
     * @return mixed
     */
    private function quoteValue($value)
    {
        if ($value !== null && !is_scalar($value)) {
            throw new UnexpectedValueException('Non-scalar values can not be used in filters.');
        }

        if (is_bool($value)) {
            return (int) $value;
        }

        if (is_string($value)) {
            return strpos($value, 'expr:') === 0 ? ltrim(substr($value, 5)) : $this->connection->quote($value);
        }

        return $value;
    }
}
