<?php

declare(strict_types=1);

namespace GeorgRinger\SfeventmgtRedirectSlugChange\Hooks;

use GeorgRinger\SfeventmgtRedirectSlugChange\Service\SlugService;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Messaging\FlashMessage;
use TYPO3\CMS\Core\Messaging\FlashMessageService;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\MathUtility;

class DataHandlerSlugUpdateHook
{
    protected SlugService $slugService;

    /** @var string[] */
    protected array $persistedSlugValues;

    public function __construct(SlugService $slugService)
    {
        $this->slugService = $slugService;
    }

    /**
     * Collects slugs of persisted records before having been updated.
     *
     * @param string|int $id (id could be string, for this reason no type hint)
     */
    public function processDatamap_preProcessFieldArray(array $incomingFieldArray, string $table, $id, DataHandler $dataHandler): void
    {
        if (
            $table !== 'tx_sfeventmgt_domain_model_event'
            || empty($incomingFieldArray['slug'])
            || !MathUtility::canBeInterpretedAsInteger($id)
            || !$dataHandler->checkRecordUpdateAccess($table, $id, $incomingFieldArray)
        ) {
            return;
        }

        $record = BackendUtility::getRecordWSOL($table, (int) $id, 'slug');
        $this->persistedSlugValues[(int) $id] = $record['slug'];
    }

    /**
     * Acts on potential slug changes.
     *
     * Hook `processDatamap_postProcessFieldArray` is executed after `DataHandler::fillInFields` which
     * ensure access to pages.slug field and applies possible evaluations (`eval => 'trim,...`).
     */
    public function processDatamap_postProcessFieldArray(string $status, string $table, $id, array $fieldArray, DataHandler $dataHandler): void
    {
        $persistedSlugValue = $this->persistedSlugValues[(int) $id] ?? null;
        if (
            $table !== 'tx_sfeventmgt_domain_model_event'
            || $status !== 'update'
            || empty($fieldArray['slug'])
            || $persistedSlugValue === null
            || $persistedSlugValue === $fieldArray['slug']
        ) {
            return;
        }

        $redirectIds = $this->slugService->rebuildSlugsForSlugChange($id, $persistedSlugValue, $fieldArray['slug'], $dataHandler->getCorrelationId());
        if ($redirectIds) {
            $this->addMessage($redirectIds);
        }
    }

    /**
     * @param int[] $recordIds
     */
    protected function addMessage(array $recordIds): void
    {
        $message = sprintf($GLOBALS['LANG']->sL('LLL:EXT:news_redirect_slug_change/Resources/Private/Language/de.locallang.xlf:notification.success'), implode(', ', $recordIds));
        $flashMessage = GeneralUtility::makeInstance(FlashMessage::class, $message, '', ContextualFeedbackSeverity::INFO, true);
        $flashMessageService = GeneralUtility::makeInstance(FlashMessageService::class);
        $defaultFlashMessageQueue = $flashMessageService->getMessageQueueByIdentifier();
        $defaultFlashMessageQueue->enqueue($flashMessage);
    }
}
