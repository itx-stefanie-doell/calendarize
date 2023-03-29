<?php

namespace HDNET\Calendarize\EventListener;

use HDNET\Calendarize\Domain\Model\PluginConfiguration;
use HDNET\Calendarize\Event\IndexRepositoryDefaultConstraintEvent;
use HDNET\Calendarize\Register;
use HDNET\Calendarize\Utility\HelperUtility;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Utility\MathUtility;

class DefaultEventConstraintsListener
{
    public function __invoke(IndexRepositoryDefaultConstraintEvent $event)
    {
        $indexTypes = $event->getIndexTypes();
        $indexIds = $event->getIndexIds();

        if (empty($indexTypes)) {
            return;
        }

        $conjunction = strtolower($event->getAdditionalSlotArguments()['settings']['categoryConjunction']);

        // If "ignore category selection" is used, nothing needs to be done
        // An empty value is assumed to be OR for backwards compatibility.
        if ('all' === $conjunction) {
            return;
        }

        $categoryIds = $this->getCategoryIds($event->getAdditionalSlotArguments());
        if (empty($categoryIds)) {
            return;
        }

        $tables = $this->getTableNames($indexTypes, $indexIds);

        $newIndexIds = $this->getIndexIds($categoryIds, $conjunction, $tables);

        $event->setIndexIds($indexIds + $newIndexIds);
    }

    /**
     * Gets the index IDs (foreign tables and foreign UIDs) that have the categories set.
     *
     * @param array  $categories
     * @param string $conjunction
     * @param array  $tables
     *
     * @return array
     *
     * @throws \Doctrine\DBAL\DBALException
     * @throws \Doctrine\DBAL\Driver\Exception
     */
    protected function getIndexIds(array $categories, string $conjunction, array $tables)
    {
        $uidLocal_field = 'uid_local'; // Category uid
        $uidForeign_field = 'uid_foreign'; // Event uid
        $tableNames = 'tablenames';
        $MM_table = 'sys_category_record_mm';

        $q = HelperUtility::getDatabaseConnection($MM_table)
            ->createQueryBuilder();
        $q->select($tableNames, $uidForeign_field)
            ->from($MM_table)
            ->groupBy($tableNames, $uidForeign_field)
            ->where(
                $q->expr()->eq(
                    'fieldname',
                    $q->createNamedParameter('categories')
                ),
                $q->expr()->in(
                    $tableNames,
                    $q->createNamedParameter($tables, Connection::PARAM_STR_ARRAY)
                )
            );

        switch ($conjunction) {
            case 'and':
                // Relational Algebra
                $q->andWhere(
                    $q->expr()->in(
                        $uidLocal_field,
                        $q->createNamedParameter($categories, Connection::PARAM_INT_ARRAY)
                    )
                )->having(
                    'COUNT(DISTINCT ' . $q->quoteIdentifier($uidLocal_field) . ') = '
                    . $q->createNamedParameter(\count($categories), \PDO::PARAM_INT)
                );
                break;

            case 'or':
            default:
                $q->andWhere(
                    $q->expr()->in(
                        $uidLocal_field,
                        $q->createNamedParameter($categories, Connection::PARAM_INT_ARRAY)
                    )
                );
        }

        $result = $q->executeQuery();

        $indexIds = [];
        while ($row = $result->fetchAssociative()) {
            $indexIds[$row[$tableNames]][] = $row[$uidForeign_field];
        }

        // Enforce no result for all tables without a match
        foreach ($tables as $table) {
            if (empty($indexIds[$table])) {
                $indexIds[$table] = [-1];
            }
        }

        return $indexIds;
    }

    /**
     * Get the selected categories of the content configuration and plugin configuration model.
     *
     * @param array $additionalSlotArguments
     *
     * @return array
     *
     * @throws \Doctrine\DBAL\DBALException
     * @throws \Doctrine\DBAL\Driver\Exception
     */
    protected function getCategoryIds(array $additionalSlotArguments): array
    {
        $table = 'sys_category_record_mm';
        $db = HelperUtility::getDatabaseConnection($table);
        $q = $db->createQueryBuilder();

        $categoryIds = [];
        if (isset($additionalSlotArguments['contentRecord']['uid']) && MathUtility::canBeInterpretedAsInteger($additionalSlotArguments['contentRecord']['uid'])) {
            $categoryIds = $q->select('uid_local')
                ->from($table)
                ->where(
                    $q->expr()->andX(
                        $q->expr()->eq('tablenames', $q->createNamedParameter('tt_content')),
                        $q->expr()->eq('fieldname', $q->createNamedParameter('categories')),
                        $q->expr()->eq('uid_foreign', $q->createNamedParameter($additionalSlotArguments['contentRecord']['uid']))
                    )
                )
                ->executeQuery()
                ->fetchFirstColumn();
        }

        if (isset($additionalSlotArguments['settings']['pluginConfiguration']) && $additionalSlotArguments['settings']['pluginConfiguration'] instanceof PluginConfiguration) {
            /** @var PluginConfiguration $pluginConfiguration */
            $pluginConfiguration = $additionalSlotArguments['settings']['pluginConfiguration'];
            $categories = $pluginConfiguration->getCategories();
            foreach ($categories as $category) {
                $categoryIds[] = $category->getUid();
            }
        }

        // Remove duplicate IDs
        return array_unique($categoryIds);
    }

    /**
     * Get all table names for filtering.
     * Models with ids already set or without category field are ignored.
     *
     * @param array $indexTypes
     * @param array $indexIds
     *
     * @return array
     */
    protected function getTableNames(array $indexTypes, array $indexIds): array
    {
        $tables = [];

        foreach ($indexTypes as $type) {
            $tableName = Register::getRegister()[$type]['tableName'] ?? null;
            if (null === $tableName) {
                continue;
            }
            // Skip if there are already ids (e.g. by other extensions)
            // We don't want to overwrite such values
            if (isset($indexIds[$tableName])) {
                continue;
            }

            // Check if the table has categories
            if (!isset($GLOBALS['TCA'][$tableName]['columns']['categories'])) {
                continue;
            }
            $tables[] = $tableName;
        }

        return $tables;
    }
}
