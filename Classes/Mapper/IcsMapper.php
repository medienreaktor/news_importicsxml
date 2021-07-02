<?php

namespace GeorgRinger\NewsImporticsxml\Mapper;

/**
 * This file is part of the "news_importicsxml" Extension for TYPO3 CMS.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 */

use GeorgRinger\NewsImporticsxml\Domain\Model\Dto\TaskConfiguration;
use ICal\ICal;
use RuntimeException;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class IcsMapper extends AbstractMapper implements MapperInterface
{

    /** @var bool */
    protected $pathIsModified = false;

    /**
     * @param TaskConfiguration $configuration
     * @return array
     */
    public function map(TaskConfiguration $configuration)
    {
        if ($configuration->getCleanBeforeImport()) {
            $this->removeImportedRecordsFromPid($configuration->getPid(), $this->getImportSource());
        }
        if ($configuration->getHideBeforeImport()) {
            $this->hideImportedRecordsFromPid($configuration->getPid(), $this->getImportSource());
        }

        $data = [];
        $path = $this->getFileContent($configuration);

        $idCount = [];

        require_once(ExtensionManagementUtility::extPath('news_importicsxml') . 'Resources/Private/Contrib/Ical.php');
        $iCalService = new ICal($path);
        $events = $iCalService->events();

        foreach ($events as $event) {
            if (!isset($idCount[$event->uid])) {
                $idCount[$event->uid] = 1;
            } else {
                $idCount[$event->uid]++;
            }
            $datetime = $iCalService->iCalDateToUnixTimestamp($event->dtstart);
            if ($datetime === false) {
                $datetime = $iCalService->iCalDateToUnixTimestamp($event->dtend);
            }

            $data[] = [
                'import_source' => $this->getImportSource(),
                'import_id' => $event->uid . '-'. $idCount[$event->uid],
                'crdate' => $GLOBALS['EXEC_TIME'],
                'cruser_id' => $GLOBALS['BE_USER']->user['uid'],
                'type' => 0,
                'pid' => $configuration->getPid(),
                'title' => $this->cleanup($event->summary),
                'bodytext' => $this->cleanup($event->description),
                'datetime' => $datetime,
//                'categories' => $this->getCategories((array)$event['CATEGORIES_array'], $configuration),
                '_dynamicData' => [
                    'location' => $event->location,
                    'datetime_end' => $iCalService->iCalDateToUnixTimestamp($event->dtend),
                    'news_importicsxml' => [
                        'importDate' => date('d.m.Y h:i:s', $GLOBALS['EXEC_TIME']),
                        'feed' => $configuration->getPath(),
                        'UID' => $event->uid,
                        'VARIANT' => $idCount[$event->uid],
                        'LOCATION' => $event->location,
                        'DTSTART' => $event->dtstart,
                        'DTSTAMP' => $event->dtstamp,
                        'DTEND' => $event->dtend,
//                        'PRIORITY' => $event['PRIORITY'],
                        'SEQUENCE' => $event->sequence,
                        'STATUS' => $event->status,
                        'TRANSP' => $event->transp,
                    ]
                ],
            ];
        }

        if ($this->pathIsModified) {
            unlink($path);
        }

        return $data;
    }

    /**
     * @param array $categoryTitles
     * @param TaskConfiguration $configuration
     * @return array
     */
    protected function getCategories(array $categoryTitles, TaskConfiguration $configuration): array
    {
        $categoryIds = [];
        if (!empty($categoryTitles)) {
            if (!$configuration->getMapping()) {
                $this->logger->info('Categories found during import but no mapping assigned in the task!');
            } else {
                $categoryMapping = $configuration->getMappingConfigured();
                foreach ($categoryTitles as $title) {
                    if (!isset($categoryMapping[$title])) {
                        $this->logger->warning(sprintf('Category mapping is missing for category "%s"', $title));
                    } else {
                        $categoryIds[] = $categoryMapping[$title];
                    }
                }
            }
        }

        return $categoryIds;
    }

    /**
     * @param string $content
     * @return string
     */
    protected function cleanup($content)
    {
        $search = ['\\,'];
        $replace = [','];

        return str_replace($search, $replace, $content);
    }

    /**
     * @param TaskConfiguration $configuration
     * @return array
     */
    protected function getFileContent(TaskConfiguration $configuration)
    {
        $path = $configuration->getPath();
        if (GeneralUtility::isFirstPartOfStr($path, 'http://') || GeneralUtility::isFirstPartOfStr($path, 'https://')) {
            $content = $this->getContentOfFile($path);

            $temporaryCopyPath = PATH_site . 'typo3temp/' . md5($path . $GLOBALS['EXEC_TIME']);
            GeneralUtility::writeFileToTypo3tempDir($temporaryCopyPath, $content);
            $this->pathIsModified = true;
        } else {
            $temporaryCopyPath = PATH_site . $configuration->getPath();
        }

        if (!is_file($temporaryCopyPath)) {
            throw new RuntimeException(sprintf('The path "%s" does not contain a valid file', $temporaryCopyPath));
        }

        return $temporaryCopyPath;
    }

    protected function getContentOfFile($url)
    {
        $response = GeneralUtility::getUrl($url);

        if (empty($response)) {
            $message = sprintf('URL "%s" returned an empty content!', $url);
            $this->logger->alert($message);
            throw new RuntimeException($message);
        }
        return $response;
    }

    /**
     * @return string
     */
    public function getImportSource()
    {
        return 'newsimporticsxml_ics';
    }
}
