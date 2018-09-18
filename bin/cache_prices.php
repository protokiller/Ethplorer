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

require dirname(__FILE__) . '/../service/lib/ethplorer.php';
$aConfig = require_once dirname(__FILE__) . '/../service/config.php';

$startTime = microtime(TRUE);
echo "\n[".date("Y-m-d H:i:s")."], Started.";

$numPrices = 0;
$maxTimeGetPrice = 0;

$es = Ethplorer::db($aConfig);
$lock = $es->createProcessLock('prices.lock', 600);
foreach($aConfig['updateRates'] as $address){
    $startGetPrice = microtime(TRUE);
    $es->getCache()->clearLocalCache();
    $es->getTokenPrice($address, TRUE);
    $timeGetPrice = round(microtime(TRUE) - $startGetPrice, 4);
    echo "\n[".date("Y-m-d H:i:s")."], Get price for address: " . $address . " Time : " . $timeGetPrice;

    $numPrices++;
    if($timeGetPrice > $maxTimeGetPrice) $maxTimeGetPrice = $timeGetPrice;
}
unset($lock);

$ms = round(microtime(TRUE) - $startTime, 4);
echo "\n[".date("Y-m-d H:i:s")."], Finished, {$ms} s. Total prices: " . $numPrices . " Max. time : " . $maxTimeGetPrice;
