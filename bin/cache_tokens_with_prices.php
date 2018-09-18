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

define("MIN_TOKENS_NUM", 350);

$startTime = microtime(TRUE);
echo "\n[".date("Y-m-d H:i")."], Started.";

$aConfig = require_once dirname(__FILE__) . '/../service/config.php';

$jsonRequest = json_encode(array(
    'jsonrpc' => '2.0',
    'method'  => 'getTokensWithPrices',
    'params'  => array(),
    'id'      => mt_rand()
));

$context = stream_context_create(array(
    'http' => array(
        'method'  => 'POST',
        'header'  => 'Content-Type: application/json\r\n',
        'content' => $jsonRequest
    )
));
$jsonResponse = file_get_contents($aConfig['currency'], false, $context);

if($jsonResponse){
    try{
        $response = json_decode($jsonResponse, true);
        if($response && !isset($response['error']) && isset($response['result']) && (sizeof($response['result']) > MIN_TOKENS_NUM)){
            $confPrices = '<?php'. "\n";
            $confPrices .= 'return ['. "\n";
            $numTokens = 0;
            for($i = 0; $i < sizeof($response['result']); $i++){
                if(!empty($response['result'][$i]['address'])){
                    $confPrices .= "'" . $response['result'][$i]['address'] . "',". "\n";
                    $numTokens++;
                }
            }
            $confPrices .= '];'. "\n";
            if($numTokens > MIN_TOKENS_NUM){
                file_put_contents(dirname(__FILE__) . '/../service/config.prices.php', $confPrices);
            }
        }
    }catch(\Exception $e){
        //
        var_dump($e);
    }
}

$ms = round(microtime(TRUE) - $startTime, 4);
echo "\n[".date("Y-m-d H:i")."], Finished, {$ms} s.";
