<?php

declare(strict_types=1);

namespace NITSAN\NsT3AL\Domain\Repository;

use Doctrine\DBAL\Exception as DbalException;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Persistence\Generic\Typo3QuerySettings;
use TYPO3\CMS\Extbase\Persistence\QueryInterface;
use TYPO3\CMS\Extbase\Persistence\Repository;

class LogsRepository extends Repository
{
    /**
     * Same as the `logRetentionDays` default in ext_conf_template.txt; used while that setting was never saved.
     */
    public const DEFAULT_RETENTION_DAYS = 365;

    private const TABLE_NAME = 'tx_nst3al_domain_model_logs';

    public function initializeObject(): void
    {
        /** @var Typo3QuerySettings $querySettings */
        $querySettings = $this->createQuery()->getQuerySettings();
        $querySettings->setRespectStoragePage(false);
        $this->setDefaultQuerySettings($querySettings);
    }

    /**
     * @param array<string, mixed> $data
     * @throws DbalException
     */
    public function insertRecord(array $data): void
    {
        $connectionPool = GeneralUtility::makeInstance(ConnectionPool::class);
        $connectionPool
            ->getConnectionForTable(self::TABLE_NAME)
            ->insert(
                self::TABLE_NAME,
                $data
            );
    }

    /**
     * Rows without a diff (failed writes, legacy rows) cannot be rendered, so they are left out here to keep
     * pagination and the "no logs" notice in line with what the Logs tab shows.
     */
    public function findRecords(array $filter = [])
    {
        $query = $this->createQuery();
        $constraints = [$query->logicalNot($query->equals('content', ''))];

        if (isset($filter['extensionKey']) && $filter['extensionKey']) {
            $constraints[] = $query->equals('extensionName', $filter['extensionKey']);
        }
        if (isset($filter['sourceFile']) && $filter['sourceFile']) {
            $constraints[] = $query->equals('sourceFile', $filter['sourceFile']);
        }
        if (isset($filter['targetLanguage']) && $filter['targetLanguage']) {
            $constraints[] = $query->equals('targetLanguage', $filter['targetLanguage']);
        }
        $query->matching($query->logicalAnd(...$constraints));
        $query->setOrderings(['uid' => QueryInterface::ORDER_DESCENDING]);

        return $query->execute();

    }

    public function findTargetLanguage(array $filter = []): array
    {
        $queryBuilder = $this->getPreparedQueryBuilder();
        $result = $queryBuilder
            ->select('target_language')
            ->from(self::TABLE_NAME)
            ->where(
                $queryBuilder->expr()->eq('extension_name', $queryBuilder->createNamedParameter($filter['extensionKey'])),
                $queryBuilder->expr()->eq('source_file', $queryBuilder->createNamedParameter($filter['sourceFile'])),
                $queryBuilder->expr()->neq('content', $queryBuilder->createNamedParameter(''))
            )
            ->groupBy('target_language')
            ->executeQuery()->fetchAllAssociative();

        if ($result) {
            return array_column($result, 'target_language');
        }

        return [];
    }

    /**
     * Hard-deletes logs older than the retention period (also rows deleted in the module); 0 keeps all logs.
     */
    public function deleteExpired(int $retentionDays): int
    {
        if ($retentionDays <= 0) {
            return 0;
        }
        $queryBuilder = $this->getPreparedQueryBuilder();
        return $queryBuilder
            ->delete(self::TABLE_NAME)
            ->where($queryBuilder->expr()->lt(
                'crdate',
                $queryBuilder->createNamedParameter(time() - $retentionDays * 86400, Connection::PARAM_INT)
            ))
            ->executeStatement();
    }

    protected function getPreparedQueryBuilder(): QueryBuilder
    {
        $connectionPool = GeneralUtility::makeInstance(ConnectionPool::class);
        return $connectionPool->getQueryBuilderForTable(self::TABLE_NAME);

    }

}
