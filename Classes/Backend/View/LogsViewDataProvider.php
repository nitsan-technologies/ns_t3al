<?php

declare(strict_types=1);

namespace NITSAN\NsT3AL\Backend\View;

use NITSAN\NsT3AL\Domain\Repository\LogsRepository;
use NITSAN\NsT3AL\Utility\XliffUtility;
use TYPO3\CMS\Core\Localization\Locales;
use TYPO3\CMS\Core\Pagination\SimplePagination;
use TYPO3\CMS\Extbase\Pagination\QueryResultPaginator;
use TYPO3\CMS\Extensionmanager\Utility\ListUtility;

/**
 * Builds assign arrays for logs screens (presentation only).
 */
class LogsViewDataProvider
{
    public function __construct(
        protected LogsRepository $logsRepository,
        protected XliffUtility $xlfUtility,
        protected Locales $locales,
        protected ListUtility $listUtility
    ) {
    }

    public const ITEMS_PER_PAGE = 20;

    /**
     * @return array<string, mixed>
     */
    public function buildLogsData(
        string $extensionKey,
        string $selectedFile = '',
        string $targetLanguage = '',
        string $returnUrl = '',
        int $currentPage = 1
    ): array {
        $assign = [
            'returnUrl' => $returnUrl,
            'files' => [],
            'logRecords' => [],
            'filter' => [],
            'targetLanguages' => [],
            'title' => '',
            'pagination' => [],
        ];

        if ($extensionKey === '') {
            return $assign;
        }

        $extensionDetails = $this->listUtility->getExtension($extensionKey);
        $packagePath = $extensionDetails->getPackagePath();
        $metadata = $extensionDetails->getPackageMetaData();
        $files = $this->xlfUtility->getFileList($packagePath);

        $filter = [
            'sourceFile' => $selectedFile ?: ($files ? array_key_first($files) : ''),
            'extensionKey' => $extensionKey,
            'targetLanguage' => $targetLanguage,
        ];

        $assign['files'] = $files;
        $assign['title'] = $metadata->getTitle();
        $assign['filter'] = $filter;
        $assign['extkey'] = $extensionKey;

        $queryResult = $this->logsRepository->findRecords($filter);
        $paginator = new QueryResultPaginator($queryResult, max(1, $currentPage), self::ITEMS_PER_PAGE);
        $pagination = new SimplePagination($paginator);
        $logRecords = iterator_to_array($paginator->getPaginatedItems(), false);
        $assign['pagination'] = [
            'currentPage' => $paginator->getCurrentPageNumber(),
            'numberOfPages' => $paginator->getNumberOfPages(),
            'previousPage' => $pagination->getPreviousPageNumber(),
            'nextPage' => $pagination->getNextPageNumber(),
            'totalItems' => $queryResult->count(),
        ];
        $targetLanguages = $this->logsRepository->findTargetLanguage($filter);
        $allLanguagesList = $this->locales->getLanguages();
        $assign['targetLanguages'] = array_filter(
            $allLanguagesList,
            static function ($key) use ($targetLanguages) {
                return in_array($key, $targetLanguages, true);
            },
            ARRAY_FILTER_USE_KEY
        );
        $assign['logRecords'] = $logRecords;
        $assign['hasLogs'] = !empty($logRecords);

        return $assign;
    }
}
