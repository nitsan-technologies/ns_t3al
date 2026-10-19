<?php

declare(strict_types=1);

namespace NITSAN\NsT3AL\Controller;

use Doctrine\DBAL\Exception as DbalException;
use NITSAN\NsT3AL\Backend\View\ConcurrentEditNotice;
use NITSAN\NsT3AL\Backend\View\EditorViewDataProvider;
use NITSAN\NsT3AL\Backend\View\LogsViewDataProvider;
use NITSAN\NsT3AL\Domain\Repository\LogsRepository;
use NITSAN\NsT3AL\Service\AiProviderResolver;
use NITSAN\NsT3AL\Service\CrowdinService;
use NITSAN\NsT3AL\Service\ExtensionAccessService;
use NITSAN\NsT3AL\Service\LabelSearchService;
use NITSAN\NsT3AL\Service\LanguageArchiveBuilder;
use NITSAN\NsT3AL\Service\TranslationService;
use NITSAN\NsT3AL\Utility\CsvCellEscaper;
use NITSAN\NsT3AL\Utility\GridLabels;
use NITSAN\NsT3AL\Utility\XliffUtility;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use TYPO3\CMS\Backend\Template\ModuleTemplate;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;
use TYPO3\CMS\Core\Utility\PathUtility;
use TYPO3\CMS\Extbase\Http\ForwardResponse;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;
use TYPO3\CMS\Extbase\Utility\LocalizationUtility;

/**
 * Admin Tools module — translate_locallang-style editor, existing ns_t3al features.
 */
