<?php
/**
 * Entry point for static resources (JS, CSS, etc.)
 *
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

use Magento\Framework\App\Filesystem\DirectoryList;

require realpath(__DIR__) . '/app/bootstrap.php';
$bootstrap = \Magento\Framework\App\Bootstrap::create(BP, $_SERVER);
/** @var \Magento\Framework\App\StaticResource $app */
$app = $bootstrap->createApplication(\Magento\Framework\App\StaticResource::class);

/** @var \Magento\Framework\App\ResourceConnection $res */
$res = \Magento\Framework\App\ObjectManager::getInstance()->get('Magento\Framework\App\ResourceConnection');
/** @var Magento\Framework\DB\Profiler $profiler */
$profiler = $res->getConnection('read')->getProfiler();
$fileSystems = \Magento\Framework\App\ObjectManager::getInstance()->create('\Magento\Framework\Filesystem');
$directoryHandle = $fileSystems->getDirectoryWrite(DirectoryList::VAR_DIR);
$csvAdapter = \Magento\Framework\App\ObjectManager::getInstance()->create('Magento\ImportExport\Model\Export\Adapter\Csv',
    [
        'destination' => 'log/queryProfiler.csv'
    ]);

$queryContent = str_replace("\n\nAFF", "\nAFF", $directoryHandle->readFile('debug/db.log'));
$allQueries = explode("\n\n", $queryContent);
$rows = [];

foreach ($allQueries as $query) {
    $queryData = explode("\n", $query);

    if (count($queryData) < 5) {
        continue;
    }

    list($indexOfBind, $indexOfAFF) = findIndex($queryData);
    $queryString = '';
    $bindString = '';
    $timeString = end($queryData);
    $timeData = explode(':', $timeString);
    $time = (float) $timeData[1];

    if ($indexOfBind === 2) {
        $endOfQuery = $indexOfAFF - $indexOfBind + 2;
    } else {
        $endOfQuery = $indexOfBind + 1;
    }

    for ($i = 2; $i < $endOfQuery;  $i ++) {
        $queryString.= $queryData[$i];
    }

    if (strpos($queryString, '_base64') !== false) {
        continue;
    }

    if (isset($rows[$queryString])) {
        $rows[$queryString]['time'] += $time;
        $rows[$queryString]['count'] += 1;
    } else {
        if ($indexOfBind > 2) {
            for($i = $indexOfBind; $i < $indexOfAFF; $i ++) {
                $bindString .= $queryData[$i];
            }
        }

        $rows[$queryString] = [
            'query' => $queryString,
            'time' => $time,
            'count' => 1,
            'bind' => $bindString
        ];
    }
}

usort($rows, 'compare');

foreach ($rows as $rowData) {
    $csvAdapter->writeRow($rowData);
}

function findIndex(array $row): array
{

    $result = [2,0];

    foreach ($row as $key => $value) {
        if (strpos($value, 'BIND') !== false) {
            $result[0] = $key + 1;
        }

        if (strpos($value, 'AFF') !== false) {
            $result[1] = $key;
        }
    }

    return $result;
}

function compare($currentElement, $nextElement): int
{
    return  $nextElement['count'] <=> $currentElement['count'];
}



