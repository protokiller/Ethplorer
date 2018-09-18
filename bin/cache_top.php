<?php
/*!
 * Copyright 2016 Everex https://everex.io
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *     http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

ini_set('memory_limit', '512M');

require dirname(__FILE__) . '/../service/lib/ethplorer.php';
$aConfig = require_once dirname(__FILE__) . '/../service/config.php';

$startTime = microtime(TRUE);
echo "\n[".date("Y-m-d H:i")."], Started.";

$es = Ethplorer::db($aConfig);
$es->createProcessLock('topTokens.lock', 600);
$aCriteries = array('cap', 'trade', 'count');
$aTotals = null;
foreach($aCriteries as $criteria){
    $res = $es->getTokensTop(100, $criteria, TRUE);
    if(!$aTotals && isset($res['totals'])){
        $aTotals = $res['totals'];
    }
}

// save cap history into file
if($aTotals){
    $aTotals['date'] = gmdate("Y-m-d H:i", time());

    $data = array();
    $historyFile = dirname(__FILE__) . '/cap_history.json';
    if(file_exists($historyFile)){
        $history = @file_get_contents($historyFile);
        $data = json_decode($history, TRUE);
    }
    $data[] = $aTotals;
    $json = json_encode($data, JSON_PRETTY_PRINT);
    @file_put_contents($historyFile, $json);
}

$ms = round(microtime(TRUE) - $startTime, 4);
echo "\n[".date("Y-m-d H:i")."], Finished, {$ms} s.";
