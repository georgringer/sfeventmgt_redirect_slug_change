<?php

declare(strict_types=1);

namespace GeorgRinger\SfeventmgtRedirectSlugChange\Service;

use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\DateTimeAspect;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\DataHandling\History\RecordHistoryStore;
use TYPO3\CMS\Core\DataHandling\Model\CorrelationId;
use TYPO3\CMS\Core\Domain\Repository\PageRepository;
use TYPO3\CMS\Core\LinkHandling\LinkService;
use TYPO3\CMS\Core\Site\Entity\SiteInterface;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Utility\DebuggerUtility;
use TYPO3\CMS\Redirects\Service\RedirectCacheService;

class SlugService implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    /** @var CorrelationId|string */
    protected $correlationIdRedirectCreation = '';

    /** @var CorrelationId|string */
    protected $correlationIdSlugUpdate = '';

    protected Context $context;
    protected SiteInterface $site;
    protected SiteFinder $siteFinder;
    protected PageRepository $pageRepository;
    protected LinkService $linkService;
    protected bool $autoCreateRedirects;
    protected int $redirectTTL;
    protected int $httpStatusCode;
    protected int $targetDetailPageId;
    protected int $targetRegistrationPageId;
    protected RedirectCacheService $redirectCacheService;

    public function __construct(
        Context $context,
        SiteFinder $siteFinder,
        PageRepository $pageRepository,
        LinkService $linkService,
        RedirectCacheService $redirectCacheService
    ) {
        $this->context = $context;
        $this->siteFinder = $siteFinder;
        $this->pageRepository = $pageRepository;
        $this->linkService = $linkService;
        $this->redirectCacheService = $redirectCacheService;
    }

    public function rebuildSlugsForSlugChange(int $recordId, string $currentSlug, string $newSlug, CorrelationId $correlationId): array
    {
        $redirectIds = [];
        $currentRecord = BackendUtility::getRecord('tx_sfeventmgt_domain_model_event', $recordId);
        if ($currentRecord === null) {
            return $redirectIds;
        }
        $pageId = $currentRecord['pid'];
        $this->initializeSettings($pageId);
        if ($this->autoCreateRedirects && $this->targetDetailPageId) {
            $this->createCorrelationIds($recordId, $correlationId);
            $redirectIds = $this->createRedirect($currentSlug, $recordId, (int) $currentRecord['sys_language_uid'], $pageId);
        }
        return $redirectIds;
    }

    /**
     * @return array redirect record
     */
    protected function createRedirect(string $originalSlug, int $recordId, int $languageId, int $pid): array
    {
        $redirectIds = [];
        $rebuildHosts = [];

        // detail
        $targetLink = $this->linkService->asString([
            'type' => 'page',
            'pageuid' => $this->targetDetailPageId,
            'parameters' => '_language=' . $languageId . '&tx_sfeventmgt_pieventdetail[controller]=Event&tx_sfeventmgt_pieventdetail[action]=detail&tx_sfeventmgt_pieventdetail[event]=' . $recordId,
        ]);
        $siteLanguage = $this->site->getLanguageById($languageId);

        $sourcePath = $this->generateDetailUrl($this->site, $recordId, $this->targetDetailPageId, $languageId);
        $sourcePath = '/' . ltrim(str_replace(['http://', 'https://', $siteLanguage->getBase()->getHost()], '', $sourcePath), '/');

        //        DebuggerUtility::var_dump([
        //            'source_path' => $sourcePath,
        //            'target_path' => $targetLink,
        //            'origalslug' => $originalSlug
        //        ]);die;
        $row = $this->persistRedirect($siteLanguage, $sourcePath, $targetLink);
        $redirectIds[] = $row['uid'];
        $rebuildHosts[] = $row['source_host'] ?: '*';

        // registration
        if ($this->targetRegistrationPageId) {
            $targetLink = $this->linkService->asString([
                'type' => 'page',
                'pageuid' => $this->targetRegistrationPageId,
                'parameters' => '_language=' . $languageId . '&tx_sfeventmgt_pieventregistration[controller]=Event&tx_sfeventmgt_pieventregistration[action]=registration&tx_sfeventmgt_pieventregistration[event]=' . $recordId,
            ]);
            $siteLanguage = $this->site->getLanguageById($languageId);

            $sourcePath = $this->generateRegistrationUrl($this->site, $recordId, $this->targetRegistrationPageId, $languageId);
            $sourcePath = '/' . ltrim(str_replace(['http://', 'https://', $siteLanguage->getBase()->getHost()], '', $sourcePath), '/');

            $row = $this->persistRedirect($siteLanguage, $sourcePath, $targetLink);
            $redirectIds[] = $row['uid'];
            $rebuildHosts[] = $row['source_host'] ?: '*';
        }

        $hosts = array_unique($rebuildHosts);
        foreach ($hosts as $host) {
            $this->redirectCacheService->rebuildForHost($host);
        }

        return $redirectIds;
    }

    protected function getRecordHistoryStore(): RecordHistoryStore
    {
        $backendUser = $this->getBackendUser();
        return GeneralUtility::makeInstance(
            RecordHistoryStore::class,
            RecordHistoryStore::USER_BACKEND,
            (int) $backendUser->user['uid'],
            (int) $backendUser->getOriginalUserIdWhenInSwitchUserMode(),
            $this->context->getPropertyFromAspect('date', 'timestamp'),
            $backendUser->workspace
        );
    }

    protected function getBackendUser(): BackendUserAuthentication
    {
        return $GLOBALS['BE_USER'];
    }

    protected function generateDetailUrl(SiteInterface $site, int $recordId, int $detailPageId, int $languageId): string
    {
        $additionalQueryParams = [
            'tx_sfeventmgt_pieventdetail' => [
                'action' => 'detail',
                'controller' => 'Event',
                'event' => $recordId,
            ],
            '_language' => $languageId,
        ];
        return (string) $site->getRouter()->generateUri(
            (string) $detailPageId,
            $additionalQueryParams
        );
    }

    protected function generateRegistrationUrl(SiteInterface $site, int $recordId, int $detailPageId, int $languageId): string
    {
        $additionalQueryParams = [
            'tx_sfeventmgt_pieventregistration' => [
                'action' => 'registration',
                'controller' => 'Event',
                'event' => $recordId,
            ],
            '_language' => $languageId,
        ];
        return (string) $site->getRouter()->generateUri(
            (string) $detailPageId,
            $additionalQueryParams
        );
    }

    protected function initializeSettings(int $pageId): void
    {
        $this->site = $this->siteFinder->getSiteByPageId($pageId);
        $settings = $this->site->getConfiguration()['redirectsSfEventMgmt'] ?? [];
        $this->autoCreateRedirects = (bool) ($settings['autoCreateRedirects'] ?? true);
        if (!$this->context->getPropertyFromAspect('workspace', 'isLive')) {
            $this->autoCreateRedirects = false;
        }
        $this->redirectTTL = (int) ($settings['redirectTTL'] ?? 0);
        $this->httpStatusCode = (int) ($settings['httpStatusCode'] ?? 307);
        $this->targetDetailPageId = (int) ($settings['detailPageId'] ?? 0);
        $this->targetRegistrationPageId = (int) ($settings['registrationPageId'] ?? 0);
    }

    protected function createCorrelationIds(int $newsId, CorrelationId $correlationId): void
    {
        if ($correlationId->getSubject() === null) {
            $subject = md5('event:' . $newsId);
            $correlationId = $correlationId->withSubject($subject);
        }

        $this->correlationIdRedirectCreation = $correlationId->withAspects(\TYPO3\CMS\Redirects\Service\SlugService::CORRELATION_ID_IDENTIFIER, 'redirect');
        $this->correlationIdSlugUpdate = $correlationId->withAspects(\TYPO3\CMS\Redirects\Service\SlugService::CORRELATION_ID_IDENTIFIER, 'path_segment');
    }

    private function persistRedirect(SiteLanguage $siteLanguage, string $sourcePath, string $targetLink): array
    {
        /** @var DateTimeAspect $date */
        $date = $this->context->getAspect('date');
        $endtime = $date->getDateTime()->modify('+' . $this->redirectTTL . ' days');

        $record = [
            'pid' => $this->site->getRootPageId(),
            'updatedon' => $date->get('timestamp'),
            'createdon' => $date->get('timestamp'),
            'deleted' => 0,
            'disabled' => 0,
            'starttime' => 0,
            'endtime' => $this->redirectTTL > 0 ? $endtime->getTimestamp() : 0,
            'source_host' => $siteLanguage->getBase()->getHost() ?: '*',
            'source_path' => $sourcePath,
            'is_regexp' => 0,
            'force_https' => 0,
            'respect_query_parameters' => 0,
            'target' => $targetLink,
            'target_statuscode' => $this->httpStatusCode,
            'hitcount' => 0,
            'lasthiton' => 0,
            'disable_hitcount' => 0,
            'creation_type' => 6322,
        ];

        $connection = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('sys_redirect');
        $connection->insert('sys_redirect', $record);
        $id = (int) $connection->lastInsertId();
        $record['uid'] = $id;
        $this->getRecordHistoryStore()->addRecord('sys_redirect', $id, $record, $this->correlationIdRedirectCreation);

        return (array) BackendUtility::getRecord('sys_redirect', $id);
    }
}