class ModuleController extends ActionController implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    protected array $extSettings = [];

    public function __construct(
        protected ModuleTemplateFactory $moduleTemplateFactory,
        protected PageRenderer $pageRenderer,
        ExtensionConfiguration $extensionConfiguration,
        protected EditorViewDataProvider $editorViewDataProvider,
        protected LogsViewDataProvider $logsViewDataProvider,
        protected TranslationService $translationService,
        protected XliffUtility $xlfUtility,
        protected LogsRepository $logsRepository,
        protected CrowdinService $crowdinService,
        protected ExtensionAccessService $extensionAccessService,
        protected LabelSearchService $labelSearchService,
        protected AiProviderResolver $aiProviderResolver,
        protected CacheManager $cacheManager,
        protected LanguageArchiveBuilder $languageArchiveBuilder
    ) {
        $this->extSettings = $extensionConfiguration->get('ns_t3al') ?? [];
    }

    public function initializeAction(): void
    {
        $this->pageRenderer->loadJavaScriptModule('@typo3/backend/element/spinner-element.js');
        $this->pageRenderer->loadJavaScriptModule('@nitsan/ns-t3al/module-functionality.js');
        $this->pageRenderer->loadJavaScriptModule('@nitsan/ns-t3al/module-ui.js');
        $this->pageRenderer->loadJavaScriptModule('@nitsan/ns-t3al/unsaved-changes.js');
        $this->pageRenderer->addCssFile('EXT:ns_t3al/Resources/Public/Css/module.css');
        $this->pageRenderer->addInlineLanguageLabelFile('EXT:ns_t3al/Resources/Private/Language/locallang.xlf');
        $this->pageRenderer->addInlineLanguageLabelFile('EXT:ns_t3al/Resources/Private/Language/locallang_mod.xlf');
    }

    /**
     * @param list<string> $langKeys
     * @param array<string, array<string, string>> $overrideLabels
     * @param array<string, string> $models
     */
    public function listAction(
        string $extensionKey = '',
        string $extkey = '',
        string $file = '',
        array $langKeys = [],
        bool $sort = false,
        array $overrideLabels = [],
        string $apiModel = '',
        array $models = [],
        string $projectId = '',
        string $extType = ''
    ): ResponseInterface {
        $extensionKey = $this->resolveEditorExtensionKey($extensionKey, $extkey);
        $moduleData = $this->editorViewDataProvider->getModuleData();
        $sessid = $this->getBackendUser()->getSession()->getIdentifier();
        $extType = $this->resolveExtType($extType, $moduleData);

        if ($extensionKey === '' && !empty($moduleData['extkey'])) {
            $available = $this->editorViewDataProvider->getAvailableExtensions($extType);
            if (isset($available[$moduleData['extkey']])) {
                $extensionKey = (string)$moduleData['extkey'];
                if ($file === '') {
                    $file = (string)($moduleData['file'] ?? '');
                }
                if ($langKeys === []) {
                    $langKeys = is_array($moduleData['langKeys'] ?? null) ? $moduleData['langKeys'] : [];
                }
            }
        }

        $projectId = (string)$this->fromParsedBody('projectId', $projectId);
        $apiModel = $this->resolveRequestedApiModel($apiModel, $moduleData);

        $langKeys = $this->normalizeLangKeys($langKeys);
        $extensions = $this->editorViewDataProvider->getAvailableExtensions($extType);
        $this->addNoEditableExtensionsNotice($extensions, $extType);
        $files = [];
        $labels = [];
        $disableSaveButton = false;
        $formChanged = false;
        $extensionType = '';

        if ($extensionKey !== '' && isset($extensions[$extensionKey])) {
            $files = $this->editorViewDataProvider->getFileList($extensionKey);
            $extensionType = $this->editorViewDataProvider->getPackageType($extensionKey);
            if ($file !== '' && !isset($files[$file])) {
                $file = '';
            }
            if ($file !== '') {
                $foreignLock = $this->editorViewDataProvider->checkAndAcquireEditorLock(
                    $extensionKey,
                    $file,
                    $sessid,
                    (string)($this->getBackendUser()->user['username'] ?? '')
                );
                if ($foreignLock !== null) {
                    $this->addFlashMessage(
                        ConcurrentEditNotice::message($foreignLock, $file, time(), $this->translateMod(...)),
                        $this->translateMod('concurrent_edit.title'),
                        ContextualFeedbackSeverity::WARNING
                    );
                }

                if ($overrideLabels !== []) {
                    $labels = GridLabels::normalizeLineBreaks($overrideLabels);
                    $this->replacePostedGridValues($labels);
                    // A grid handed back unchanged (e.g. Translate found nothing to fill) is not an unsaved edit.
                    $formChanged = GridLabels::differ($this->editorViewDataProvider->loadLabels($extensionKey, $file, $langKeys), $labels)
                        || $this->editorViewDataProvider->buildKeyRenames($this->postedArray('keys')) !== [];
                } else {
                    $labels = $this->editorViewDataProvider->loadLabels($extensionKey, $file, $langKeys);
                }
                $sort = $sort || (bool)$this->fromParsedBody('sortByKey', false);
                if ($sort) {
                    ksort($labels);
                    $formChanged = true;
                }

                $maxInputVars = (int)ini_get('max_input_vars');
                $fieldcount = (count($labels) + 1) * (count($langKeys) + 1) + count($langKeys) + 10;
                if ($fieldcount > $maxInputVars) {
                    $this->addFlashMessage(
                        sprintf($this->translateMod('max_input_vars.message'), (string)$fieldcount),
                        $this->translateMod('max_input_vars.title'),
                        ContextualFeedbackSeverity::WARNING
                    );
                    $disableSaveButton = true;
                }
            }
        } else {
            $extensionKey = '';
            $file = '';
            $labels = [];
        }

        $this->editorViewDataProvider->setModuleData([
            'extkey' => $extensionKey,
            'file' => $file,
            'langKeys' => $langKeys,
            'extType' => $extType,
            'apiModel' => $apiModel,
            'time' => time(),
            'sessid' => $sessid,
        ]);

        $languageOptions = $this->editorViewDataProvider->getLanguageOptions($extensionKey);
        unset($languageOptions['default']);
        $sourceEditable = (bool)$this->getBackendUser()->isAdmin() && $extensionType !== 'typo3-cms-framework';

        $assign = [
            'extkey' => $extensionKey,
            'file' => $file,
            'files' => $files,
            'langKeys' => $langKeys,
            'labels' => $labels,
            'extensions' => $extensions,
            'extType' => $extType,
            'allLanguagesList' => $languageOptions,
            'time' => time(),
            'extSettings' => $this->extSettings,
            'apiModelDefault' => $apiModel,
            'apiModelIs' => [
                'openai' => $apiModel === 'openai',
                'gemini' => $apiModel === 'gemini',
                'deepl' => $apiModel === 'deepl',
                'google' => $apiModel === 'google',
            ],
            'projectId' => $projectId,
            'models' => $models,
            'extensionType' => $extensionType,
            'saveUri' => $this->getExtbaseUri('save'),
            'translateUri' => $this->getExtbaseUri('translate'),
            'modifyKeys' => (bool)$this->getBackendUser()->isAdmin(),
            'sourceEditable' => $sourceEditable,
            'sortByKey' => $sort && $sourceEditable,
            'defaultLangKey' => 'en',
            'langKeysSelected' => array_fill_keys($langKeys, true),
            'disableSaveButton' => $disableSaveButton,
            'formChanged' => $formChanged,
            'reloadUri' => $this->getExtbaseUri('list', [
                'extensionKey' => $extensionKey,
                'file' => $file,
                'langKeys' => $langKeys,
                'extType' => $extType,
            ]),
            'exportZipUri' => $this->isZipExportAvailable($extensionType)
                ? $this->getExtbaseUri('export', ['extensionKey' => $extensionKey])
                : '',
        ];

        $moduleTemplate = $this->createModuleTemplate();
        $moduleTemplate->assignMultiple($assign);

        return $moduleTemplate->renderResponse('Module/List');
    }

    /**
     * @param list<string> $langKeys
     * @param array<string, string> $keys
     * @param array<string, array<string, string>> $labels
     * @param array<string, string> $models
     */
    public function translateAction(
        string $extensionKey = '',
        string $extkey = '',
        string $file = '',
        array $langKeys = [],
        array $keys = [],
        array $labels = [],
        string $apiModel = '',
        array $models = [],
        string $projectId = ''
    ): ResponseInterface {
        $extensionKey = $this->resolveEditorExtensionKey($extensionKey, $extkey);
        $langKeys = $this->normalizeLangKeys($langKeys);
        if ($labels === []) {
            $labels = $this->postedArray('labels');
        }
        $labels = GridLabels::normalizeLineBreaks($labels);
        if ($keys === []) {
            $keys = $this->postedArray('keys');
        }
        $apiModel = $this->resolveRequestedApiModel($apiModel);
        $this->persistApiModelSelection($apiModel);
        $models = $models !== [] ? $models : (array)$this->fromParsedBody('models', []);
        $forwardArgs = [
            'extensionKey' => $extensionKey,
            'file' => $file,
            'langKeys' => $langKeys,
            'apiModel' => $apiModel,
            'models' => $models,
            'projectId' => $projectId,
        ];

        if ($apiModel === '') {
            $this->addFlashMessage(
                $this->translate('setting.api_key.none_configured'),
                $this->translate('api_key.missing'),
                ContextualFeedbackSeverity::ERROR
            );
            return (new ForwardResponse('list'))->withArguments($forwardArgs);
        }

        $validation = $this->translationService->validate($apiModel);
        if (!$validation['status']) {
            $this->addFlashMessage(
                (string)($validation['message'] ?? $this->translate('setting.api_key.none_configured')),
                $this->translate('api_key.missing'),
                ContextualFeedbackSeverity::ERROR
            );
            return (new ForwardResponse('list'))->withArguments($forwardArgs);
        }

        if ($extensionKey === '' || $file === '') {
            $this->addFlashMessage(
                $this->translate('translation.file.missing'),
                $this->translate('translation.file.no_content'),
                ContextualFeedbackSeverity::WARNING
            );
            return (new ForwardResponse('list'))->withArguments($forwardArgs);
        }

        if (!$this->guardEditableTarget($extensionKey, $file)) {
            return (new ForwardResponse('list'))->withArguments($forwardArgs);
        }

        if ($labels === []) {
            $labels = $this->editorViewDataProvider->loadLabels($extensionKey, $file, $langKeys);
        } else {
            if (!$this->guardKeyChanges($extensionKey, $file, $labels, $keys)) {
                $forwardArgs['overrideLabels'] = $labels;
                return (new ForwardResponse('list'))->withArguments($forwardArgs);
            }
            $labels = $this->editorViewDataProvider->applyKeyChanges($labels, $keys);
        }

        $translatedAny = false;
        try {
            foreach ($langKeys as $iso) {
                if ($iso === 'default') {
                    continue;
                }
                $batch = [];
                foreach ($labels as $key => $row) {
                    $source = (string)($row['default'] ?? '');
                    $target = (string)($row[$iso] ?? '');
                    if ($source === '') {
                        continue;
                    }
                    // Always missing-only: skip cells that already have a translation.
                    if ($target !== '') {
                        continue;
                    }
                    $batch[$key] = ['source' => $source, 'target' => $target];
                }
                if ($batch === []) {
                    continue;
                }
                $responseData = $this->translationService->request($apiModel, $iso, $batch, $models);
                $isFailed = isset($responseData['status'])
                    && ($responseData['status'] === false || $responseData['status'] === 'false' || empty($responseData['content']));
                if ($isFailed) {
                    $this->addFlashMessage(
                        (string)($responseData['message'] ?? $this->translate('translation.translationGenerationError.title')),
                        (string)($responseData['title'] ?? $this->translate('translation.translationGenerationError.title')),
                        ContextualFeedbackSeverity::ERROR
                    );
                    $forwardArgs['overrideLabels'] = $labels;
                    return (new ForwardResponse('list'))->withArguments($forwardArgs);
                }
                foreach ((array)$responseData['content'] as $key => $unit) {
                    if (!isset($labels[$key])) {
                        continue;
                    }
                    $labels[$key][$iso] = (string)($unit['target'] ?? '');
                }
                if (!empty($responseData['message'])) {
                    $this->addFlashMessage(
                        '[' . $iso . '] ' . $responseData['message'],
                        (string)($responseData['title'] ?? $this->translate('translation.partialFailure.title')),
                        ContextualFeedbackSeverity::WARNING
                    );
                }
                $translatedAny = true;
            }
        } catch (\Throwable $exception) {
            $this->logger?->error(
                'AI translate failed',
                ['apiModel' => $apiModel] + $this->exceptionContext($exception, $extensionKey, $file, implode(',', $langKeys))
            );
            $this->addFlashMessage(
                $this->translate('translation.generation.error'),
                $this->translate('translation.translationGenerationError.title'),
                ContextualFeedbackSeverity::ERROR
            );
            $forwardArgs['overrideLabels'] = $labels;
            return (new ForwardResponse('list'))->withArguments($forwardArgs);
        }

        if (!$translatedAny) {
            $this->addFlashMessage(
                $this->translate('translation.noTranslationsNeeded.message'),
                $this->translate('translation.noTranslationsNeeded.title'),
                ContextualFeedbackSeverity::INFO
            );
        } else {
            $this->addFlashMessage(
                $this->translate('translation.fetchingDataSuccessful.message'),
                $this->translate('translation.fetchingDataSuccessful.title'),
                ContextualFeedbackSeverity::OK
            );
        }

        $forwardArgs['overrideLabels'] = $labels;
        return (new ForwardResponse('list'))->withArguments($forwardArgs);
    }

    /**
     * @param array<string, string> $keys
     * @param array<string, array<string, string>> $labels
     * @param list<string> $langKeys
     */
    public function saveAction(
        string $extensionKey = '',
        string $extkey = '',
        string $file = '',
        array $langKeys = [],
        array $keys = [],
        array $labels = [],
        string $fileLocation = 'local',
        string $projectId = '',
        string $extensionType = '',
        string $apiModel = '',
        array $models = []
    ): ResponseInterface {
        $extensionKey = $this->resolveEditorExtensionKey($extensionKey, $extkey);
        $langKeys = $this->normalizeLangKeys($langKeys);
        if ($labels === []) {
            $labels = $this->postedArray('labels');
        }
        $labels = GridLabels::normalizeLineBreaks($labels);
        if ($keys === []) {
            $keys = $this->postedArray('keys');
        }
        $fileLocation = (string)$this->fromParsedBody('fileLocation', $fileLocation);
        $projectId = (string)$this->fromParsedBody('projectId', $projectId);
        $apiModel = $this->resolveRequestedApiModel($apiModel);
        $this->persistApiModelSelection($apiModel);

        // Never trust extensionType from the request — resolve from the installed package.
        $extensionType = '';
        if ($extensionKey !== '') {
            try {
                $extensionType = $this->editorViewDataProvider->getPackageType($extensionKey);
            } catch (\Throwable $exception) {
                $extensionType = '';
            }
        }

        $forwardArgs = [
            'extensionKey' => $extensionKey,
            'file' => $file,
            'langKeys' => $langKeys,
            'apiModel' => $apiModel,
            'models' => $models,
            'projectId' => $projectId,
        ];

        if ($extensionKey === '' || $file === '') {
            $this->addFlashMessage(
                $this->translate('translation.file.missing'),
                $this->translate('translation.file.no_content'),
                ContextualFeedbackSeverity::WARNING
            );
            return (new ForwardResponse('list'))->withArguments($forwardArgs);
        }

        if (!$this->guardEditableTarget($extensionKey, $file)) {
            return (new ForwardResponse('list'))->withArguments($forwardArgs);
        }

        if ($extensionType === 'typo3-cms-framework' && !(bool)$this->getBackendUser()->isAdmin()) {
            $this->addFlashMessage(
                $this->translateMod('access.denied'),
                $this->translateMod('access.denied.title'),
                ContextualFeedbackSeverity::ERROR
            );
            return (new ForwardResponse('list'))->withArguments($forwardArgs);
        }

        // Crowdin files are resolved in the TYPO3 Core project layout (<crowdinSystemExtensionPath>/<ext>/…, default /main/typo3/sysext).
        if ($fileLocation === 'crowdin' && $extensionType !== 'typo3-cms-framework') {
            $this->addFlashMessage(
                $this->translate('translation.write.crowdin.error.system_extensions_only'),
                $this->translate('translation.write.crowdin.error.title'),
                ContextualFeedbackSeverity::ERROR
            );
            return (new ForwardResponse('list'))->withArguments($forwardArgs);
        }

        if ($fileLocation === 'crowdin' && trim($projectId) === '') {
            $this->addFlashMessage(
                $this->translate('projectId.missing_field_desc'),
                $this->translate('projectId.missing'),
                ContextualFeedbackSeverity::ERROR
            );
            return (new ForwardResponse('list'))->withArguments($forwardArgs);
        }

        if ($fileLocation === 'crowdin' && !$this->crowdinService->hasAccessToken()) {
            $this->addFlashMessage(
                $this->translate('translation.write.crowdin.error.token_missing'),
                $this->translate('translation.write.crowdin.error.title'),
                ContextualFeedbackSeverity::ERROR
            );
            return (new ForwardResponse('list'))->withArguments($forwardArgs);
        }

        $isAdmin = (bool)$this->getBackendUser()->isAdmin();
        // System extension sources live in the TYPO3 Core (vendor/typo3/cms-* or typo3/sysext) — never write them.
        $sourceEditable = $isAdmin && $extensionType !== 'typo3-cms-framework';
        $renames = [];
        if ($sourceEditable) {
            if (!$this->guardKeyChanges($extensionKey, $file, $labels, $keys)) {
                $forwardArgs['overrideLabels'] = $labels;
                return (new ForwardResponse('list'))->withArguments($forwardArgs);
            }
            $labels = $this->editorViewDataProvider->applyKeyChanges($labels, $keys);
            $renames = $this->editorViewDataProvider->buildKeyRenames($keys);
        } else {
            // Translation values only — ignore key renames/deletes/new keys and English source edits.
            $existingLabels = $this->editorViewDataProvider->loadLabels($extensionKey, $file, $langKeys);
            if ($isAdmin && $this->editorViewDataProvider->hasSourceChanges(
                $existingLabels,
                $this->editorViewDataProvider->applyKeyChanges($labels, $keys)
            )) {
                $this->addFlashMessage(
                    $this->translateMod('save.source_read_only'),
                    $this->translateMod('save.source_read_only.title'),
                    ContextualFeedbackSeverity::WARNING
                );
            }
            $safeLabels = [];
            foreach ($existingLabels as $key => $existingRow) {
                $posted = $labels[$key] ?? [];
                $row = $existingRow;
                foreach ($langKeys as $langKey) {
                    if ($langKey === 'default') {
                        continue;
                    }
                    if (is_array($posted) && array_key_exists($langKey, $posted)) {
                        $row[$langKey] = (string)$posted[$langKey];
                    }
                }
                $safeLabels[$key] = $row;
            }
            $labels = $safeLabels;
        }
        $sortByKey = $sourceEditable && (bool)$this->fromParsedBody('sortByKey', false);
        $keepGridOrder = $sourceEditable && !$sortByKey && $this->editorViewDataProvider->isGridOrderChanged(
            array_keys($this->editorViewDataProvider->loadLabels($extensionKey, $file, ['default'])),
            array_keys($labels),
            $renames
        );
        $writeSuccesses = 0;
        $writeFailures = 0;
        foreach ($langKeys as $langKey) {
            if ($langKey === 'default' && !$sourceEditable) {
                continue;
            }
            $result = $this->editorViewDataProvider->buildLanguageFile($extensionKey, $file, $langKey, $labels, $renames, $sortByKey, $keepGridOrder);
            if ($result === null) {
                $writeFailures++;
                continue;
            }
            if (!$result['changed']) {
                // Untouched files are not rewritten; Crowdin still receives the current file on request.
                if ($fileLocation === 'crowdin' && $langKey !== 'default' && $result['content'] !== '') {
                    $this->uploadToCrowdin($extensionKey, $file, $langKey, $result['content'], (int)$projectId, $extensionType);
                }
                continue;
            }
            if ($this->persistLanguageContent($extensionKey, $file, $langKey, $result['content'], $fileLocation, $projectId, $extensionType, 'Manual')) {
                $writeSuccesses++;
            } else {
                $writeFailures++;
            }
        }
        if ($writeSuccesses > 0) {
            $this->flushLabelCache();
        }

        // One flash for the overall local save — avoid repeating the same success once per langKey.
        if ($writeSuccesses === 0 && $writeFailures === 0) {
            $this->addFlashMessage(
                $this->translateMod('save.no_changes'),
                $this->translateMod('save.no_changes.title'),
                ContextualFeedbackSeverity::INFO
            );
        } elseif ($writeSuccesses > 0 && $writeFailures === 0) {
            $this->addFlashMessage(
                $this->translate('translation.write.local.success.message'),
                $this->translate('translation.write.local.success.title'),
                ContextualFeedbackSeverity::OK
            );
        } elseif ($writeFailures > 0 && $writeSuccesses === 0) {
            $this->addFlashMessage(
                $this->translate('translation.errorWritingTranslationFile.message'),
                $this->translate('translation.generation.error'),
                ContextualFeedbackSeverity::ERROR
            );
        } elseif ($writeFailures > 0 && $writeSuccesses > 0) {
            $this->addFlashMessage(
                $this->translate('translation.errorWritingTranslationFile.message'),
                $this->translate('translation.generation.error'),
                ContextualFeedbackSeverity::WARNING
            );
        }

        if ($writeFailures > 0) {
            // Keep the posted grid so unsaved edits are not lost.
            return (new ForwardResponse('list'))->withArguments($forwardArgs);
        }
        // Post/Redirect/Get: reloading the page must not save (and upload / log) again. The list then shows
        // what is on disk, so ignored edits (read-only source, non-admin key changes) do not look saved.
        return $this->redirect('list', null, null, $forwardArgs);
    }

    public function searchAction(string $word = '', string $searchLang = ''): ResponseInterface
    {
        $languageOptions = $this->getSearchLanguageOptions();
        if (!isset($languageOptions[$searchLang])) {
            $searchLang = '';
        }
        $search = $this->labelSearchService->search($word, $searchLang);

        $moduleTemplate = $this->createModuleTemplate();
        $moduleTemplate->assignMultiple([
            'word' => $word,
            'searchLang' => $searchLang,
            'languageOptions' => $languageOptions,
            'results' => $search['results'],
            'limited' => $search['limited'],
            'maxResults' => LabelSearchService::MAX_RESULTS,
        ]);

        return $moduleTemplate->renderResponse('Module/Search');
    }

    /**
     * @return array<string, string> '' = all languages, 'default' = English source files
     */
    protected function getSearchLanguageOptions(): array
    {
        $options = [
            '' => $this->translateMod('search_language_all'),
            'default' => $this->translateMod('search_language_default'),
        ];
        $languages = $this->editorViewDataProvider->getLanguageOptions();
        unset($languages['default'], $languages['en']);
        foreach ($languages as $langKey => $name) {
            $options[(string)$langKey] = $name . ' (' . $langKey . ')';
        }
        return $options;
    }

    public function createFileAction(string $extensionKey = '', string $extkey = '', string $newFile = ''): ResponseInterface
    {
        $extensionKey = $this->resolveEditorExtensionKey($extensionKey, $extkey);
        if (!$this->getBackendUser()->isAdmin()) {
            $this->addFlashMessage(
                $this->translateMod('access.denied'),
                $this->translateMod('access.denied.title'),
                ContextualFeedbackSeverity::ERROR
            );
            return (new ForwardResponse('list'))->withArguments(['extensionKey' => $extensionKey]);
        }
        if ($newFile === '') {
            $newFile = (string)$this->fromParsedBody('newFile', '');
        }
        $newFile = trim($newFile);
        if ($extensionKey === '' || $newFile === '') {
            return (new ForwardResponse('list'))->withArguments(['extensionKey' => $extensionKey]);
        }

        if ($this->editorViewDataProvider->isSourceReadOnly($extensionKey)) {
            $this->addFlashMessage(
                $this->translateMod('create_file.source_read_only'),
                $this->translateMod('create_file.error'),
                ContextualFeedbackSeverity::ERROR
            );
            return (new ForwardResponse('list'))->withArguments(['extensionKey' => $extensionKey]);
        }

        if (!XliffUtility::isValidXliffFileName($newFile)) {
            $this->addFlashMessage(
                $this->translateMod('create_file.illegal'),
                $this->translateMod('create_file.error'),
                ContextualFeedbackSeverity::ERROR
            );
            return (new ForwardResponse('list'))->withArguments(['extensionKey' => $extensionKey]);
        }

        if (!$this->editorViewDataProvider->createEmptyFile($extensionKey, $newFile)) {
            $this->addFlashMessage(
                $this->translateMod('create_file.exists'),
                $this->translateMod('create_file.error'),
                ContextualFeedbackSeverity::ERROR
            );
            return (new ForwardResponse('list'))->withArguments(['extensionKey' => $extensionKey]);
        }
        $this->flushLabelCache();

        return $this->redirect('list', null, null, [
            'extensionKey' => $extensionKey,
            'file' => $newFile,
            'langKeys' => ['default'],
        ]);
    }

    /**
     * @param list<string> $langKeys
     */
    public function importCsvAction(string $extensionKey = '', string $extkey = '', string $file = '', array $langKeys = []): ResponseInterface
    {
        $extensionKey = $this->resolveEditorExtensionKey($extensionKey, $extkey);
        $langKeys = $this->normalizeLangKeys($langKeys);
        $forward = [
            'extensionKey' => $extensionKey,
            'file' => $file,
            'langKeys' => $langKeys,
        ];

        if (!$this->getBackendUser()->isAdmin()) {
            $this->addFlashMessage(
                $this->translateMod('access.denied'),
                $this->translateMod('access.denied.title'),
                ContextualFeedbackSeverity::ERROR
            );
            return (new ForwardResponse('list'))->withArguments($forward);
        }

        if ($extensionKey === '' || $file === '' || !$this->guardEditableTarget($extensionKey, $file)) {
            return (new ForwardResponse('list'))->withArguments($forward);
        }

        $fp = $this->openUploadedFile('importFile');
        if ($fp === null) {
            $this->addFlashMessage(
                $this->translateMod('csv.no_file'),
                $this->translateMod('csv.error'),
                ContextualFeedbackSeverity::ERROR
            );
            return (new ForwardResponse('list'))->withArguments($forward);
        }
        if (fgets($fp, 4) !== "\xef\xbb\xbf") {
            rewind($fp);
        }
        $hrow = fgetcsv($fp, 0, ',', '"', '\\');
        if (!$hrow || ($hrow[0] ?? '') !== 'key' || count($hrow) < 2) {
            fclose($fp);
            $this->addFlashMessage(
                $this->translateMod('csv.invalid'),
                $this->translateMod('csv.error'),
                ContextualFeedbackSeverity::ERROR
            );
            return (new ForwardResponse('list'))->withArguments($forward);
        }

        $importedLangKeys = [];
        for ($i = 1; $i < count($hrow); $i++) {
            $header = trim((string)$hrow[$i]);
            $langKey = (str_contains($header, '(default)') || $header === 'default' || $header === 'en') ? 'default' : $header;
            $importedLangKeys[$i] = $langKey;
        }
        $labels = [];
        while ($row = fgetcsv($fp, 0, ',', '"', '\\')) {
            $key = CsvCellEscaper::unescape((string)($row[0] ?? ''));
            if ($key === '') {
                continue;
            }
            foreach ($importedLangKeys as $column => $langKey) {
                $labels[$key][$langKey] = isset($row[$column]) ? CsvCellEscaper::unescape((string)$row[$column]) : '';
            }
        }
        fclose($fp);

        // A CSV with only some keys or languages must not drop the others on the next Save.
        $gridLangKeys = $this->normalizeLangKeys(array_merge($langKeys, array_values($importedLangKeys)));
        $merge = $this->editorViewDataProvider->mergeImportedLabels(
            $this->editorViewDataProvider->loadLabels($extensionKey, $file, $gridLangKeys),
            $labels,
            $gridLangKeys
        );
        $this->addFlashMessage(
            sprintf($this->translateMod('csv.imported.message'), $merge['updated'], $merge['added']),
            $this->translateMod('csv.imported.title'),
            ContextualFeedbackSeverity::INFO
        );

        $forward['langKeys'] = $gridLangKeys;
        $forward['overrideLabels'] = $merge['labels'];
        return (new ForwardResponse('list'))->withArguments($forward);
    }

    /**
     * @param list<string> $langKeys
     * @param list<string|int> $files
     */
    public function exportCsvAction(
        string $extensionKey = '',
        string $extkey = '',
        string $file = '',
        string $filename = '',
        array $files = [],
        array $langKeys = [],
        string $targetLanguage = '',
        string $returnAction = 'list'
    ): ResponseInterface {
        $extensionKey = $this->resolveEditorExtensionKey($extensionKey, $extkey);
        $file = $file !== '' ? $file : $filename;
        $langKeys = $this->normalizeLangKeys($langKeys);
        if ($langKeys === ['default'] && $targetLanguage !== '') {
            $langKeys[] = $targetLanguage;
            $langKeys = $this->normalizeLangKeys($langKeys);
        }

        $forward = (new ForwardResponse('list'))->withArguments([
            'extensionKey' => $extensionKey,
            'file' => $file,
            'langKeys' => $langKeys,
        ]);

        if ($extensionKey === '' || $file === '') {
            $this->addFlashMessage(
                $this->translate('translation.file.missing'),
                $this->translate('translation.file.no_content'),
                ContextualFeedbackSeverity::WARNING
            );
            return $forward;
        }

        if (!$this->guardEditableTarget($extensionKey, $file)) {
            return $forward;
        }

        $labels = $this->editorViewDataProvider->loadLabels($extensionKey, $file, $langKeys);
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            return $forward;
        }
        fwrite($handle, "\xEF\xBB\xBF");
        $header = ['key'];
        foreach ($langKeys as $langKey) {
            $header[] = $langKey === 'default' ? 'en (default)' : $langKey;
        }
        fputcsv($handle, $header, ',', '"', '\\');
        $rowCount = 0;
        foreach ($labels as $key => $row) {
            $csvRow = [CsvCellEscaper::escape((string)$key)];
            foreach ($langKeys as $langKey) {
                $csvRow[] = CsvCellEscaper::escape((string)($row[$langKey] ?? ''));
            }
            fputcsv($handle, $csvRow, ',', '"', '\\');
            $rowCount++;
        }
        if ($rowCount === 0) {
            fclose($handle);
            $this->addFlashMessage(
                $this->translate('translation.file.missing'),
                $this->translate('translation.file.no_content'),
                ContextualFeedbackSeverity::WARNING
            );
            return $forward;
        }
        rewind($handle);
        $csv = stream_get_contents($handle) ?: '';
        fclose($handle);

        $safeName = preg_replace('/[^a-zA-Z0-9._-]+/', '_', $extensionKey . '-' . pathinfo($file, PATHINFO_FILENAME));
        $downloadName = (($safeName !== null && $safeName !== '') ? $safeName : 'translations') . '.csv';

        return $this->responseFactory->createResponse()
            ->withHeader('Content-Type', 'text/csv; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $downloadName . '"')
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('Pragma', 'no-cache')
            ->withBody($this->streamFactory->createStream($csv));
    }

    public function logsAction(
        string $extensionKey = '',
        string $extkey = '',
        string $extType = '',
        string $logFile = '',
        string $targetLanguage = '',
        int $currentPage = 1
    ): ResponseInterface {
        $extensionKey = $this->resolveEditorExtensionKey($extensionKey, $extkey);
        $moduleData = $this->editorViewDataProvider->getModuleData();
        $extType = $this->resolveExtType($extType, $moduleData);
        // The filter form posts plain field names; a changed filter starts again on page 1.
        $parsedBody = (array)($this->request->getParsedBody() ?? []);
        if (isset($parsedBody['logFile'])) {
            $logFile = (string)$parsedBody['logFile'];
            $targetLanguage = (string)($parsedBody['targetLanguage'] ?? '');
            $currentPage = 1;
        }
        $returnUrl = (string)$this->request->getAttribute('normalizedParams')?->getRequestUri();

        $extensions = $this->editorViewDataProvider->getAvailableExtensions($extType);
        $this->addNoEditableExtensionsNotice($extensions, $extType);
        if ($extensionKey !== '' && !isset($extensions[$extensionKey])) {
            $extensionKey = '';
        }

        $this->editorViewDataProvider->setModuleData(array_merge(
            $moduleData,
            [
                'extType' => $extType,
                'extkey' => $extensionKey !== '' ? $extensionKey : (string)($moduleData['extkey'] ?? ''),
                'time' => time(),
                'sessid' => $this->getBackendUser()->getSession()->getIdentifier(),
            ]
        ));

        $assign = $this->logsViewDataProvider->buildLogsData($extensionKey, $logFile, $targetLanguage, $returnUrl, $currentPage);
        $assign['extensions'] = $extensions;
        $assign['extType'] = $extType;

        if ($extensionKey !== '' && empty($assign['hasLogs'])) {
            $this->addFlashMessage(
                $this->translate('file.no_logs.description'),
                $this->translate('file.no_logs'),
                ContextualFeedbackSeverity::WARNING
            );
        }

        $moduleTemplate = $this->createModuleTemplate();
        $moduleTemplate->assignMultiple($assign);

        return $moduleTemplate->renderResponse('Module/Logs');
    }

    public function exportAction(string $extensionKey = ''): ResponseInterface
    {
        $extensionKey = $this->resolveExtensionKey($extensionKey);
        if ($extensionKey === '') {
            return new ForwardResponse('list');
        }
        // Only installed extensions the user may edit; never a path from the request.
        $extension = $this->editorViewDataProvider->getAvailableExtensions()[$extensionKey] ?? null;
        $packagePath = is_array($extension) ? (string)($extension['packagePath'] ?? '') : '';
        if ($packagePath === '') {
            $this->addFlashMessage(
                $this->translateMod('access.extension_denied'),
                $this->translateMod('access.denied.title'),
                ContextualFeedbackSeverity::ERROR
            );
            return $this->redirect('list');
        }
        if (!$this->isZipExportAvailable($this->editorViewDataProvider->getPackageType($extensionKey))) {
            $this->addFlashMessage(
                $this->translateMod('export.unavailable.message'),
                $this->translateMod('export.unavailable.title'),
                ContextualFeedbackSeverity::ERROR
            );
            return $this->redirect('list');
        }

        $archivePath = '';
        try {
            $archivePath = $this->languageArchiveBuilder->build(
                PathUtility::sanitizeTrailingSeparator($packagePath) . XliffUtility::LANGUAGE_DIR
            );
            // Read into memory so the archive can be deleted before the response is emitted.
            $body = $this->streamFactory->createStream((string)file_get_contents($archivePath));
            $downloadName = $extensionKey . '_Localization_' . date('YmdHi', (int)$GLOBALS['EXEC_TIME']) . '.zip';

            return $this->responseFactory
                ->createResponse()
                ->withHeader('Content-Type', 'application/zip')
                ->withHeader('Content-Length', (string)$body->getSize())
                ->withHeader('Content-Disposition', 'attachment; filename="' . $downloadName . '"')
                ->withBody($body);
        } catch (\Throwable $exception) {
            $this->logger?->error('Language ZIP export failed', [
                'extensionKey' => $extensionKey,
                'exception' => $exception::class . ': ' . $exception->getMessage(),
            ]);
            $this->addFlashMessage(
                $this->translateMod('export.failed.message'),
                $this->translateMod('export.failed.title'),
                ContextualFeedbackSeverity::ERROR
            );
            return $this->redirect('list');
        } finally {
            if ($archivePath !== '' && is_file($archivePath)) {
                unlink($archivePath);
            }
        }
    }

    /**
     * Export ZIP packs the extension's own Language folder; framework translations live in the labels path instead.
     */
    protected function isZipExportAvailable(string $packageType): bool
    {
        return !empty($this->extSettings['enableExport']) && $packageType !== 'typo3-cms-framework';
    }

    public function guideAction(): ResponseInterface
    {
        $demoUrl = $this->resolveGuideDemoUrl(
            (string)($this->request->getQueryParams()['demoUrl'] ?? '')
        );

        $moduleTemplate = $this->createModuleTemplate();
        $moduleTemplate->assignMultiple([
            'guideDemoUrl' => $demoUrl,
        ]);

        return $moduleTemplate->renderResponse('Module/Guide');
    }

    /**
     * Allow only known Supademo share/embed URLs. Anything else falls back to the default guide.
     * Share links use /demo/; iframes require /embed/.
     */
    protected function resolveGuideDemoUrl(string $url = ''): string
    {
        $default = 'https://app.supademo.com/embed/cm3q0nvwe1zwiahr7igu1j2w0';
        $url = trim($url);
        if ($url === '') {
            return $default;
        }

        if (preg_match(
            '#^https://app\.supademo\.com/(?:demo|embed)/([a-zA-Z0-9]+)/?$#i',
            $url,
            $matches
        ) !== 1) {
            return $default;
        }

        return 'https://app.supademo.com/embed/' . $matches[1];
    }

    /**
     * The extension must be granted to the user and $file must be an existing base XLF
     * (new base files are created only through the admin-only createFile action).
     * Adds an error flash and returns false otherwise.
     */
    protected function guardEditableTarget(string $extensionKey, string $file): bool
    {
        if (!$this->extensionAccessService->canEdit($extensionKey)) {
            $this->addFlashMessage(
                $this->translateMod('access.extension_denied'),
                $this->translateMod('access.denied.title'),
                ContextualFeedbackSeverity::ERROR
            );
            return false;
        }
        if (!$this->editorViewDataProvider->isExistingLanguageFile($extensionKey, $file)) {
            $this->addFlashMessage(
                $this->translateMod('save.file_not_found'),
                $this->translateMod('save.file_not_found.title'),
                ContextualFeedbackSeverity::ERROR
            );
            return false;
        }
        return true;
    }

    /**
     * Empty state for non-admins whose groups have not been granted any extension yet.
     *
     * @param array<string, mixed> $extensions
     */
    protected function addNoEditableExtensionsNotice(array $extensions, string $extType): void
    {
        if ($extensions !== [] || (bool)$this->getBackendUser()->isAdmin()) {
            return;
        }
        if ($extType !== 'All' && $this->editorViewDataProvider->getAvailableExtensions('All') !== []) {
            return;
        }
        $this->addFlashMessage(
            $this->translateMod('access.no_extensions'),
            $this->translateMod('access.no_extensions.title'),
            ContextualFeedbackSeverity::INFO
        );
    }

    /**
     * Write one language XLF (and optional Crowdin upload / log row).
     * Local success/error flashes are owned by saveAction so multi-language saves
     * do not repeat the same "Translation Success" message once per langKey.
     */
    protected function persistLanguageContent(
        string $extensionKey,
        string $file,
        string $langKey,
        string $content,
        string $fileLocation,
        string $projectId,
        string $extensionType,
        string $requestMode
    ): bool {
        $basePath = '';
        if ($extensionType === 'typo3-cms-framework' && $langKey !== 'default') {
            $basePath = Environment::getLabelsPath() . '/' . $langKey . '/' . $extensionKey . '/';
        }

        $logContent = '';
        if (($this->extSettings['enableLogs'] ?? '0') === '1') {
            try {
                $logContent = $this->xlfUtility->computeDiff($content, $extensionKey, $file, $langKey, $basePath);
            } catch (\Throwable $exception) {
                $this->logger?->warning('Computing the log diff failed', $this->exceptionContext($exception, $extensionKey, $file, $langKey));
            }
        }

        try {
            $status = $this->xlfUtility->writeFile($extensionKey, $content, $file, $langKey, $basePath);
        } catch (\Throwable $exception) {
            $this->logger?->error('Writing the language file failed', $this->exceptionContext($exception, $extensionKey, $file, $langKey));
            $status = false;
        }

        if ($status && $fileLocation === 'crowdin' && $langKey !== 'default') {
            $this->uploadToCrowdin($extensionKey, $file, $langKey, $content, (int)$projectId, $extensionType);
        }

        if (($this->extSettings['enableLogs'] ?? '0') === '1') {
            try {
                $this->logsRepository->insertRecord([
                    'source_file' => $file,
                    'output_file' => $langKey === 'default' ? $file : $langKey . '.' . $file,
                    'extension_name' => $extensionKey,
                    'status' => $status ? 1 : 0,
                    'crdate' => time(),
                    'request_mode' => $requestMode,
                    'target_language' => $langKey,
                    'content' => ($status && $logContent) ? $logContent : '',
                ]);
            } catch (DbalException|\Throwable $exception) {
                $this->logger?->error('Writing the translation log failed', $this->exceptionContext($exception, $extensionKey, $file, $langKey));
                $this->addFlashMessage(
                    $this->translate('logs.insert.error.message') ?: $exception->getMessage(),
                    $this->translate('logs.insert.error.title') ?: 'Log entry could not be saved',
                    ContextualFeedbackSeverity::ERROR
                );
            }
            try {
                $this->logsRepository->deleteExpired(
                    (int)($this->extSettings['logRetentionDays'] ?? LogsRepository::DEFAULT_RETENTION_DAYS)
                );
            } catch (DbalException|\Throwable $exception) {
                $this->logger?->warning('Deleting expired translation logs failed', $this->exceptionContext($exception, $extensionKey, $file, $langKey));
            }
        }

        return $status;
    }

    protected function uploadToCrowdin(
        string $extensionKey,
        string $fileName,
        string $targetLanguage,
        string $content,
        int $projectId,
        string $extensionType
    ): void {
        // Runs after the local file was written: an upload problem must never abort the save.
        try {
            if ($extensionType === 'typo3-cms-framework' && $projectId > 0) {
                $data = $this->crowdinService->uploadTranslation($projectId, $extensionKey, $fileName, $targetLanguage, $content);
                $this->addFlashMessage(
                    (string)($data['message'] ?? ''),
                    (string)($data['title'] ?? ''),
                    !empty($data['status']) ? ContextualFeedbackSeverity::OK : ContextualFeedbackSeverity::ERROR
                );
                return;
            }
            $message = $this->translate('translation.write.crowdin.error.message.no_project');
        } catch (\Throwable $exception) {
            $this->logger?->error('Crowdin upload failed', $this->exceptionContext($exception, $extensionKey, $fileName, $targetLanguage));
            $message = $this->translate('translation.generation.error');
        }

        $this->addFlashMessage(
            $message,
            $this->translate('translation.write.crowdin.error.title'),
            ContextualFeedbackSeverity::ERROR
        );
    }

    /**
     * Flashes duplicate and malformed keys; nothing may be written then (a duplicate would overwrite another label).
     *
     * @param array<string|int, array<string, string>> $labels
     * @param array<string|int, string> $keys
     */
    protected function guardKeyChanges(string $extensionKey, string $file, array $labels, array $keys): bool
    {
        $problems = $this->editorViewDataProvider->findKeyProblems(
            $labels,
            $keys,
            array_keys($this->editorViewDataProvider->loadLabels($extensionKey, $file, ['default']))
        );
        if ($problems['duplicates'] !== []) {
            $this->addFlashMessage(
                sprintf($this->translateMod('save.duplicate_keys.message'), implode(', ', $problems['duplicates'])),
                $this->translateMod('save.key_error.title'),
                ContextualFeedbackSeverity::ERROR
            );
        }
        if ($problems['invalid'] !== []) {
            $this->addFlashMessage(
                sprintf($this->translateMod('save.invalid_keys.message'), implode(', ', $problems['invalid'])),
                $this->translateMod('save.key_error.title'),
                ContextualFeedbackSeverity::ERROR
            );
        }
        return $problems['duplicates'] === [] && $problems['invalid'] === [];
    }

    /**
     * The core l10n cache key ignores file modification times, so written labels stay stale until it is flushed.
     */
    protected function flushLabelCache(): void
    {
        try {
            $this->cacheManager->getCache('l10n')->flush();
        } catch (\Throwable $exception) {
            $this->logger?->warning('Flushing the l10n cache failed', [
                'exception' => $exception::class . ': ' . $exception->getMessage(),
            ]);
        }
    }

    /**
     * @return array<string, string>
     */
    protected function exceptionContext(\Throwable $exception, string $extensionKey, string $file, string $langKey): array
    {
        return [
            'extensionKey' => $extensionKey,
            'file' => $file,
            'langKey' => $langKey,
            'exception' => $exception::class . ': ' . $exception->getMessage(),
        ];
    }

    /**
     * @param list<string> $langKeys
     * @return list<string>
     */
    protected function normalizeLangKeys(array $langKeys): array
    {
        $normalized = [];
        foreach ($langKeys as $langKey) {
            $langKey = trim((string)$langKey);
            // "en" is the English source ("default"), never a translation target.
            if ($langKey === '' || $langKey === '0' || $langKey === 'en' || !XliffUtility::isValidLanguageCode($langKey)) {
                continue;
            }
            $normalized[$langKey] = $langKey;
        }
        if (!isset($normalized['default'])) {
            $normalized = ['default' => 'default'] + $normalized;
        }
        return array_values($normalized);
    }

    /**
     * @return array<string, mixed>
     */
    protected function postedArray(string $name): array
    {
        $value = $this->fromParsedBody($name, []);
        return is_array($value) ? $value : [];
    }

    /**
     * ForwardResponse keeps the submitted POST as originalRequest, and Fluid form fields prefer those
     * posted values over assigned ones — replace them so the grid shows the given labels.
     *
     * @param array<string, array<string, string>> $labels
     */
    protected function replacePostedGridValues(array $labels): void
    {
        $extbaseParams = $this->request->getAttribute('extbase');
        if ($extbaseParams === null || $extbaseParams->getOriginalRequest() === null) {
            return;
        }
        $originalRequest = $extbaseParams->getOriginalRequest();
        $origArgs = $originalRequest->getArguments();
        $origArgs['labels'] = $labels;
        $extbaseParams->setOriginalRequest($originalRequest->withArguments($origArgs));
    }

    /**
     * Copies the upload into php://temp (released on fclose), so the import leaves no temporary file behind.
     *
     * @return resource|null Readable stream at position 0; null when nothing usable was uploaded
     */
    protected function openUploadedFile(string $name)
    {
        $uploaded = $this->getUploadedFile($name);
        if ($uploaded === null || $uploaded->getError() !== UPLOAD_ERR_OK) {
            return null;
        }
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            return null;
        }
        try {
            fwrite($handle, (string)$uploaded->getStream());
        } catch (\RuntimeException $exception) {
            fclose($handle);
            return null;
        }
        rewind($handle);
        return $handle;
    }

    protected function getUploadedFile(string $name): ?UploadedFileInterface
    {
        $files = $this->request->getUploadedFiles();
        if (($files[$name] ?? null) instanceof UploadedFileInterface) {
            return $files[$name];
        }
        foreach ($files as $group) {
            if (is_array($group) && ($group[$name] ?? null) instanceof UploadedFileInterface) {
                return $group[$name];
            }
        }
        return null;
    }

    /**
     * Read a field from the plugin namespace or the raw parsed body (AI provider radios in Partials/Module/Toolbar.html).
     *
     * @param mixed $default
     * @return mixed
     */
    protected function fromParsedBody(string $name, $default = '')
    {
        $body = (array)($this->request->getParsedBody() ?? []);
        if (array_key_exists($name, $body)) {
            return $body[$name];
        }
        foreach ($body as $value) {
            if (is_array($value) && array_key_exists($name, $value)) {
                return $value[$name];
            }
        }
        return $default;
    }

    /**
     * @param array<string, mixed> $moduleData
     */
    protected function resolveExtType(string $extType, array $moduleData = []): string
    {
        if ($extType === '') {
            $extType = (string)$this->fromParsedBody('extType', '');
        }
        if ($extType === '') {
            $query = $this->request->getQueryParams();
            $extType = (string)($query['extType'] ?? '');
        }
        if ($extType === '' && !empty($moduleData['extType'])) {
            $extType = (string)$moduleData['extType'];
        }
        if ($extType === '') {
            return 'Local';
        }
        return $this->editorViewDataProvider->normalizeExtType($extType);
    }

    protected function resolveEditorExtensionKey(string $extensionKey, string $extkey): string
    {
        if ($extensionKey !== '') {
            return $extensionKey;
        }
        if ($extkey !== '' && $extkey !== '0') {
            return $extkey;
        }
        return $this->resolveExtensionKey('');
    }

    protected function resolveExtensionKey(string $extensionKey): string
    {
        if ($extensionKey !== '') {
            return $extensionKey;
        }
        $query = $this->request->getQueryParams();
        $parsed = (array)($this->request->getParsedBody() ?? []);
        return (string)($query['extensionKey']
            ?? $parsed['extensionKey']
            ?? $parsed['extkey']
            ?? '');
    }

    /**
     * Resolve the AI provider the user actually selected: raw POST (radios / hidden) over Extbase args,
     * then module data. A known provider is kept even without a key so its warning can be shown — never
     * silently switch to another provider. With nothing requested the first configured provider is used.
     *
     * @param array<string, mixed>|null $moduleData
     * @return string openai|gemini|deepl|google or '' when none requested and none configured
     */
    protected function resolveRequestedApiModel(string $argumentApiModel = '', ?array $moduleData = null): string
    {
        $moduleData ??= $this->editorViewDataProvider->getModuleData();
        $fromBody = (string)$this->fromParsedBody('apiModel', '');
        if ($fromBody === '') {
            $fromBody = (string)$this->fromParsedBody('selectedApiModel', '');
        }
        $requested = $fromBody !== '' ? $fromBody : $argumentApiModel;
        if ($requested === '') {
            $requested = (string)($moduleData['apiModel'] ?? '');
        }
        return $this->aiProviderResolver->resolve($requested);
    }

    protected function persistApiModelSelection(string $apiModel): void
    {
        if ($apiModel === '') {
            return;
        }
        $moduleData = $this->editorViewDataProvider->getModuleData();
        if (($moduleData['apiModel'] ?? '') === $apiModel) {
            return;
        }
        $this->editorViewDataProvider->setModuleData(array_merge($moduleData, [
            'apiModel' => $apiModel,
            'time' => time(),
        ]));
    }

    protected function createModuleTemplate(): ModuleTemplate
    {
        $moduleTemplate = $this->moduleTemplateFactory->create($this->request);
        $moduleTemplate->setModuleClass('ns-t3al-module-shell');
        $moduleTemplate->getDocHeaderComponent()->disable();
        $moduleTemplate->assignMultiple([
            'moduleTabs' => $this->buildModuleTabs(),
            'guideDemoUrl' => $this->resolveGuideDemoUrl(),
        ]);
        return $moduleTemplate;
    }

    protected function getExtbaseUri(string $action, array $arguments = []): string
    {
        return $this->uriBuilder->reset()->uriFor($action, $arguments, 'Module');
    }

    /**
     * @return list<array{title: string, href: string, active: int}>
     */
    protected function buildModuleTabs(): array
    {
        $extensionKey = $this->resolveExtensionKey('');
        $args = $extensionKey !== '' ? ['extensionKey' => $extensionKey] : [];
        $current = $this->request->getControllerActionName();

        $menuItems = [
            'list' => ['label' => 'actionmenu.list', 'fallback' => 'Translate'],
            'search' => ['label' => 'actionmenu.search', 'fallback' => 'Search'],
            'logs' => ['label' => 'actionmenu.logs', 'fallback' => 'Logs'],
        ];

        $tabs = [];
        foreach ($menuItems as $action => $meta) {
            $uri = $this->uriBuilder->reset()->uriFor(
                $action,
                $action === 'search' ? [] : $args,
                'Module'
            );
            $isActive = $current === $action
                || (in_array($current, ['translate', 'save', 'createFile', 'importCsv', 'exportCsv'], true)
                    && $action === 'list');
            $title = $this->translateMod($meta['label']);
            if ($title === '' || str_starts_with($title, 'LLL:')) {
                $translated = LocalizationUtility::translate($meta['label'], 'NsT3AL');
                $title = is_string($translated) && $translated !== '' ? $translated : $meta['fallback'];
            }
            $tabs[] = [
                'title' => $title,
                'href' => $uri,
                'active' => $isActive ? 1 : 0,
            ];
        }

        return $tabs;
    }

    protected function translate(string $id): string
    {
        return $this->getLanguageService()->sL('LLL:EXT:ns_t3al/Resources/Private/Language/locallang.xlf:' . $id);
    }

    protected function translateMod(string $id): string
    {
        return $this->getLanguageService()->sL('LLL:EXT:ns_t3al/Resources/Private/Language/locallang_mod.xlf:' . $id);
    }

    protected function getLanguageService(): LanguageService
    {
        return $GLOBALS['LANG'];
    }

    protected function getBackendUser(): BackendUserAuthentication
    {
        return $GLOBALS['BE_USER'];
    }
}
