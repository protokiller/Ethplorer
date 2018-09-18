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

require_once __DIR__ . '/cache.php';
require_once __DIR__ . '/mongo.php';
require_once __DIR__ . '/mongo_scanner.php';
require_once __DIR__ . '/mongo_pools.php';
require_once __DIR__ . '/profiler.php';
require_once __DIR__ . '/lock.php';
require_once __DIR__ . '/../../vendor/autoload.php';

use \Litipk\BigNumbers\Decimal as Decimal;

class Ethplorer {

    /**
     * Chainy contract address
     */
    const ADDRESS_CHAINY = '0xf3763c30dd6986b53402d41a8552b8f7f6a6089b';

    /**
     * Ethereum address
     */
    const ADDRESS_ETH = '0x0000000000000000000000000000000000000000';

    const MAX_OFFSET = 100000;

    const SHOW_TX_ALL = 'all';

    const SHOW_TX_ETH = 'eth';

    const SHOW_TX_TOKENS = 'tokens';

    /**
     * Settings
     *
     * @var array
     */
    protected $aSettings = array();

    /**
     * MongoDB.
     *
     * @var evxMongoScanner
     */
    protected $oMongo;

    /**
     * MongoDB.
     *
     * @var evxMongoPools
     */
    protected $oMongoPools;

    /**
     * Singleton instance.
     *
     * @var Ethplorer
     */
    protected static $oInstance;

    /**
     * Cache storage.
     *
     * @var evxCache
     */
    protected $oCache;

    /**
     * Process lock.
     *
     * @var evxProcessLock
     */
    protected $oProcessLock;

    /**
     *
     * @var int
     */
    protected $pageSize = 0;

    /**
     *
     * @var array
     */
    protected $aPager = array();

    /**
     *
     * @var string
     */
    protected $filter = FALSE;

    /**
     * Show transfers mode
     *
     * @var string
     */
    protected $showTx = self::SHOW_TX_ALL;

    /**
     * Cache for getTokens
     *
     * @var array
     */
    protected $aTokens = FALSE;

    /**
     * Cache for current prices
     *
     * @var array
     */
    protected $aPrices = FALSE;

    /**
     * Sentry client object
     * composer require "sentry/sentry"
     *
     * @var Raven_Client
     */
    protected $sentryClient;

    protected $useOperations2 = FALSE;

    protected $getTokensCacheCreation = FALSE;
    
    protected $debug = false;
    
    protected $memUsage = [];

    /**
     * Constructor.
     *
     * @throws Exception
     */
    protected function __construct(array $aConfig){
        $uri = isset($_SERVER) && isset($_SERVER["REQUEST_URI"]) ? $_SERVER["REQUEST_URI"] : FALSE;
        evxProfiler::checkpoint('Ethplorer', 'START', $uri);
        $this->aSettings = $aConfig;
        $this->aSettings += array(
            "cacheDir" => dirname(__FILE__) . "/../cache/",
            "logsDir" => dirname(__FILE__) . "/../log/",
            "locksDir" => dirname(__FILE__) . "/../lock/",
        );
        if(isset($this->aSettings['sentry']) && is_array($this->aSettings['sentry']) && class_exists('Raven_Client')){
            try {
                $aSentry = $this->aSettings['sentry'];
                $https = isset($aSentry['https']) ? !!$aSentry['https'] : false;
                $url = isset($aSentry['url']) ? $aSentry['url'] : false;
                $key = isset($aSentry['key']) ? $aSentry['key'] : false;
                $secret = isset($aSentry['secret']) ? $aSentry['secret'] : false;
                $id = isset($aSentry['id']) ? $aSentry['id'] : false;
                if($url && $key){
                    $protocol = $https ? "https" : "http";
                    $this->sentryClient = new Raven_Client($protocol . "://" . $key . ($secret ? (":" . $secret) : "") . "@" . $url . ($id ? ("/" . $id) : ""));
                    $this->sentryClient->install();
                }else{
                    throw new \Exception("Invalid configuration: one of mandatory field [" . ($url ? "key" : "url") . "] is missing");
                }
            }catch(\Exception $e){
                error_log("Sentry initialization failed: " . $e->getMessage());
            }
        }
        $cacheDriver = isset($this->aSettings['cacheDriver']) ? $this->aSettings['cacheDriver'] : 'file';
        $useLocks = isset($this->aSettings['useLocks']) ? $this->aSettings['useLocks'] : FALSE;
        $this->useOperations2 = isset($this->aSettings['useOperations2']) ? $this->aSettings['useOperations2'] : FALSE;
        $this->oCache = new evxCache($this->aSettings, $cacheDriver, $useLocks);
        if(isset($this->aSettings['mongo']) && is_array($this->aSettings['mongo'])){
            evxMongoScanner::init($this->aSettings['mongo'], $this->useOperations2);
            $this->oMongo = evxMongoScanner::getInstance();
        }
        if(isset($this->aSettings['bundles']) && is_array($this->aSettings['bundles'])){
            evxMongoPools::init($this->aSettings['bundles']);
            $this->oMongoPools = evxMongoPools::getInstance();
        }

        if(isset($this->aSettings['debugId']) && $this->aSettings['debugId']){
            $this->debug = $this->aSettings['debugId'];
        }
    }

    public function __destruct(){
        // Todo: profiler config
        evxProfiler::checkpoint('Ethplorer', 'FINISH');
        $total = evxProfiler::getTotalTime();
        if($this->debug){
            evxProfiler::log($this->aSettings['logsDir'] . 'profiler-' . /* time() . '-' . */ md5($this->debug) . '.log');
        }
        $slowQueryTime = isset($this->aSettings['slowQueryTime']) ? (int)$this->aSettings['slowQueryTime'] : 10;
        if(($total > $slowQueryTime) && (php_sapi_name() !== 'cli')){
            evxProfiler::log($this->aSettings['logsDir'] . 'profiler-long-queries.log');
        }
    }

    /**
     * Creates and returns process lock object
     *
     * @return evxProcessLock
     */
    public function createProcessLock($name, $lockTTL = 0){
        $this->oProcessLock = new evxProcessLock($this->aSettings['locksDir'] . $name, $lockTTL ? $lockTTL : $this->aSettings['lockTTL'], TRUE);
        return $this->oProcessLock;
    }

    /**
     * Returns cache object
     *
     * @return evxCache
     */
    public function getCache(){
        return $this->oCache;
    }

    /**
     * Returns mongo object
     *
     * @return evxMongo
     */
    public function getMongo(){
        return $this->oMongo;
    }

    public function storeMemoryUsage($label = false){
        if($this->debug){
            if(!$label){
                $label = microtime();
            }
            $mb = 1024 * 1024;
            $this->memUsage[$label] = [
                'php' => round(memory_get_usage() / $mb, 2),
                'real' => round(memory_get_usage(TRUE) / $mb, 2)
            ];
        }
    }       

    /**
     * Returns some debug data
     *
     * @return array
     */
    public function getDebugData(){
        return array(
            'totalTime' => evxProfiler::getTotalTime(),
            'dbConnected' => $this->oMongo->dbConnected(),
            'queries' => $this->oMongo->getQueryProfileData(),
            'memUsage' => $this->memUsage
        );
    }
    
    /**
     * Singleton getter.
     *
     * @return Ethereum
     */
    public static function db(array $aConfig = array()){
        if(is_null(self::$oInstance)){
            self::$oInstance = new Ethplorer($aConfig);
        }
        return self::$oInstance;
    }

    /**
     * Sets new page size.
     *
     * @param int $pageSize
     */
    public function setPageSize($pageSize){
        $this->pageSize = $pageSize;
    }

    /**
     * Sets current page offset for section
     *
     * @param string $section
     * @param int $page
     */
    public function setPager($section, $page = 1){
        $this->aPager[$section] = $page;
    }

    /**
     * Return page offset for section
     *
     * @param string $section
     * @return int
     */
    public function getPager($section){
        return isset($this->aPager[$section]) ? $this->aPager[$section] : 1;
    }

    /**
     * Set filter value
     *
     * @param string $filter
     */
    public function setFilter($filter){
        $this->filter = $filter;
    }

    /**
     * Set show tx mode
     *
     * @param string $showTx
     */
    public function setShowTx($showTx){
        $this->showTx = $showTx;
    }

    /**
     * Returns item offset for section.
     *
     * @param string $section
     * @int type
     */
    public function getOffset($section){
        $limit = $this->pageSize;
        return (1 === $this->getPager($section)) ? FALSE : ($this->getPager($section) - 1) * $limit;
    }

    public function getOffsetReverse($section, $total){
        $limit = $this->pageSize;
        $numPages = ceil($total / $limit);
        $offsetReverse = $total - ($this->getPager($section) * $this->pageSize);
        if($offsetReverse < 0) $offsetReverse = 0;
        return (intval($numPages) === $this->getPager($section)) ? 0 : $offsetReverse;
    }

    /**
     * Returns true if provided string is a valid ethereum address.
     *
     * @param string $address  Address to check
     * @return bool
     */
    public function isValidAddress($address){
        return (is_string($address)) ? preg_match("/^0x[0-9a-f]{40}$/", $address) : false;
    }

    /**
     * Returns true if provided string is a valid ethereum tx hash.
     *
     * @param string  $hash  Hash to check
     * @return bool
     */
    public function isValidTransactionHash($hash){
        return (is_string($hash)) ? preg_match("/^0x[0-9a-f]{64}$/", $hash) : false;
    }

    /**
     * Returns true if provided string is a chainy contract address.
     *
     * @param type $address
     * @return bool
     */
    public function isChainyAddress($address){
        return ($address === self::ADDRESS_CHAINY);
    }

    /**
     * Returns advanced address details.
     *
     * @param string $address
     * @return array
     */
    public function getAddressDetails($address, $limit = 50){
        evxProfiler::checkpoint('getAddressDetails', 'START', 'address=' . $address . ', limit=' . $limit);
        $result = array(
            "isContract"    => FALSE,
            "transfers"     => array()
        );
        if($this->pageSize){
            $limit = $this->pageSize;
        }
        $refresh = isset($this->aPager['refresh']) ? $this->aPager['refresh'] : FALSE;
        $contract = $this->getContract($address);
        $token = FALSE;
        if($contract){
            $result['isContract'] = TRUE;
            $result['contract'] = $contract;
            if($token = $this->getToken($address)){
                $result["token"] = $token;
            }elseif($this->isChainyAddress($address)){
                $result['chainy'] = $this->getChainyTransactions($limit, $this->getOffset('chainy'));
                $count = $this->countChainy();
                $result['pager']['chainy'] = array(
                    'page' => $this->getPager('chainy'),
                    'records' => $count,
                    'total' => $this->filter ? $this->countChainy(FALSE) : $count
                );
            }
        }else{
            $token = $this->getToken($address);
            if(is_array($token)){
                $result['isContract'] = TRUE;
                // @todo
                $result['contract'] = array();
                $result["token"] = $token;
            }
        }
        if($result['isContract'] && isset($result['token'])){
            $result['pager'] = array('pageSize' => $limit);
            foreach(array('transfers', 'issuances', 'holders') as $type){
                if(!$refresh || ($type === $refresh)){
                    $page = $this->getPager($type);
                    $offset = $this->getOffset($type);
                    $offsetReverse = FALSE;
                    switch($type){
                        case 'transfers':
                            $count = 0;
                            $total = 0;
                            if($this->showTx == self::SHOW_TX_ALL || $this->showTx == self::SHOW_TX_TOKENS){
                                $count += $this->getContractOperationCount('transfer', $address);
                                $total += $this->filter ? $this->getContractOperationCount('transfer', $address, FALSE) : $count;
                            }
                            if($this->showTx == self::SHOW_TX_ALL || $this->showTx == self::SHOW_TX_ETH){
                                $countEth = (int)$this->getContractOperationCount('transfer', $address, TRUE, TRUE);
                                $totalEth = (int)$this->filter ? $this->getContractOperationCount('transfer', $address, FALSE, TRUE) : $countEth;
                                $count += $countEth;
                                $total += $totalEth;
                            }
                            $offsetReverse = $this->getOffsetReverse('transfers', $count);
                            $cmd = 'getContractTransfers';
                            break;
                        case 'issuances':
                            $count = $this->getContractOperationCount(array('$in' => array('issuance', 'burn', 'mint')), $address);
                            $total = $this->filter ? $this->getContractOperationCount(array('$in' => array('issuance', 'burn', 'mint')), $address, FALSE) : $count;
                            $cmd = 'getContractIssuances';
                            break;
                        case 'holders':
                            $count = $this->getTokenHoldersCount($address);
                            $total = $this->filter ? $this->getTokenHoldersCount($address, FALSE) : $count;
                            $offsetReverse = $this->getOffsetReverse('holders', $count);
                            $cmd = 'getTokenHolders';
                            break;
                    }
                    if($offset && ($offset > $count)){
                        $offset = 0;
                        $page = 1;
                    }
                    $result[$type] = $this->{$cmd}($address, $limit, (($offsetReverse === FALSE) ? $offset : array($offset, $offsetReverse)));
                    $result['pager'][$type] = array(
                        'page' => $page,
                        'records' => $count,
                        'total' => $total
                    );
                }
            }
            // @todo: move to extension
            $ck = '0x06012c8cf97bead5deae237070f9587f8e7a266d';
            if($address == $ck){
                $result['cryptokitties'] = true;
            }
        }else{
            // @todo: move to extension
            $ck = '0x06012c8cf97bead5deae237070f9587f8e7a266d';
            $cursor = $this->oMongo->find('transactions', array('from' => $address, 'to' => $ck));
            if($cursor){
                foreach($cursor as $token){
                    $result['cryptokitties'] = true;
                    break;
                }
            }            
        }
        if(!isset($result['token']) && !isset($result['pager'])){
            // Get balances
            $result["tokens"] = array();
            $result["balances"] = $this->getAddressBalances($address);
            $aBalances = array();
            evxProfiler::checkpoint('getTokenLoop', 'START');
            foreach($result["balances"] as $balance){
                $balanceToken = $this->getToken($balance["contract"], TRUE);
                if($balanceToken){
                    $result["tokens"][$balance["contract"]] = $balanceToken;
                    $aBalances[] = $balance;
                }
            }
            evxProfiler::checkpoint('getTokenLoop', 'FINISH');
            $result["balances"] = $aBalances;
            $countOperations = $this->countOperations($address, TRUE, $this->showTx);
            $totalOperations = $this->filter ? $this->countOperations($address, FALSE, $this->showTx) : $countOperations;
            $result['pager']['transfers'] = array(
                'page' => $this->getPager('transfers'),
                'records' => $countOperations,
                'total' => $totalOperations,
            );
            $aOffsets = [$this->getOffset('transfers'), $this->getOffsetReverse('transfers', $countOperations)];
            $result["transfers"] = $this->getAddressOperations($address, $limit, $aOffsets, array('transfer'), $this->showTx);
        }
        if(!$refresh){
            $result['balance'] = $this->getBalance($address);
            $result['balanceOut'] = 0;
            $result['balanceIn'] = 0;
            $in = $this->getEtherTotalIn($address, FALSE, !$this->isHighloadedAddress($address));
            $out = $in - $result['balance'];
            if($out < 0){
                $in = $result['balance'];
                $out = 0;
                $result['hideBalanceOut'] = true;
            }
            $result['balanceOut'] = $out;
            $result['balanceIn'] = $in;
        }
        evxProfiler::checkpoint('getAddressDetails', 'FINISH');
        return $result;
    }

    public function getTokenTotalInOut($address){
        evxProfiler::checkpoint('getTokenTotalInOut', 'START', 'address=' . $address);
        $result = array('totalIn' => 0, 'totalOut' => 0);
        // temporary off
        if(false && $this->isValidAddress($address)){
            $aResult = $this->oMongo->aggregate('balances', array(
                array('$match' => array("contract" => $address)),
                array(
                    '$group' => array(
                        "_id" => '$contract',
                        'totalIn' => array('$sum' => '$totalIn'),
                        'totalOut' => array('$sum' => '$totalOut')
                    )
                ),
            ));
            if(is_array($aResult) && isset($aResult['result']) && count($aResult['result'])){
                $result['totalIn'] = $aResult['result'][0]['totalIn'];
                $result['totalOut'] = $aResult['result'][0]['totalOut'];
            }
        }
        evxProfiler::checkpoint('getTokenTotalInOut', 'FINISH');
        return $result;
    }

    /**
     * Returns ETH address total in.
     * Total out can be calculated as total in - current balance.
     *
     * @param string $address
     * @param bool $updateCache
     * @return float
     */
    public function getEtherTotalIn($address, $updateCache = FALSE, $parityOnly = FALSE){
        $cache = 'ethIn-' . $address;
        $result = $this->oCache->get($cache, FALSE, TRUE, 300);
        if($updateCache || (FALSE === $result)){
            evxProfiler::checkpoint('getEtherTotalIn', 'START', 'address=' . $address);
            if($this->isValidAddress($address)){
                $balance = false;
                // Get totalIn from DB
                if(!$parityOnly){
                    $cursor = $this->oMongo->find('ethBalances', array('address' => $address));
                    foreach($cursor as $balance) break;
                }
                if($balance && isset($balance['balance']) && isset($balance['totalIn'])){
                    $result = $balance['totalIn'];
                }elseif($parityOnly){
                    // Get from parity
                    $aResult = $this->oMongo->aggregate('operations2', array(
                        array('$match' => array("to" => $address, "isEth" => true)),
                        array(
                            '$group' => array(
                                "_id" => '$to',
                                'in' => array('$sum' => '$value')
                            )
                        ),
                    ));
                    $result = 0;
                    if(is_array($aResult) && isset($aResult['result'])){
                        foreach($aResult['result'] as $record){
                            $result += floatval($record['in']);
                        }
                    }
                }
                $this->oCache->save($cache, $result);
            }
            evxProfiler::checkpoint('getEtherTotalIn', 'FINISH');
        }
        return (float)$result;
    }    
    
    /**
     * Returns ETH address total out.
     * Total in can be calculated as total out + current balance.
     *
     * @param string $address
     * @param bool $updateCache
     * @return float
     */
    public function getEtherTotalOut($address, $updateCache = FALSE){
        $cache = 'ethOut-' . $address;
        $result = $this->oCache->get($cache, FALSE, TRUE, 3600);
        if($updateCache || (FALSE === $result)){
            evxProfiler::checkpoint('getEtherTotalOut', 'START', 'address=' . $address);
            if($this->isValidAddress($address)){
                $aResult = $this->oMongo->aggregate('transactions', array(
                    array('$match' => array("from" => $address)),
                    array(
                        '$group' => array(
                            "_id" => '$from',
                            'out' => array('$sum' => '$value')
                        )
                    ),
                ));
                $result = 0;
                if(is_array($aResult) && isset($aResult['result'])){
                    foreach($aResult['result'] as $record){
                        $result += floatval($record['out']);
                    }
                }
                $this->oCache->save($cache, $result);
            }
            evxProfiler::checkpoint('getEtherTotalOut', 'FINISH');
        }
        return (float)$result;
    }

    /**
     * Returns transactions list for a specific address.
     *
     * @param string  $address
     * @param int     $limit
     * @return array
     */
    public function getTransactions($address, $limit = 10, $timestamp = 0, $showZero = FALSE){
        $cache = 'transactions-' . $address . '-' . $limit . '-' . ($timestamp ? ($timestamp . '-') : '') . ($showZero ? '1' : '0');
        $result = $this->oCache->get($cache, FALSE, TRUE, 30);        
        if(!$result){
            $result = array();
            $fields = ['from', 'to'];
            foreach($fields as $field){
                $search = array();
                $search[$field] = $address;
                if($timestamp > 0){
                    $search['timestamp'] = array('$lte' => $timestamp);
                }
                if(!$showZero){
                    $search['value'] = array('$gt' => 0);
                }
                $cursor = $this->oMongo->find('transactions', $search, array("timestamp" => -1), $limit);
                foreach($cursor as $tx){
                    $receipt = isset($tx['receipt']) ? $tx['receipt'] : false;
                    $tx['gasLimit'] = $tx['gas'];
                    $tx['gasUsed'] = isset($tx['gasUsed']) ? $tx['gasUsed'] : ($receipt ? $receipt['gasUsed'] : 0);
                    // @todo: research
                    // $toContract = !!$tx['input'];
                    // $toContract = !!$this->getContract($tx['to']); // <-- too slow

                    $success = ((21000 == $tx['gasUsed']) || /*!$toContract ||*/ ($tx['gasUsed'] < $tx['gasLimit']) || ($receipt && !empty($receipt['logs'])));
                    $success = isset($tx['status']) ? $this->txSuccessStatus($tx) : $success;

                    $result[] = array(
                        'timestamp' => $tx['timestamp'],
                        'from' => $tx['from'],
                        'to' => $tx['to'],
                        'hash' => $tx['hash'],
                        'value' => $tx['value'],
                        'input' => $tx['input'],
                        'success' => $success
                    );
                }
            }
            usort($result, function($a, $b){ 
                return ($a['timestamp'] > $b['timestamp']) ? -1 : (($a['timestamp'] < $b['timestamp']) ? 1 : 0);
            });

            if(count($result) > $limit){
                $limitedResult = [];
                foreach($result as $index => $record){
                    if($index == $limit) break;
                    $limitedResult[] = $record;
                }
                $result = $limitedResult;
            }
            $this->oCache->save($cache, $result);
        }
        return $result;
    }

    /**
     * Returns advanced transaction data.
     *
     * @param string  $hash  Transaction hash
     * @return array
     */
    public function getTransactionDetails($hash){
        evxProfiler::checkpoint('getTransactionDetails', 'START', 'hash=' . $hash);
        $cache = 'tx-' . $hash;
        $result = $this->oCache->get($cache, false, true);
        if(isset($_GET['update_cache']) && $_GET['update_cache']) $result = FALSE;
        if(false === $result){
            $tx = $this->getTransaction($hash);
            $result = array(
                "tx" => $tx,
                "contracts" => array()
            );

            // if transaction is not mained trying get it from pedding pool
            if(false === $tx){
                $transaction = $this->getTransactionFromPoolByHash($hash);
                if ($transaction) {
                    // transaction is pending if has no blockHash
                    $result['pending'] = true;
                    $result['tx'] = $transaction ?: false;
                    if (isset($transaction['to'])) {
                        $token = $this->getToken($transaction['to']);
                        if ($token && isset($transaction['input'])) {
                            $operation = $this->getTokenOperationData($transaction['input'], $token['decimals']);
                            if ($operation && strtoupper($operation['code']) === '0XA9059CBB') {
                                $result['token'] = $token;
                                $result['operations'] = [
                                    [
                                        'transactionHash' => $transaction['hash'],
                                        'blockNumber' => null,
                                        'contract' => $operation['from'],
                                        'value' => $operation['value'],
                                        'intValue' => (int)$operation['value'],
                                        'type' => 'Transfer',
                                        'isEth' => false,
                                        'priority' => 0,
                                        'from' => $transaction['from'],
                                        'to' => $transaction['to'],
                                        'addresses' => '',
                                        'success' => false,
                                        'pending' => true,
                                        'token' => $token
                                    ]
                                ];
                            }
                        }
                    }
                }
            }
            $tokenAddr = false;
            if(isset($tx["creates"]) && $tx["creates"]){
                $result["contracts"][] = $tx["creates"];
                $tokenAddr = $tx["creates"];
            }
            $fromContract = $this->getContract($tx["from"]);
            if($fromContract){
                $result["contracts"][] = $tx["from"];
            }
            if(isset($tx["to"]) && $tx["to"]){
                if($this->getContract($tx["to"])){
                    $result["contracts"][] = $tx["to"];
                    $tokenAddr = $tx["to"];
                }
            }
            $result["contracts"] = array_values(array_unique($result["contracts"]));
            if (!isset($result['pending'])) {
                if($tokenAddr){
                    // If no price, but token have price, save current and set cache lifetime for 1 hour
                    if($token = $this->getToken($tokenAddr)){
                        $result['token'] = $token;
                        $result['token']['priceHistoric'] = $this->_getRateByDate($tokenAddr, date("Y-m-d", $tx['timestamp']));
                    }
                }
                $result["operations"] = $this->getOperations($hash);
                if(is_array($result["operations"]) && count($result["operations"])){
                    foreach($result["operations"] as $idx => $operation){
                        if($result["operations"][$idx]['contract'] !== $tx["to"]){
                            $result["contracts"][] = $operation['contract'];
                        }
                        if($token = $this->getToken($operation['contract'])){
                            $result['token'] = $token;
                            $result["operations"][$idx]['type'] = ucfirst($operation['type']);
                            $result["operations"][$idx]['token'] = $token;
                        }
                    }
                }
            }
            if($result['tx'] && !isset($result['pending'])) {
                $this->oCache->save($cache, $result);
            }
        }
        if(is_array($result) && is_array($result['tx'])){
            if (!isset($result['pending'])) {
                $confirmations = 1;
                $lastblock = $this->getLastBlock();
                if($lastblock){
                    $confirmations = $lastblock - $result['tx']['blockNumber'] + 1;
                    if($confirmations < 1){
                        $confirmations = 1;
                    }
                }
                $result['tx']['confirmations'] = $confirmations;
            } else {
                // if transaction in pending status
                $result['tx']['confirmations'] = !empty($result['tx']['blockNumber']) ? 1 : 0;
            }

            // Temporary
            $methodsFile = dirname(__FILE__) . "/../methods.sha3.php";
            if(file_exists($methodsFile)){
                if($result['tx']['input']){
                    $methods = require($methodsFile);
                    $cmd = substr($result['tx']['input'], 2, 8);
                    if(isset($methods[$cmd])){
                        $result['tx']['method'] = $methods[$cmd];
                    }
                }
            }
        }
        if(is_array($result) && isset($result['token']) && is_array($result['token']) && !isset($result['pending'])){
            $result['token'] = $this->getToken($result['token']['address']);
        }
        evxProfiler::checkpoint('getTransactionDetails', 'FINISH');
        return $result;
    }

    /**
     * Return operation details
     * @param String $input Transaction input raw data
     * @param Int $decimals
     * @return Array|null Operation data
     */
    private function getTokenOperationData($input, $decimals = 18) {
        preg_match('/^(?<code>.{10})(?<from>.{64})(?<value>.{64})(?<rest>.*)?$/', $input, $operation);
        if ($operation) {
            $ten = Decimal::create(10);
            $dec = Decimal::create($decimals);
            $value = Decimal::create(hexdec($operation['value']));
            $operation['value'] = (string)$value;

            return $operation;
        }

        return null;
    }

    /**
     * Returns a list of transactions currently in the queue of Parity
     * @param String $hash Transaction hash
     * @return Array|null
     */
    public function getTransactionFromPoolByHash(string $hash) {
        evxProfiler::checkpoint('getTransactionFromPoolByHash', 'START');
        $time = microtime(true);
        $cacheId = 'ethTransactionByHash-' . $hash;
        $transaction = $this->oCache->get($cacheId, false, true, 30);
        if(false === $transaction){
            $transactionRaw = $this->_callRPC('eth_getTransactionByHash', [$hash]);
            if (false !== $transactionRaw) {
                $transaction = [
                    'hash' => $transactionRaw['blockHash'],
                    'blockNumber' => $transactionRaw['blockNumber'] ? hexdec(str_replace('0x', '', $transactionRaw['blockNumber'])) : null,
                    'to' => $transactionRaw['to'],
                    'from' => $transactionRaw['from'],
                    'value' => hexdec(str_replace('0x', '', $transactionRaw['value'])) / pow(10, 18),
                    'input' => $transactionRaw['input'],
                    'gasLimit' => hexdec(str_replace('0x', '', $transactionRaw['gas'])),
                    'gasPrice' => hexdec(str_replace('0x', '', $transactionRaw['gasPrice'])) / pow(10, 18),
                    'nonce' => hexdec(str_replace('0x', '', $transactionRaw['nonce']))
                ];

                $this->oCache->save($cacheId, $transaction);
            } else {
                file_put_contents(__DIR__ . '/../log/parity.log', '[' . date('Y-m-d H:i:s') . "] - getting transaction by hash from pending pool is failed\n", FILE_APPEND);
            }
        }
        $qTime = microtime(true) - $time;
        if($qTime > 0.5){
            file_put_contents(__DIR__ . '/../log/parity.log', '[' . date('Y-m-d H:i:s') . '] - (' . $qTime . "s) getting transaction by hash from pending pool too slow\n", FILE_APPEND);
        }
        evxProfiler::checkpoint('getTransactionFromPoolByHash', 'FINISH');
        return $transaction;
    }

    /**
     * Return address ETH balance.
     *
     * @param string  $address  Address
     * @return double
     */
    public function getBalance($address){
        evxProfiler::checkpoint('getBalance', 'START', 'address=' . $address);
        $time = microtime(true);
        $cacheId = 'ethBalance-' . $address;
        $balance = $this->oCache->get($cacheId, false, true, 30);
        if(false === $balance){
            $result = false;
            $cursor = $this->oMongo->find('ethBalances', array('address' => $address));
            foreach($cursor as $result) break;
            if($result && isset($result['balance']) && ((time() * 1000 - (int)$result['lastUpdated']) < 3600000 * 24)){
                $balance = $result['balance'];               
            }else{
                $balance = $this->_callRPC('eth_getBalance', array($address, 'latest'));
                if(false !== $balance){
                    $balance = hexdec(str_replace('0x', '', $balance)) / pow(10, 18);
                    $this->oCache->save($cacheId, $balance);
                }else{
                    file_put_contents(__DIR__ . '/../log/parity.log', '[' . date('Y-m-d H:i:s') . '] - get balance for ' . $address . " failed\n", FILE_APPEND);
                    $this->oCache->save($cacheId, -1);
                }
            }
        }
        $qTime = microtime(true) - $time;
        if($qTime > 0.5){
            file_put_contents(__DIR__ . '/../log/parity.log', '[' . date('Y-m-d H:i:s') . '] - (' . $qTime . 's) get ETH balance of ' . $address . "\n", FILE_APPEND);
        }
        evxProfiler::checkpoint('getBalance', 'FINISH');
        return $balance;
    }

    /**
     * Return transaction data by transaction hash.
     *
     * @param string  $tx  Transaction hash
     * @return array
     */
    public function getTransaction($tx){
        evxProfiler::checkpoint('getTransaction', 'START', 'hash=' . $tx);
        $cursor = $this->oMongo->find('transactions', array("hash" => $tx));
        $result = false;
        foreach($cursor as $result) break;
        if($result){
            $receipt = isset($result['receipt']) ? $result['receipt'] : false;
            $result['gasLimit'] = $result['gas'];
            unset($result["_id"]);
            unset($result["gas"]);
            $result['gasUsed'] = isset($result['gasUsed']) ? $result['gasUsed'] : ($receipt ? $receipt['gasUsed'] : 0);

            $success = ((21000 == $result['gasUsed']) || ($result['gasUsed'] < $result['gasLimit']) || ($receipt && !empty($receipt['logs'])));
            $result['success'] = isset($result['status']) ? $this->txSuccessStatus($result) : $success;

            $methodsFile = dirname(__FILE__) . "/../methods.sha3.php";
            if(file_exists($methodsFile)){
                if($result['input']){
                    $methods = require($methodsFile);
                    $cmd = substr($result['input'], 2, 8);
                    if(isset($methods[$cmd])){
                        $result['method'] = $methods[$cmd];
                    }
                }
            }

        }
        evxProfiler::checkpoint('getTransaction', 'FINISH');
        return $result;
    }


    /**
     * Returns list of transfers in specified transaction.
     *
     * @param string  $tx  Transaction hash
     * @return array
     */
    public function getOperations($tx, $type = FALSE, $showEth = FALSE){
        evxProfiler::checkpoint('getOperations', 'START', 'hash=' . $tx);
        $search = array("transactionHash" => $tx);
        if($type){
            $search['type'] = $type;
        }
        if(!$showEth){
            if($this->useOperations2){
                $search['isEth'] = false;
            }else{
                $search['contract'] = array('$ne' => 'ETH');
            }
        }
        $cursor = $this->oMongo->find('operations', $search, array('priority' => 1));
        $result = array();
        foreach($cursor as $res){
            unset($res["_id"]);
            $res["success"] = true;
            $result[] = $res;
        }
        evxProfiler::checkpoint('getOperations', 'FINISH');
        return $result;
    }

    /**
     * Returns list of transfers in specified transaction.
     *
     * @param string  $tx  Transaction hash
     * @return array
     */
    public function getTransfers($tx){
        return $this->getOperations($tx, 'transfer');
    }

    /**
     * Returns list of issuances in specified transaction.
     *
     * @param string  $tx  Transaction hash
     * @return array
     */
    public function getIssuances($tx){
        return $this->getOperations($tx, array('$in' => array('issuance', 'burn', 'mint')));
    }

    /**
     * Returns list of known tokens.
     *
     * @param bool  $updateCache  Update cache from DB if true
     * @return array
     */
    public function getTokens($updateCache = false){
        if(FALSE !== $this->aTokens){
            return $this->aTokens;
        }
        $aResult = $this->oCache->get('tokens', false, true);
        // Allow generating cache only from cron jobs
        if(!$this->getTokensCacheCreation && ($updateCache/* || (false === $aResult)*/)){
            // Recursion protection
            $this->getTokensCacheCreation = true;

            evxProfiler::checkpoint('getTokens', 'START');
            $aPrevTokens = array();
            if($updateCache){
                $aPrevTokens = $aResult;
                if(!is_array($aPrevTokens)){
                    $aPrevTokens = array();
                }
            }
            $this->_cliDebug("prevTokens count = " . count($aPrevTokens));
            $cursor = $this->oMongo->find('tokens', array(), array("transfersCount" => -1));
            // Do not clear old data, just update records
            if(!is_array($aResult)){
                $aResult = array();
            }
            try {
                foreach($cursor as $index => $aToken){
                    $address = $aToken["address"];
                    $this->_cliDebug("Token #" . $index . " / " . $address);
                    unset($aToken["_id"]);
                    $aResult[$address] = $aToken;
                    if(!isset($aPrevTokens[$address]) || ($aPrevTokens[$address]['transfersCount'] < $aToken['transfersCount'])){
                        $this->_cliDebug($address . " was recently updated (transfers count = " . $aToken['transfersCount'] . ")");
                        $aResult[$address]['issuancesCount'] = $this->getContractOperationCount(array('$in' => array('issuance', 'burn', 'mint')), $address, FALSE);
                        $hc = $this->getTokenHoldersCount($address);;
                        if(FALSE !== $hc){
                            $aResult[$address]['holdersCount'] = $hc;
                        }
                    }else if(!isset($aPrevTokens[$address]) || !isset($aPrevTokens[$address]['issuancesCount'])){
                        $aResult[$address]['issuancesCount'] = $this->getContractOperationCount(array('$in' => array('issuance', 'burn', 'mint')), $address, FALSE);
                    }else{
                        $aResult[$address]['issuancesCount'] = isset($aPrevTokens[$address]['issuancesCount']) ? $aPrevTokens[$address]['issuancesCount'] : 0;
                        $aResult[$address]['holdersCount'] = isset($aPrevTokens[$address]['holdersCount']) ? $aPrevTokens[$address]['holdersCount'] : 0;
                    }
                    if(isset($this->aSettings['client']) && isset($this->aSettings['client']['tokens'])){
                        $aClientTokens = $this->aSettings['client']['tokens'];
                        if(isset($aClientTokens[$address])){
                            $aResult[$address] = array_merge($aResult[$address], $aClientTokens[$address]);
                        }
                    }
                    if(isset($aResult[$address]['name'])){
                        $aResult[$address]['name'] = htmlspecialchars($aResult[$address]['name']);
                    }
                    if(isset($aResult[$address]['symbol'])){
                        $aResult[$address]['symbol'] = htmlspecialchars($aResult[$address]['symbol']);
                    }

                    $cursor2 = $this->oMongo->find('addressCache', array("address" => $address));
                    $aCachedData = false;
                    foreach($cursor2 as $aCachedData) break;
                    if(false !== $aCachedData){
                        $aResult[$address]['txsCount'] = $aCachedData['txsCount'];
                        if(isset($aCachedData['ethTransfersCount'])) $aResult[$address]['ethTransfersCount'] = $aCachedData['ethTransfersCount'];
                    }
                }
            }catch(\Exception $e){
                $this->_cliDebug("Exception: " . $e->getMessage());
            }
            if(isset($aResult[self::ADDRESS_ETH])){
                unset($aResult[self::ADDRESS_ETH]);
            }
            $this->oCache->save('tokens', $aResult);
            evxProfiler::checkpoint('getTokens', 'FINISH');
        }
        $this->aTokens = $aResult;
        return $aResult;
    }


    public function getTokenHoldersCount($address, $useFilter = TRUE){
        evxProfiler::checkpoint('getTokenHoldersCount', 'START', 'address=' . $address);
        $cache = 'getTokenHoldersCount-' . $address;
        if($useFilter && $this->filter){
            $cache .= $this->filter;
        }
        $result = $this->oCache->get($cache, false, true, 120);
        if(FALSE === $result){
            $search = array('contract' => $address, 'balance' => array('$gt' => 0));
            if($useFilter && $this->filter){
                $search = array(
                    '$and' => array(
                        $search,
                        array('address' => array('$regex' => $this->filter)),
                    )
                );
            }
            $result = $this->oMongo->count('balances', $search);
            if(FALSE !== $result){
                $this->oCache->save($cache, $result);
            }
        }
        evxProfiler::checkpoint('getTokenHoldersCount', 'FINISH');
        return $result;
    }

    /**
     * Returns list of token holders.
     *
     * @param string $address
     * @param int $limit
     * @return array
     */
    public function getTokenHolders($address, $limit = FALSE, $offset = FALSE){
        evxProfiler::checkpoint('getTokenHolders', 'START', 'address=' . $address . ', limit=' . $limit . ', offset=' . (is_array($offset) ? print_r($offset, TRUE) : (int)$offset));
        $result = array();
        $token = $this->getToken($address);
        if($token){
            $search = array('contract' => $address, 'balance' => array('$gt' => 0));
            if($this->filter){
                $search = array(
                    '$and' => array(
                        $search,
                        array('address' => array('$regex' => $this->filter)),
                    )
                );
            }
            $reverseOffset = FALSE;
            $skip = is_array($offset) ? $offset[0] : $offset;
            $sortOrder = -1;
            if(is_array($offset) && ($offset[0] > self::MAX_OFFSET) && ($offset[0] > $offset[1])){
                $reverseOffset = TRUE;
                $sortOrder = 1;
                $skip = $offset[1];
            }
            $cursor = $this->oMongo->find('balances', $search, array('balance' => $sortOrder), $limit, $skip);
            if($cursor){
                $total = 0;
                $aBalances = [];
                foreach($cursor as $balance){
                    $aBalances[] = $balance;
                }
                foreach($aBalances as $balance){
                    $total += floatval($balance['balance']);
                }
                if($total > 0){
                    if(isset($token['totalSupply']) && ($total < $token['totalSupply'])){
                        $total = $token['totalSupply'];
                    }
                    foreach($aBalances as $balance){
                        $result[] = array(
                            'address' => $balance['address'],
                            'balance' => floatval($balance['balance']),
                            'share' => round((floatval($balance['balance']) / $total) * 100, 2)
                        );
                    }
                    if($reverseOffset){
                        $result = array_reverse($result);
                    }
                }
            }
        }
        evxProfiler::checkpoint('getTokenHolders', 'FINISH');
        return $result;
    }

    /**
     * Get data from token cache and sores it as a single record
     *
     * @param string $address
     * @return array
     */
    protected function getTokenByAddress($address, $noCache = false){
        $result = false;
        if($address){
            if($noCache){
                $aTokens = $this->getTokens();
                $result = isset($aTokens[$address]) ? $aTokens[$address] : false;
            }else{
                $cache = 'tokend-' . $address;
                $result = $this->oCache->get($cache, false, true, 600);
                if(false === $result){
                    $aTokens = $this->getTokens();
                    $result = isset($aTokens[$address]) ? $aTokens[$address] : "notfound";
                    if(is_array($result) && isset($result["_id"])){
                        unset($result["_id"]);
                    }
                    $this->oCache->save($cache, $result);
                }
            }
        }
        return is_array($result) && !empty($result) ? $result : false;
    }

    /**
     * Returns token data by contract address.
     *
     * @param string  $address  Token contract address
     * @return array
     */
    public function getToken($address, $fast = FALSE){
        // evxProfiler::checkpoint('getToken', 'START', 'address=' . $address);
        $cache = 'token-' . $address;
        if($fast){
            $result = $this->getTokenByAddress($address, true);
        }else{
            $result = $this->oCache->get($cache, false, true, 30);
            if(false === $result){
                $result = $this->getTokenByAddress($address);
                if(is_array($result)){
                    $result += array('txsCount' => 0, 'transfersCount' => 0, 'ethTransfersCount' => 0, 'issuancesCount' => 0, 'holdersCount' => 0, "symbol" => "");
                    if(!isset($result['decimals']) || !intval($result['decimals'])){
                        $result['decimals'] = 0;
                        if(isset($result['totalSupply']) && ((float)$result['totalSupply'] > 1e+18)){
                            $result['decimals'] = 18;
                            $result['estimatedDecimals'] = true;
                        }
                    }

                    // Ask DB for fresh counts
                    $cursor = $this->oMongo->find('tokens', array('address' => $address), array(), false, false, array('txsCount', 'transfersCount'));
                    $token = false;
                    if($cursor){
                        foreach($cursor as $token){
                            break;
                        }
                    }
                    if($token){
                        $result['txsCount'] = $token['txsCount'];
                        $result['transfersCount'] = $token['transfersCount'];
                    }

                    $result['txsCount'] = (int)$result['txsCount'] + 1; // Contract creation tx

                    if(isset($this->aSettings['client']) && isset($this->aSettings['client']['tokens'])){
                        $aClientTokens = $this->aSettings['client']['tokens'];
                        if(isset($aClientTokens[$address])){
                            $aClientToken = $aClientTokens[$address];
                            if(isset($aClientToken['name'])){
                                $result['name'] = $aClientToken['name'];
                            }
                            if(isset($aClientToken['symbol'])){
                                $result['symbol'] = $aClientToken['symbol'];
                            }
                        }
                    }
                    $this->oCache->save($cache, $result);
                }
            }
        }
        if(is_array($result)){
            unset($result["_id"]);
            $price = $this->getTokenPrice($address);
            if(is_array($price)){
                $price['currency'] = 'USD';
            }
            $result['price'] = $price ? $price : false;
        }
        // evxProfiler::checkpoint('getToken', 'FINISH');
        return $result;
    }

    /**
     * Returns contract data by contract address.
     *
     * @param string $address
     * @return array
     */
    public function getContract($address, $calculateTransactions = TRUE){
        evxProfiler::checkpoint('getContract', 'START', 'address=' . $address);
        $cursor = $this->oMongo->find('contracts', array("address" => $address));
        $result = false;
        foreach($cursor as $result) break;
        if($result && $calculateTransactions){
            unset($result["_id"]);
            unset($result["code"]);
            if($calculateTransactions){
                evxProfiler::checkpoint('getContract CalculateTransactions', 'START', 'address=' . $address);
                $cache = 'contractTransactionsCount-' . $address;
                $count = $this->oCache->get($cache, false, true, 600);
                if(FALSE === $count){
                    $token = false;
                    if($token = $this->getToken($address)){
                        $count = isset($token['txsCount']) ? $token['txsCount'] : 0;
                    }
                    if(!$token || !$count){
                        $count = $this->countTransactions($address);
                    }
                    $this->oCache->save($cache, $count);
                }
                $result['txsCount'] =  $count;
                evxProfiler::checkpoint('getContract CalculateTransactions', 'FINISH', $count . ' transactions');
            }
            if($this->isChainyAddress($address)){
                $result['isChainy'] = true;
            }
        }
        evxProfiler::checkpoint('getContract', 'FINISH');
        return $result;
    }

    /**
     * Returns total number of token transfers for the address.
     *
     * @param string $address  Contract address
     * @return int
     */
    public function countOperations($address, $useFilter = TRUE, $showTx = self::SHOW_TX_ALL){        
        evxProfiler::checkpoint('countOperations', 'START', 'address=' . $address . ', useFilter = ' . ($useFilter ? 'ON' : 'OFF'));
        $cache = 'countOperations-' . $address . '-' . $showTx;
        $result = $this->oCache->get($cache, false, true, 30);
        if(FALSE === $result){
            $result = 0;
            if($token = $this->getToken($address)){
                $result = $this->getContractOperationCount('transfer', $address, $useFilter);
            }else{
                $cursor = $this->oMongo->find('addressCache', array("address" => $address));
                $aCachedData = false;
                foreach($cursor as $aCachedData) break;                
                if(false !== $aCachedData){
                    evxProfiler::checkpoint('countTransfersFromCache', 'START', 'address=' . $address);
                    $result = $aCachedData['transfersCount'];
                    if($showTx == self::SHOW_TX_ALL) $result += $aCachedData['ethTransfersCount'];
                    else if($showTx == self::SHOW_TX_ETH) $result = $aCachedData['ethTransfersCount'];
                    evxProfiler::checkpoint('countTransfersFromCache', 'FINISH', 'count=' . $result);
                }else{
                    $aSearchFields = array('from', 'to', 'address');
                    foreach($aSearchFields as $searchField){
                        $search = array($searchField => $address);
                        if($showTx == self::SHOW_TX_ETH){
                            $search['isEth'] = true;
                        }else if($showTx == self::SHOW_TX_TOKENS){
                            $search['isEth'] = false;
                        }
                        if($useFilter && $this->filter){
                            $search = array(
                                '$and' => array(
                                    $search,
                                    array(
                                        '$or' => array(
                                            array('from'                => array('$regex' => $this->filter)),
                                            array('to'                  => array('$regex' => $this->filter)),
                                            array('address'             => array('$regex' => $this->filter)),
                                            array('transactionHash'     => array('$regex' => $this->filter)),
                                        )
                                    )
                                )
                            );
                        }
                        $result += (int)$this->oMongo->count('operations', $search);

                        $search['type'] = array('$eq' => array('approve'));
                        $approves = (int)$this->oMongo->count('operations', $search);
                        if($approves){
                            $result -= $approves;
                        }
                    }
                }
            }
            $this->oCache->save($cache, $result);
        }
        evxProfiler::checkpoint('countOperations', 'FINISH');
        return $result;
    }

    public function isHighloadedAddress($address){
        $cache = 'highloaded-address-' . $address;
        $result = $this->oCache->get($cache, false, true);
        if(FALSE === $result){
            $count = $this->countTransactions($address, 1000);
            if($count >= 1000){
                $this->oCache->save($cache, true);
                return true;
            }
            $opCount = $this->countOperations($address, FALSE, self::SHOW_TX_TOKENS);
            if($opCount >= 1000){
                $this->oCache->save($cache, true);
                return true;
            }
        }
        return $result;
    }

    /**
     * Returns total number of transactions for the address (incoming, outoming, contract creation).
     *
     * @param string $address  Contract address
     * @return int
     */
    public function countTransactions($address, $limit = FALSE){
        $cache = 'address-' . $address . '-txcnt';
        $result = $this->oCache->get($cache, false, true, 30);
        if(FALSE === $result){
            evxProfiler::checkpoint('countTransactions', 'START', 'address=' . $address);
            $result = 0;
            if($token = $this->getToken($address)){
                $result = $token['txsCount'];
                $result++; // One for contract creation
            } else { 
                $cursor = $this->oMongo->find('addressCache', array("address" => $address));
                $aCachedData = false;
                foreach($cursor as $aCachedData) break;
                if(false !== $aCachedData){
                    evxProfiler::checkpoint('countTransactionsFromCache', 'START', 'address=' . $address);
                    $result = $aCachedData['txsCount'];
                    evxProfiler::checkpoint('countTransactionsFromCache', 'FINISH', 'count=' . $result);
                }else{
                    foreach(array('from', 'to') as $where){
                        $search = array($where => $address);
                        $result += (int)$this->oMongo->count('transactions', $search, $limit);
                    }
                }
            }
            $this->oCache->save($cache, $result);
            evxProfiler::checkpoint('countTransactions', 'FINISH', $result . ' transactions');
        }
        return $result;
    }

    /**
     * Returns list of contract transfers.
     *
     * @param string $address  Contract address
     * @param int $limit       Maximum number of records
     * @return array
     */
    public function getContractTransfers($address, $limit = 10, $offset = FALSE){
        return $this->getContractOperation('transfer', $address, $limit, $offset);
    }

    /**
     * Returns list of contract issuances.
     *
     * @param string $address  Contract address
     * @param int $limit       Maximum number of records
     * @return array
     */
    public function getContractIssuances($address, $limit = 10, $offset = FALSE){
        return $this->getContractOperation(array('$in' => array('issuance', 'burn', 'mint')), $address, $limit, $offset);
    }

    /**
     * Returns last known mined block number.
     *
     * @return int
     */
    public function getLastBlock($updateCache = FALSE){
        evxProfiler::checkpoint('getLastBlock', 'START');
        $lastblock = $this->oCache->get('lastBlock', false, true, 300);
        if($updateCache || (false === $lastblock)){
            $cursor = $this->oMongo->find('blocks', array(), array('number' => -1), 1, false, array('number'));
            $block = false;
            foreach($cursor as $block) break;            
            $lastblock = $block && isset($block['number']) ? $block['number'] : false;
            $this->oCache->save('lastBlock', $lastblock);
        }
        evxProfiler::checkpoint('getLastBlock', 'FINISH');
        return $lastblock;
    }

    /**
     * Returns address token balances.
     *
     * @param string $address  Address
     * @param bool $withZero   Returns zero balances if true
     * @return array
     */
    public function getAddressBalances($address, $withZero = TRUE, $withEth = FALSE){
        evxProfiler::checkpoint('getAddressBalances', 'START', 'address=' . $address);
        $cache = 'getAddressBalances-' . $address . ($withEth ? '-eth' : '');
        $result = $this->oCache->get($cache, false, true, 60);
        if(FALSE === $result){
            $search = array("address" => $address);
            if(!$withZero){
                $search['balance'] = array('$gt' => 0);
            }
            // $search['totalIn'] = array('$gt' => 0);
            $cursor = $this->oMongo->find('balances', $search, array(), false, false, array('contract', 'balance', 'totalIn', 'totalOut'));
            $result = array();
            foreach($cursor as $balance){
                unset($balance["_id"]);
                $result[] = $balance;
            }
            if($withEth){
                $result[] = array(
                    'contract' => self::ADDRESS_ETH,
                    'balance' => $this->getBalance($address),
                    'totalIn' => 0,
                    'totalOut' => 0
                );
            }
            $this->oCache->save($cache, $result);
        }
        evxProfiler::checkpoint('getAddressBalances', 'FINISH');
        return $result;
    }

    /**
     * Returns list of transfers made by specified address.
     *
     * @param string $address  Address
     * @param int $limit       Maximum number of records
     * @return array
     */
    public function getLastTransfers(array $options = array(), $showEth = FALSE){
        $search = array();
        if(isset($options['address']) && !isset($options['history'])){
            $search['contract'] = $options['address'];
        }
        if(isset($options['address']) && isset($options['history'])){
            $search['addresses'] = $options['address'];
        }
        if(isset($options['token']) && isset($options['history'])){
            $search['contract'] = $options['token'];
        }
        if(!$showEth && !isset($search['contract'])){
            $search['isEth'] = false;
        }
        if(!isset($options['type'])){
            $search['type'] = 'transfer';
        }else{
            if(FALSE !== $options['type']){
                $search['type'] = $options['type'];
            }
        }
        $sort = array("timestamp" => -1);

        if(isset($options['timestamp']) && ($options['timestamp'] > 0)){
            $search['timestamp'] = array('$lte' => $options['timestamp']);
        }

        $limit = isset($options['limit']) ? (int)$options['limit'] : false;
        $cursor = $this->oMongo->find('operations2', $search, $sort, $limit);

        $result = array();
        $aTokens = array();
        foreach($cursor as $transfer){
            if(!$transfer['isEth']){
                if(!isset($aTokens[$transfer['contract']])){
                    $aTokens[$transfer['contract']] = $this->getToken($transfer['contract'], true);
                }
                $transfer['token'] = $aTokens[$transfer['contract']];
            }
            unset($transfer["_id"]);
            $result[] = $transfer;
        }
        return $result;
    }

    /**
     * Returns list of transfers made by specified address.
     *
     * @param string $address  Address
     * @param int $limit       Maximum number of records
     * @return array
     */
    public function getAddressOperations($address, $limit = 10, $offset = FALSE, array $aTypes = NULL, $showTx = self::SHOW_TX_ALL){
        evxProfiler::checkpoint('getAddressOperations', 'START', 'address=' . $address . ', limit=' . $limit . ', offset=' . (is_array($offset) ? print_r($offset, TRUE) : (int)$offset));

        $result = array();
        $search = array('addresses' => $address);
        if($aTypes && is_array($aTypes) && count($aTypes)){
            if(1 == count($aTypes)){
                $search['type'] = $aTypes[0];
            }else{
                $search['type'] = array('$in' => $aTypes);
            }
        }
        if($showTx == self::SHOW_TX_ETH){
            $search['isEth'] = true;
        }else if($showTx == self::SHOW_TX_TOKENS){
            $search['isEth'] = false;
        }

        // @todo: remove $or, use special field with from-to-address-txHash concatination maybe
        if($this->filter){
            $search = array(
                '$and' => array(
                    $search,
                    array(
                        '$or' => array(
                            array('addresses'           => array('$regex' => $this->filter)),
                            array('transactionHash'     => array('$regex' => $this->filter)),
                        )
                    )
                )
            );
        }
        $reverseOffset = FALSE;
        $skip = is_array($offset) ? $offset[0] : $offset;
        $sortOrder = -1;
        if(is_array($offset) && ($offset[0] > self::MAX_OFFSET) && ($offset[0] > $offset[1])){
            $reverseOffset = TRUE;
            $sortOrder = 1;
            $skip = $offset[1];
        }
        if($showTx == self::SHOW_TX_ETH || $showTx == self::SHOW_TX_TOKENS){
            $hint = 'addresses_1_isEth_1_timestamp_1';
            $cursor = $this->oMongo->find('operations2', $search, array("timestamp" => $sortOrder), $limit, $skip, false, $hint);
        }else{
            $cursor = $this->oMongo->find('operations2', $search, array("timestamp" => $sortOrder), $limit, $skip);
        }

        foreach($cursor as $transfer){
            if(is_null($aTypes) || in_array($transfer['type'], $aTypes)){
                unset($transfer["_id"]);
                $result[] = $transfer;
            }
        }
        if($reverseOffset){
            $result = array_reverse($result);
        }
        evxProfiler::checkpoint('getAddressOperations', 'FINISH');
        return $result;
    }

    /**
     * Returns data of operations made by specified address for downloading in CSV format.
     *
     * @param string $address  Address
     * @param string $type     Operations type
     * @return array
     */
    public function getAddressOperationsCSV($address, $type = 'transfer'){
        $limit = 1000;

        $cache = 'address_operations_csv-' . $address . '-' . $limit . '-' . $this->showTx;
        $result = $this->oCache->get($cache, false, true, 600);
        if(FALSE === $result){
            $cr = "\r\n";
            $spl = ";";
            $result = 'date;txhash;from;to;token-name;token-address;value;usdPrice;symbol' . $cr;

            $options = array(
                'address' => $address,
                'type' => $type,
                'limit' => $limit
            );
            $aTokens = array();
            $addTokenInfo = true;
            $isContract = $this->getContract($address);
            if($isContract){
                $addTokenInfo = false;
            }
            $ten = Decimal::create(10);
            $dec = false;
            $tokenName = '';
            $tokenSymbol = '';
            $isToken = $this->getToken($address);
            if($isToken){
                $operations = $this->getLastTransfers($options);
                $dec = Decimal::create($isToken['decimals']);
                $tokenName = isset($isToken['name']) ? $isToken['name'] : '';
                $tokenSymbol = isset($isToken['symbol']) ? $isToken['symbol'] : '';
            }else{
                $operations = $this->getAddressOperations($address, $limit, FALSE, array('transfer'), $this->showTx);
            }
            $aTokenInfo = array();
            foreach($operations as $record){
                $date = date("Y-m-d H:i:s", $record['timestamp']);
                $hash = $record['transactionHash'];
                $from = isset($record['from']) ? $record['from'] : '';
                $to = isset($record['to']) ? $record['to'] : '';
                $usdPrice = isset($record['usdPrice']) ? $record['usdPrice'] : '';
                $dec = false;
                $tokenAddress = '';
                if(isset($record['contract'])){
                    $tokenName = '';
                    $tokenSymbol = '';
                    $contract = $record['contract'];
                    if(isset($aTokenInfo[$contract])){
                        $token = $aTokenInfo[$contract];
                    }else{
                        if($contract == 'ETH'){
                            $token = $this->getEthToken();
                        }else{
                            $token = $this->getToken($contract, TRUE);
                        }
                    }
                    if($token){
                        $tokenName = isset($token['name']) ? $token['name'] : '';
                        $tokenSymbol = isset($token['symbol']) ? $token['symbol'] : '';
                        $tokenAddress = isset($token['address']) ? $token['address'] : '';
                        if(isset($token['decimals']) && ($contract != 'ETH')) $dec = Decimal::create($token['decimals']);
                        if(!isset($aTokenInfo[$contract])) $aTokenInfo[$contract] = $token;
                    }
                }
                $value = $record['value'];
                if($dec){
                    $value = Decimal::create($record['value']);
                    $value = $value->div($ten->pow($dec), 4);
                }
                $value = str_replace(".", ",", $value);
                $usdPrice = str_replace(".", ",", $usdPrice);
                $result .= $date . $spl . $hash . $spl . $from . $spl . $to . $spl . $tokenName . $spl . $tokenAddress . $spl . $value . $spl . $usdPrice . $spl . $tokenSymbol . $cr;
            }
            $this->oCache->save($cache, $result);
        }
        return $result;
    }

    /**
     * Returns top tokens list.
     *
     * @todo: count number of transactions with "transfer" operation
     * @param int $limit         Maximum records number
     * @param int $period        Days from now
     * @param bool $updateCache  Force unexpired cache update
     * @return array
     */
    public function getTopTokens($limit = 10, $period = 30, $updateCache = FALSE, $showEth = FALSE){
        $cache = 'top_tokens-' . $period . '-' . $limit . ($showEth ? '-eth' : '');
        $result = $this->oCache->get($cache, false, true, 24 * 3600);
        if($updateCache || (FALSE === $result)){
            $result = array();
            $aMatch = array("timestamp" => array('$gt' => time() - $period * 2 * 24 * 3600, '$lte' => time() - $period * 24 * 3600));
            if(!$showEth){
                if($this->useOperations2){
                    $aMatch['isEth'] = false;
                }else{
                    $aMatch['contract'] = array('$ne' => 'ETH');
                }
            }
            $prevData = $this->oMongo->aggregate(
                'operations',
                array(
                    array('$match' => $aMatch),
                    array(
                        '$group' => array(
                            "_id" => '$contract',
                            'cnt' => array('$sum' => 1)
                        )
                    ),
                    array('$sort' => array('cnt' => -1)),
                    array('$limit' => $limit)
                )
            );
            $aMatch['timestamp'] = array('$gt' => time() - $period * 24 * 3600);
            $dbData = $this->oMongo->aggregate(
                'operations',
                array(
                    array('$match' => $aMatch),
                    array(
                        '$group' => array(
                            "_id" => '$contract',
                            'cnt' => array('$sum' => 1)
                        )
                    ),
                    array('$sort' => array('cnt' => -1)),
                    array('$limit' => $limit)
                )
            );
            if(is_array($dbData) && !empty($dbData['result'])){
                foreach($dbData['result'] as $token){
                    $oToken = $this->getToken($token['_id']);
                    $oToken['opCount'] = $token['cnt'];
                    unset($oToken['checked']);
                    $result[] = $oToken;
                }
                $this->oCache->save($cache, $result);
            }
        }
        return $result;
    }

    /**
     * Returns top tokens list (new).
     *
     * @param int $limit         Maximum records number
     * @param string $criteria   Sort criteria
     * @param bool $updateCache  Force unexpired cache update
     * @return array
     */
    public function getTokensTop($limit = 50, $criteria = 'trade', $updateCache = false){
        $aSkippedTokens = array('0x86fa049857e0209aa7d9e616f7eb3b3b78ecfdb0');
        $topLimit = 100;
        if($criteria != 'count'){
            $topLimit++;
            $limit++;
        }
        if($limit > $topLimit) $limit = $topLimit;
        $cache = 'top_tokens_' . $criteria;
        $aTotals = array(
            'tokens' => 0,
            'tokensWithPrice' => 0,
            'cap' => 0,
            'capPrevious' => 0,
            'volume24h' => 0,
            'volumePrevious' => 0
        );
        $result = $this->oCache->get($cache, false, true);
        if($updateCache){
            $aTokens = $this->getTokens();
            $result = array();
            $total = 0;
            $aPeriods = array(
                array('period' => 1),
                array('period' => 7),
                array('period' => 30)
            );
            foreach($aPeriods as $idx => $aPeriod){
                $period = $aPeriod['period'];
                $aPeriods[$idx]['currentPeriodStart'] = date("Y-m-d", time() - $period * 24 * 3600);
                $aPeriods[$idx]['previousPeriodStart'] = date("Y-m-d", time() - $period * 48 * 3600);
            }

            $aTokensCount = array();
            if($criteria == 'count'){
                $aTokensCountRes = $this->getTokensCountForLastDay($topLimit);
                foreach($aTokensCountRes as $aTokensCountRecord){
                    if(isset($aTokensCountRecord['_id'])) $aTokensCount[$aTokensCountRecord['_id']] = $aTokensCountRecord['cnt'];
                }
            }

            $aTokens[] = array(
                'address' => self::ADDRESS_ETH,
                'name' => 'Ethereum',
                'symbol' => 'ETH'
            );

            foreach($aTokens as $aToken){
                $address = $aToken['address'];
                if(in_array($address, $aSkippedTokens)) continue;
                $curHour = (int)date('H');

                $isEth = false;
                if($address == self::ADDRESS_ETH){
                    $isEth = true;
                }else{
                    $aTotals['tokens'] += 1;
                }

                if(!$isEth && $criteria == 'count'){
                    if(isset($aTokensCount[$address])){
                        $aToken['txsCount24'] = $aTokensCount[$address];
                        foreach($aPeriods as $aPeriod){
                            $period = $aPeriod['period'];
                            $aToken['txsCount-' . $period . 'd-current'] = 0;
                            $aToken['txsCount-' . $period . 'd-previous'] = 0;
                        }
                        //get tx's 1d trends
                        $aHistoryCount = $this->getTokenHistoryGrouped(2, $address, 'hourly', 3600);
                        if(is_array($aHistoryCount)){
                            foreach($aHistoryCount as $aRecord){
                                $aRec = $aRecord['_id'];
                                if(is_object($aRecord['_id'])){
                                    $aRec = json_decode(json_encode($aRecord['_id']), JSON_OBJECT_AS_ARRAY);
                                }
                                if(!is_array($aRec)){
                                    continue;
                                }
                                $aPeriod = $aPeriods[0];
                                $aRecordDate = date("Y-m-d", $aRecord['ts']);
                                $inCurrentPeriod = ($aRecordDate > $aPeriod['currentPeriodStart']) || (($aRecordDate == $aPeriod['currentPeriodStart']) && ($aRec['hour'] >= $curHour));
                                $inPreviousPeriod = !$inCurrentPeriod && (($aRecordDate > $aPeriod['previousPeriodStart']) || (($aRecordDate == $aPeriod['previousPeriodStart']) && ($aRec['hour'] >= $curHour)));
                                if($inCurrentPeriod){
                                    $aToken['txsCount-1d-current'] += $aRecord['cnt'];
                                }else if($inPreviousPeriod){
                                    $aToken['txsCount-1d-previous'] += $aRecord['cnt'];
                                }
                            }
                        }
                        if(!$aToken['name']) $aToken['name'] = 'N/A';
                        if(!$aToken['symbol']) $aToken['symbol'] = 'N/A';
                        $result[] = $aToken;
                    }
                }

                if($isEth){
                    $aPrice = $this->getETHPrice();
                }else{
                    $aPrice = $this->getTokenPrice($address);
                }
                //if($aPrice && ($isEth || $aToken['totalSupply'])){
                if($isEth || ($aPrice && isset($aPrice['rate']))){
                    if(!$isEth) $aTotals['tokensWithPrice'] += 1;
                    if(!$isEth && isset($aPrice['marketCapUsd'])){
                        $aTotals['cap'] += $aPrice['marketCapUsd'];
                    }
                    if(!$isEth && isset($aPrice['volume24h'])){
                        $aTotals['volume24h'] += $aPrice['volume24h'];
                    }
                    //if($criteria == 'count') continue;

                    $aToken['volume'] = 0;
                    $aToken['cap'] = 0;
                    $aToken['availableSupply'] = 0;
                    $aToken['price'] = $aPrice;
                    if(isset($aPrice['marketCapUsd'])){
                        $aToken['cap'] = $aPrice['marketCapUsd'];
                    }
                    if(isset($aPrice['availableSupply'])){
                        $aToken['availableSupply'] = $aPrice['availableSupply'];
                    }
                    foreach($aPeriods as $aPeriod){
                        $period = $aPeriod['period'];
                        $aToken['volume-' . $period . 'd-current'] = 0;
                        $aToken['volume-' . $period . 'd-previous'] = 0;
                        $aToken['cap-' . $period . 'd-current'] = $aPrice['rate'];
                        $aToken['cap-' . $period . 'd-previous'] = 0;
                        $aToken['cap-' . $period . 'd-previous-ts'] = 0;
                    }
                    $aHistory = $this->getTokenPriceHistory($address, 60, 'hourly');
                    if(is_array($aHistory)){
                        //$previousTokenCapAdded = false;
                        $prevDayCap = null;
                        $prevPrevDayCap = null;
                        foreach($aHistory as $aRecord){
                            foreach($aPeriods as $aPeriod){
                                $period = $aPeriod['period'];
                                $inCurrentPeriod = ($aRecord['date'] > $aPeriod['currentPeriodStart']) || (($aRecord['date'] == $aPeriod['currentPeriodStart']) && ($aRecord['hour'] >= $curHour ));
                                $inPreviousPeriod = !$inCurrentPeriod && (($aRecord['date'] > $aPeriod['previousPeriodStart']) || (($aRecord['date'] == $aPeriod['previousPeriodStart']) && ($aRecord['hour'] >= $curHour )));
                                if($inCurrentPeriod){
                                    $aToken['volume-' . $period . 'd-current'] += $aRecord['volumeConverted'];
                                    if(1 == $period){
                                        $aToken['volume'] += $aRecord['volumeConverted'];
                                    }
                                }else if($inPreviousPeriod){
                                    $aToken['volume-' . $period . 'd-previous'] += $aRecord['volumeConverted'];

                                    // if no data from coinmarketcap
                                    if(!$aToken['cap-' . $period . 'd-previous-ts'] || $aToken['cap-' . $period . 'd-previous-ts'] < $aRecord['ts']){
                                        if($aRecord['volumeConverted'] > 0 && $aRecord['volume'] > 0){
                                            $aToken['cap-' . $period . 'd-previous-ts'] = $aRecord['ts'];
                                            $aToken['cap-' . $period . 'd-previous'] = $aRecord['volumeConverted'] / $aRecord['volume'];
                                        }
                                    }
                                }

                                // get total cap for previous day
                                /*$prevCapPeriod = ($aRecord['date'] == $aPeriod['currentPeriodStart']);
                                if(!$isEth && $prevCapPeriod && (1 == $period) && !$previousTokenCapAdded && isset($aRecord['cap'])){
                                    $aTotals['capPrevious'] += $aRecord['cap'];
                                    $previousTokenCapAdded = true;
                                }*/
                                if(!$isEth && (1 == $period)){
                                    if(($aRecord['date'] == $aPeriod['currentPeriodStart']) && isset($aRecord['cap'])) $prevDayCap = $aRecord['cap'];
                                    if(($aRecord['date'] == $aPeriod['previousPeriodStart']) && isset($aRecord['cap'])) $prevPrevDayCap = $aRecord['cap'];
                                }
                            }
                        }

                        // get total cap for previous day
                        if(!$isEth){
                            if($prevDayCap) $aTotals['capPrevious'] += $prevDayCap;
                            else if($prevPrevDayCap) $aTotals['capPrevious'] += $prevPrevDayCap;
                        }

                        // get total volume for previous day
                        if(!$isEth){
                            $aTotals['volumePrevious'] += $aToken['volume-1d-previous'];
                        }
                    }
                    if(isset($aPrice['volume24h']) && $aPrice['volume24h'] > 0){
                        $aToken['volume'] = $aToken['volume-1d-current'] = $aPrice['volume24h'];
                    }
                    if($criteria != 'count') $result[] = $aToken;
                }
            }
            $sortMethod = '_sortByVolume';
            if($criteria == 'cap') $sortMethod = '_sortByCap';
            if($criteria == 'count') $sortMethod = '_sortByTxCount';
            usort($result, array($this, $sortMethod));

            $aPrevTotals = $this->oCache->get('top_tokens_totals', FALSE, TRUE);
            $prevTokensNum = 0;
            if(FALSE !== $aPrevTotals){
                $prevTokensNum = $aPrevTotals['tokensWithPrice'];
            }
            if($aTotals['tokensWithPrice'] < $prevTokensNum) $aTotals['tokensWithPrice'] = $prevTokensNum;
            if(($criteria != 'count') && ($aTotals['tokensWithPrice'] > $topLimit)){
                $tokensLimit = $aTotals['tokensWithPrice'];
            }else{
                $tokensLimit = $topLimit;
            }

            $res = [];
            foreach($result as $i => $item){
                if($i < $tokensLimit){
                    // get tx's other trends
                    if(($item['address'] != self::ADDRESS_ETH) && $criteria == 'count'){
                        unset($aPeriods[0]);
                        $aHistoryCount = $this->getTokenHistoryGrouped(60, $item['address'], 'daily', 3600);
                        if(is_array($aHistoryCount)){
                            foreach($aHistoryCount as $aRecord){
                                foreach($aPeriods as $aPeriod){
                                    $period = $aPeriod['period'];
                                    $aRecordDate = date("Y-m-d", $aRecord['ts']);
                                    $inCurrentPeriod = ($aRecordDate >= $aPeriod['currentPeriodStart']);
                                    $inPreviousPeriod = !$inCurrentPeriod && ($aRecordDate >= $aPeriod['previousPeriodStart']);
                                    if($inCurrentPeriod){
                                        $item['txsCount-' . $period . 'd-current'] += $aRecord['cnt'];
                                    }else if($inPreviousPeriod){
                                        $item['txsCount-' . $period . 'd-previous'] += $aRecord['cnt'];
                                    }
                                }
                            }
                        }
                    }

                    $res[] = $item;
                }
            }

            $aTotals['ts'] = time();
            $result = array('tokens' => $res, 'totals' => $aTotals);
            $this->oCache->save($cache, $result);
            $this->oCache->save('top_tokens_totals', $aTotals);
        }
        if(FALSE === $result){
            $result = array('tokens' => array());
        }

        $res = [];
        if($limit > 0 && $limit < $topLimit){
            foreach($result['tokens'] as $i => $item){
                if($i < $limit){
                    $res[] = $item;
                }else{
                    break;
                }
            }
            $result['tokens'] = $res;
        }
        return $result;
    }

    /**
     * Returns top tokens totals.
     *
     * @return array
     */
    public function getTokensTopTotals(){
        $result = $this->oCache->get('top_tokens_totals', FALSE, TRUE);
        if(FALSE === $result){
            return array();
        }
        return $result;
    }

    /**
     * Returns top tokens list by current volume.
     *
     * @param int $limit   Maximum records number
     * @param int $period        Days from now
     * @param bool $updateCache  Force unexpired cache update
     * @return array
     */
    public function getTopTokensByPeriodVolume($limit = 10, $period = 30, $updateCache = false){
        set_time_limit(0);
        $cache = 'top_tokens-by-period-volume-' . $limit . '-' . $period;
        $result = $this->oCache->get($cache, false, true, 3600);
        $today = date("Y-m-d");
        $firstDay = date("Y-m-d", time() - $period * 24 * 3600);
        if($updateCache || (FALSE === $result)){
            $aTokens = $this->getTokens();
            $result = array();
            $total = 0;
            foreach($aTokens as $aToken){
                $address = $aToken['address'];
                $aPrice = $this->getTokenPrice($address);
                if($aPrice && $aToken['totalSupply']){
                    $aToken['volume'] = 0;
                    $aToken['previousPeriodVolume'] = 0;
                    $aHistory = $this->getTokenPriceHistory($address, $period * 2, 'daily');
                    if(is_array($aHistory)){
                        foreach($aHistory as $aRecord){
                            $aToken[($aRecord['date'] >= $firstDay) ? 'volume' : 'previousPeriodVolume'] += $aRecord['volumeConverted'];
                        }
                    }
                    $total += $aToken['volume'];
                    $result[] = $aToken;
                }
                usort($result, array($this, '_sortByVolume'));

                $res = [];
                foreach($result as $i => $item){
                    if($i < $limit){
                        $item['percentage'] = round(($item['volume'] / $total) * 100);
                        $res[] = $item;
                    }
                }
                $result = $res;
            }
            $this->oCache->save($cache, $result);
        }
        return $result;
    }

    /**
     * Returns top tokens list by current volume.
     *
     * @todo: count number of transactions with "transfer" operation
     * @param int $limit   Maximum records number
     * @return array
     */
    public function getTopTokensByCurrentVolume($limit = 10){
        $cache = 'top_tokens-by-current-volume-' . $limit;
        $result = $this->oCache->get($cache, false, true, 600);
        if(FALSE === $result){
            $aTokens = $this->getTokens();
            $result = array();
            foreach($aTokens as $aToken){
                $aPrice = $this->getTokenPrice($aToken['address']);
                if($aPrice && $aToken['totalSupply']){
                    // @todo: volume != totalSuply, volume is circulated supply (@see coinmarketcap)
                    $aToken['volume'] = $aPrice['rate'] * $aToken['totalSupply'] / pow(10, $aToken['decimals']);
                    $result[] = $aToken;
                }
                usort($result, array($this, '_sortByVolume'));
                $res = [];
                foreach($result as $i => $item){
                    if($i < $limit){
                        $res[] = $item;
                    }
                }
                $result = $res;
            }
            $this->oCache->save($cache, $result);
        }
        return $result;
    }

    protected function _sortByVolume($a, $b){
        return ($a['volume'] == $b['volume']) ? 0 : (($a['volume'] > $b['volume']) ? -1 : 1);
    }

    protected function _sortByCap($a, $b){
        return ($a['cap'] == $b['cap']) ? 0 : (($a['cap'] > $b['cap']) ? -1 : 1);
    }

    protected function _sortByTxCount($a, $b){
        if(!isset($a['txsCount24']) || !isset($b['txsCount24'])) return 1;
        return ($a['txsCount24'] == $b['txsCount24']) ? 0 : (($a['txsCount24'] > $b['txsCount24']) ? -1 : 1);
    }

    /**
     * Returns transactions grouped by days.
     *
     * @param int $period      Days from now
     * @param string $address  Address
     * @return array
     */
    public function getTokenHistoryGrouped($period = 30, $address = FALSE, $type = 'daily', $cacheLifetime = 1800, $showEth = FALSE, $updateCache = FALSE){
        $cache = 'token_history_grouped-' . ($address ? ($address . '-') : '') . $period . (($type == 'hourly') ? '-hourly' : '') . ($showEth ? '-eth' : '');
        $result = $address ? $this->oCache->get($cache, false, true, $cacheLifetime) : $this->oCache->get($cache, false, true);
         if(($address && FALSE === $result) || $updateCache){
            // Chainy
            if($address && ($address == self::ADDRESS_CHAINY)){
                return $this->getChainyTokenHistoryGrouped($period);
            }

            $tsStart = gmmktime(0, 0, 0, date('n'), date('j') - $period, date('Y'));
            $aMatch = array("timestamp" => array('$gt' => $tsStart));
            if($address) $aMatch["contract"] = $address;
            else if(!$showEth){
                if($this->useOperations2){
                    $aMatch['isEth'] = false;
                }else{
                    $aMatch["contract"] = array('$ne' => 'ETH');
                }
            }
            $result = array();
            $_id = array(
                "year"  => array('$year' => array('$add' => array($this->oMongo->toDate(0), array('$multiply' => array('$timestamp', 1000))))),
                "month"  => array('$month' => array('$add' => array($this->oMongo->toDate(0), array('$multiply' => array('$timestamp', 1000))))),
                "day"  => array('$dayOfMonth' => array('$add' => array($this->oMongo->toDate(0), array('$multiply' => array('$timestamp', 1000))))),
            );
            if($type == 'hourly'){
                $_id['hour'] = array('$hour' => array('$add' => array($this->oMongo->toDate(0), array('$multiply' => array('$timestamp', 1000)))));
            }
            $dbData = $this->oMongo->aggregate(
                'operations',
                array(
                    array('$match' => $aMatch),
                    array(
                        '$group' => array(
                            "_id" => $_id,
                            'ts' =>  array('$first' => '$timestamp'),
                            'cnt' => array('$sum' => 1)
                        )
                    ),
                    array('$sort' => array('ts' => -1)),
                    //array('$limit' => 10)
                )
            );
            if(is_array($dbData) && !empty($dbData['result'])){
                $result = $dbData['result'];
                $this->oCache->save($cache, $result);
            }
        }
        return $result;
    }

    /**
     * Returns transactions grouped by days for all period.
     *
     * @return array
     */
    public function getTokenFullHistoryGrouped($updateCache = FALSE){
        $tsNow = time();
        $tsStart = 1451606400; // 01.01.2016
        $tsEnd = 1459468800;

        $history = array();
        $numIter = 0;
        while($tsStart <= $tsNow){
            $numIter++;
            $cache = 'token_full_history_grouped-' . $tsEnd;
            $cacheLifetime = FALSE;
            if($tsEnd > $tsNow){
                $cacheLifetime = 24 * 60 * 60;
            }
            $result = $this->oCache->get($cache, FALSE, TRUE, $cacheLifetime);
            // Allow generating cache only from cron jobs
            if(FALSE === $result && $updateCache){
                $result = array();

                $aMatch = array("timestamp" => array('$gte' => $tsStart + 1, '$lte' => $tsEnd));
                $aMatch['isEth'] = false;
                $_id = array(
                    "year"  => array('$year' => array('$add' => array($this->oMongo->toDate(0), array('$multiply' => array('$timestamp', 1000))))),
                    "month"  => array('$month' => array('$add' => array($this->oMongo->toDate(0), array('$multiply' => array('$timestamp', 1000))))),
                    "day"  => array('$dayOfMonth' => array('$add' => array($this->oMongo->toDate(0), array('$multiply' => array('$timestamp', 1000))))),
                );
                $dbData = $this->oMongo->aggregate(
                    'operations',
                    array(
                        array('$match' => $aMatch),
                        array(
                            '$group' => array(
                                "_id" => $_id,
                                'ts' =>  array('$first' => '$timestamp'),
                                'cnt' => array('$sum' => 1)
                            )
                        ),
                        array('$sort' => array('ts' => -1))
                    )
                );
                if(is_array($dbData) && !empty($dbData['result'])){
                    $result = $dbData['result'];
                    $this->oCache->save($cache, $result, $cacheLifetime ? FALSE : TRUE);
                }
            }
            if(is_array($result) && sizeof($result)){
                $history = array_merge($history, $result);
            }
            $tsStart = $tsEnd;
            $tsEnd += 7776000;
        }
        //$history['numIter'] = $numIter;
        return array_values($history);
    }

    /**
     * Returns count transactions for last day grouped by tokens.
     *
     * @param int $limit  Number of tokens
     * @return array
     */
    public function getTokensCountForLastDay($limit = 30, $showEth = FALSE){
        $cache = 'tokens_count-' . $limit . ($showEth ? '-eth' : '');
        $result = $this->oCache->get($cache, false, true, 3600);
        if(FALSE === $result){
            $tsStart = gmmktime((int)date('G'), 0, 0, date('n'), date('j') - 1, date('Y'));
            $aMatch = array("timestamp" => array('$gte' => $tsStart));
            if(!$showEth){
                if($this->useOperations2){
                    $aMatch['isEth'] = false;
                }else{
                    $aMatch["contract"] = array('$ne' => 'ETH');
                }
            }
            $result = array();
            $dbData = $this->oMongo->aggregate(
                'operations',
                array(
                    array('$match' => $aMatch),
                    array(
                        '$group' => array(
                            "_id" => '$contract',
                            'cnt' => array('$sum' => 1)
                        )
                    ),
                    array('$sort' => array('cnt' => -1)),
                    array('$limit' => $limit)
                )
            );
            if(is_array($dbData) && !empty($dbData['result'])){
                $result = $dbData['result'];
                $this->oCache->save($cache, $result);
            }
        }
        return $result;
    }

    public function getAllowedAPICommands(array $commands){
        $result = array();
        if(!empty($this->aSettings['allowedMethods'])){
            foreach($this->aSettings['allowedMethods'] as $command){
                if(in_array($command, $commands)){
                    $result[] = $command;
                }
            }
        }else{
            $result = $commands;
        }
        return $result;
    }

    public function checkAPIKey($key){
        return isset($this->aSettings['apiKeys']) && isset($this->aSettings['apiKeys'][$key]);
    }

    public function isSuspendedAPIKey($key){
        return (isset($this->aSettings['apiKeys'][$key]['suspended']) && $this->aSettings['apiKeys'][$key]['suspended']);
    }
    
    public function getAPIKeyDefaults($key, $option = FALSE){
        $res = FALSE;
        if($this->checkAPIKey($key)){
            if(is_array($this->aSettings['apiKeys'][$key])){
                if(FALSE === $option){
                    $res = $this->aSettings['apiKeys'][$key];
                }else{
                    if(isset($this->aSettings['apiKeys'][$key][$option])){
                        $res = $this->aSettings['apiKeys'][$key][$option];
                    }

                    if($key != 'freekey' && isset($this->aSettings['personalLimits'])){
                        foreach($this->aSettings['personalLimits'] as $cmd => $aLimits){
                            if($cmd == $option){
                                foreach($aLimits as $opt => $limit){
                                    if(!isset($res[$opt]) || ($res[$opt] < $limit)){
                                        $res[$opt] = $limit;
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }
        return $res;
    }

    /**
     * Returns contract operation data.
     *
     * @param string $type     Operation type
     * @param string $address  Contract address
     * @return array
     */
    protected function getContractOperationCount($type, $address, $useFilter = TRUE, $countEth = FALSE){
        evxProfiler::checkpoint('getContractOperationCount', 'START', 'address=' . $address . ', type=' . (is_array($type) ? json_encode($type) : $type) . ', useFilter=' . (int)$useFilter . ', countEth=' . (int)$countEth);
        $search = array("contract" => $address, 'type' => $type);
        if($countEth){
            $search = array('addresses' => $address, 'type' => $type, 'isEth' => true);
        }
        $result = 0;
        if($useFilter && $this->filter){
            foreach(array('from', 'to', 'address', 'transactionHash') as $field){
                $result += (int)$this->oMongo->count('operations', array_merge($search, array($field => array('$regex' => $this->filter))));
            }
        }else{
            $aToken = $this->getToken($address);
            if(('transfer' === $type) && $aToken){
                if($countEth) $result = isset($aToken['ethTransfersCount']) ? $aToken['ethTransfersCount'] : 0;
                else $result = isset($aToken['transfersCount']) ? $aToken['transfersCount'] : 0;
            }else{
                if(!$countEth) $result = (int)$this->oMongo->count('operations', $search);
            }
            if($countEth && ('transfer' === $type) && $aToken && !isset($aToken['ethTransfersCount'])){
                $result = (int)$this->oMongo->count('operations', $search);
            }
        }
        evxProfiler::checkpoint('getContractOperationCount', 'FINISH');
        return $result;
    }

    /**
     * Returns contract operation data.
     *
     * @param string $type     Operation type
     * @param string $address  Contract address
     * @param string $limit    Maximum number of records
     * @return array
     */
    protected function getContractOperation($type, $address, $limit, $offset = FALSE){
        evxProfiler::checkpoint('getContractOperation', 'START', 'type=' . (is_array($type) ? json_encode($type) : $type) . ', address=' . $address . ', limit=' . $limit . ', offset=' . (is_array($offset) ? print_r($offset, TRUE) : (int)$offset));

        $search = array("contract" => $address, 'type' => $type);
        $fltSearch = array(
            array('from'                => array('$regex' => $this->filter)),
            array('to'                  => array('$regex' => $this->filter)),
            array('address'             => array('$regex' => $this->filter)),
            array('transactionHash'     => array('$regex' => $this->filter))
        );
        if($this->filter){
            $search['$or'] = $fltSearch;
        }

        if(($this->showTx == self::SHOW_TX_ETH || $this->showTx == self::SHOW_TX_ALL) && $type == 'transfer'){
            if($this->showTx == self::SHOW_TX_ETH){
                $search = $ethSearch = array('addresses' => $address, 'isEth' => TRUE);
            }else{
                $ethSearch = array(
                    array("contract" => $address, 'type' => $type),
                    array('addresses' => $address, 'isEth' => TRUE)
                );
                $search = array('$or' => $ethSearch);
            }

            if($this->filter){
                $search = array(
                    '$and' => array(
                        $this->showTx == self::SHOW_TX_ETH ? $ethSearch : array('$or' => $ethSearch),
                        array('$or' => $fltSearch)
                    )
                );
            }
        }

        $reverseOffset = FALSE;
        $skip = is_array($offset) ? $offset[0] : $offset;
        $sortOrder = -1;
        if(is_array($offset) && ($offset[0] > self::MAX_OFFSET) && ($offset[0] > $offset[1])){
            $reverseOffset = TRUE;
            $sortOrder = 1;
            $skip = $offset[1];
        }
        $cursor = $this->oMongo->find('operations', $search, array("timestamp" => $sortOrder), $limit, $skip);

        $result = array();
        $fetches = 0;
        foreach($cursor as $transfer){
            unset($transfer["_id"]);
            $result[] = $transfer;
            $fetches++;
        }
        if($reverseOffset){
            $result = array_reverse($result);
        }
        evxProfiler::checkpoint('getContractOperation', 'FINISH');
        return $result;
    }

    public function getAllHolders(){
        $result = array();
        $dbHolders = $this->oMongo->aggregate(
            'balances',
            array(
                array('$group' => array("_id" => '$address')),
                array('$sort' => array('ts' => -1))
            )
        );
        if(is_array($dbHolders) && !empty($dbHolders['result'])){
            $result = $dbHolders['result'];
        }
        return $result;
    }


    /**
     * Returns last Chainy transactions.
     *
     * @param  int $limit  Maximum number of records
     * @return array
     */
    protected function getChainyTransactions($limit = 10, $offset = FALSE){
        $result = array();
        $search = array('to' => self::ADDRESS_CHAINY, 'status' => array('$ne' => '0x0'));
        if($this->filter){
            $search = array(
                '$and' => array(
                    $search,
                    array('hash' => array('$regex' => $this->filter)),
                )
            );
        }
        $cursor = $this->oMongo->find('transactions', $search, array("timestamp" => -1), $limit, $offset);
        foreach($cursor as $tx){
            if(!empty($tx['receipt']['logs'])){
                $link = substr($tx['receipt']['logs'][0]['data'], 194);
                $link = preg_replace("/0+$/", "", $link);
                if((strlen($link) % 2) !== 0){
                    $link = $link . '0';
                }
                $result[] = array(
                    'hash' => $tx['hash'],
                    'timestamp' => $tx['timestamp'],
                    'input' => $tx['input'],
                    'link' => $link,
                );
            }
        }
        return $result;
    }

    /**
     * Returns Chainy transactions grouped by days.
     *
     * @param  int $period  Number of days
     * @return array
     */
    protected function getChainyTokenHistoryGrouped($period = 30){
        $result = array();
        $aMatch = array(
            "timestamp" => array('$gt' => time() - $period * 24 * 3600),
            "to" => self::ADDRESS_CHAINY,
            'status' => array('$ne' => '0x0')
        );
        $dbData = $this->oMongo->aggregate(
            'transactions',
            array(
                array('$match' => $aMatch),
                array(
                    '$group' => array(
                        "_id" => array(
                            "year"  => array('$year' => array('$add' => array($this->oMongo->toDate(0), array('$multiply' => array('$timestamp', 1000))))),
                            "month"  => array('$month' => array('$add' => array($this->oMongo->toDate(0), array('$multiply' => array('$timestamp', 1000))))),
                            "day"  => array( '$dayOfMonth' => array('$add' => array($this->oMongo->toDate(0), array('$multiply' => array('$timestamp', 1000))))),
                        ),
                        'ts' =>  array('$first' => '$timestamp'),
                        'cnt' => array('$sum' => 1)
                    )
                ),
                array('$sort' => array('ts' => -1))
            )
        );
        if(is_array($dbData) && !empty($dbData['result'])){
            $result = $dbData['result'];
        }
        return $result;
    }

    /**
     * Returns total number of Chainy operations for the address.
     *
     * @return int
     */
    public function countChainy($useFilter = TRUE){
        $search = array('to' => self::ADDRESS_CHAINY, 'status' => array('$ne' => '0x0'));
        if($useFilter && $this->filter){
            $search = array(
                '$and' => array(
                    $search,
                    array('hash' => array('$regex' => $this->filter)),
                )
            );
        }
        $result = (int)$this->oMongo->count('transactions', $search);
        return $result;
    }

    public function getETHPrice(){
        evxProfiler::checkpoint('getETHPrice', 'START');
        $result = false;
        $eth = $this->getTokenPrice('0x0000000000000000000000000000000000000000');
        if(false !== $eth){
            $result = $eth;
        }
        evxProfiler::checkpoint('getETHPrice', 'FINISH');
        return $result;
    }

    public function getBlockTransactions($block, $showZero = false){
        $cache = 'block-txs-' . $block . ($showZero ? '-zero' : '');
        $transactions = $this->oCache->get($cache, false, true);
        if(!$transactions){
            $transactions = array();
            $search = array('blockNumber' => $block);
            if(!$showZero){
                $search = array('$and' => array($search, array('value' => array('$gt' => 0))));
            }
            $cursor = $this->oMongo->find('transactions', $search, array("timestamp" => 1)/*, $limit*/);
            foreach($cursor as $tx){
                $receipt = isset($tx['receipt']) ? $tx['receipt'] : false;
                $tx['gasLimit'] = $tx['gas'];
                $tx['gasUsed'] = $receipt ? $receipt['gasUsed'] : 0;
                $transaction = array(
                    'timestamp' => $tx['timestamp'],
                    'from' => $tx['from'],
                    'to' => $tx['to'] ? $tx['to'] : "",
                    'hash' => $tx['hash'],
                    'value' => $tx['value'],
                    'success' => (($tx['gasUsed'] < $tx['gasLimit']) || ($receipt && !empty($receipt['logs'])))
                );
                if(isset($tx['creates']) && $tx['creates']){
                    $transaction['creates'] = $tx['creates'];
                }
                $transactions[] = $transaction;
            }
            $this->oCache->save($cache, $transactions);
        }
        return $transactions;
    }

    public function getTokenPrice30d($address){
        $result = FALSE;
        $aTokensTop = $this->getTokensTop(-1, 'cap');
        if(is_array($aTokensTop) && isset($aTokensTop['tokens'])){
            foreach($aTokensTop['tokens'] as $aToken){
                if(($aToken['address'] == $address) && isset($aToken['cap-30d-previous']) && $aToken['cap-30d-previous'] > 0){
                    $result = $aToken['cap-30d-previous'];
                    break;
                }
            }
        }
        return $result;
    }

    public function getTokenPrice($address, $updateCache = FALSE){
        // evxProfiler::checkpoint('getTokenPrice', 'START', 'address=' . $address . ', updateCache=' . ($updateCache ? 'TRUE' : 'FALSE'));
        $result = FALSE;
        // exclude eos
        if($address == '0x86fa049857e0209aa7d9e616f7eb3b3b78ecfdb0'){
            return $result;
        }
        if(!$updateCache && isset($this->aPrices[$address])){
            return $this->aPrices[$address];
        }
        if(isset($this->aSettings['priceSource']) && isset($this->aSettings['priceSource'][$address])){
            $address = $this->aSettings['priceSource'][$address];
        }
        $isHidden = isset($this->aSettings['hidePrice']) && in_array($address, $this->aSettings['hidePrice']);
        $knownPrice = isset($this->aSettings['updateRates']) && in_array($address, $this->aSettings['updateRates']);

        if(!$isHidden && $knownPrice){
            $cache = 'rates';
            $rates = $this->oCache->get($cache, false, true);
            if($updateCache){
                if(!is_array($rates)){
                    $rates = array();
                }
                if(isset($this->aSettings['currency'])){
                    $method = 'getCurrencyCurrent';
                    $params = array($address, 'USD');
                    $result = $this->_jsonrpcall($this->aSettings['currency'], $method, $params);
                    if($result && isset($result['rate'])){
                        unset($result['code_from']);
                        unset($result['code_to']);
                        unset($result['bid']);
                        // THBEX price bug workaround
                        if('0xff71cb760666ab06aa73f34995b42dd4b85ea07b' === $address){
                            if($result['rate'] > 1){
                                $result = 1 / (float)$result['rate'];
                            }
                        }
                        $price30d = $this->getTokenPrice30d($address);
                        if($price30d && $result['rate']){
                            $pdiff = $this->_getPDiff($result['rate'], $price30d);
                            if($pdiff){
                                $result['diff30d'] = $pdiff;
                            }
                        }
                        $rates[$address] = $result;
                        $this->oCache->save($cache, $rates);
                    }
                }
            }
            if(is_array($rates) && isset($rates[$address])){
                $result = $rates[$address];
            }
        }
        // evxProfiler::checkpoint('getTokenPrice', 'FINISH');
        return $result;
    }

    public function getTokenPriceHistory($address, $period = 0, $type = 'hourly', $updateCache = FALSE, $updateFullHistory = FALSE){
        if(isset($this->aSettings['priceSource']) && isset($this->aSettings['priceSource'][$address])){
            $address = $this->aSettings['priceSource'][$address];
        }
        $isHidden = isset($this->aSettings['hidePrice']) && in_array($address, $this->aSettings['hidePrice']);
        $knownPrice = isset($this->aSettings['updateRates']) && in_array($address, $this->aSettings['updateRates']);
        if($isHidden || !$knownPrice){
            return FALSE;
        }
        evxProfiler::checkpoint('getTokenPriceHistory', 'START', 'address=' . $address . ', period=' . $period . ', type=' . $type);
        $rates = array();
        $cache = 'rates-history-' . /*($period > 0 ? ('period-' . $period . '-') : '' ) . ($type != 'hourly' ? $type . '-' : '') .*/ $address;
        $result = $this->oCache->get($cache, false, true);
        if($updateCache || (FALSE === $result)){
            $lastTS = 0;
            $lastDate = FALSE;
            $skipGetHistory = FALSE;
            if(FALSE !== $result){
                for($i = 0; $i < count($result); $i++){
                    if($result[$i]['ts'] > $lastTS){
                        $lastTS = $result[$i]['ts'];
                        $lastDate = $result[$i]['date'];
                    }
                }
                $prevDate = gmdate("Y-m-d", time() - (24 * 60 * 60));
                if($lastDate && ($lastDate === $prevDate)){
                    $skipGetHistory = TRUE;
                }
            }
            if(isset($this->aSettings['currency']) && (!$skipGetHistory || $updateFullHistory)){
                $method = 'getCurrencyHistory';
                $params = array($address, 'USD');
                if($lastTS && !$updateFullHistory) $params[] = $lastTS + 1;
                $res = $this->_jsonrpcall($this->aSettings['currency'], $method, $params);
                if(FALSE !== $result){
                    $result = array_merge($result, $res);
                }else{
                    $result = $res;
                }
                if($result){
                    $aToken = $this->getToken($address);
                    $tokenStartAt = false;
                    if($aToken){
                        $patchFile = dirname(__FILE__) . '/../patches/price-' . $address . '.patch';
                        $aPatch = array();
                        if(file_exists($patchFile)){
                            $data = file_get_contents($patchFile);
                            $aData = json_decode($data, TRUE);
                            if($aData && count($aData)){
                                foreach($aData as $rec){
                                    $aPatch['ts-' . $rec['time']] = array(
                                        'high' => $rec['high'],
                                        'low' => $rec['low'],
                                        'open' => $rec['open'],
                                        'close' => $rec['close'],
                                        'volume' => $rec['volumefrom'],
                                        'volumeConverted' => $rec['volumeto']
                                    );
                                }
                            }
                        }
                        if(isset($aToken['createdAt'])){
                            $tokenStartAt = $aToken['createdAt'];
                        }
                        if(isset($this->aSettings['customTokenHistoryStart']) && isset($this->aSettings['customTokenHistoryStart'][$address])){
                            $tokenStartAt = $this->aSettings['customTokenHistoryStart'][$address];
                        }
                        for($i = 0; $i < count($result); $i++){
                            $zero = array('high' => 0, 'low' => 0, 'open' => 0, 'close' => 0, 'volume' => 0, 'volumeConverted' => 0);
                            if($result[$i]['ts'] < $tokenStartAt){
                                $result[$i] = array_merge($result[$i], $zero);
                            }
                            if(isset($aPatch['ts-' . $result[$i]['ts']])){
                                $result[$i] = array_merge($result[$i], $aPatch['ts-' . $result[$i]['ts']]);
                            }
                            // @temporary: EVX invalid history values fix
                            if('0xf3db5fa2c66b7af3eb0c0b782510816cbe4813b8' == $address){
                                if($result[$i]['high'] > 10){
                                   $result[$i]['high'] = $result[$i]['low'] * 1.2;
                                }
                            }
                        }
                    }
                }
                $this->oCache->save($cache, $result);
            }
        }
        if($result){
            $aPriceHistory = array();
            if($period){
                $tsStart = gmmktime(0, 0, 0, date('n'), date('j') - $period, date('Y'));
                for($i = 0; $i < count($result); $i++){
                    if($result[$i]['ts'] < $tsStart){
                        continue;
                    }
                    $aPriceHistory[] = $result[$i];
                }
            }else{
                $aPriceHistory = $result;
            }
            if($type == 'daily'){
                $aPriceHistoryDaily = array();
                $aDailyRecord = array();
                $curDate = '';
                $prevVol = 0;
                $prevVolC = 0;
                for($i = 0; $i < count($aPriceHistory); $i++){
                    $firstRecord = false;
                    $lastRecord = false;
                    if(!$curDate || ($curDate != $aPriceHistory[$i]['date'])){
                        $aDailyRecord = $aPriceHistory[$i];
                        $firstRecord = true;
                    }
                    if(($i == (count($aPriceHistory) - 1)) || ($aPriceHistory[$i]['date'] != $aPriceHistory[$i + 1]['date'])){
                        $lastRecord = true;
                        if($lastRecord){
                            $aDailyRecord['close'] = $aPriceHistory[$i]['close'];
                        }
                    }
                    if(!$firstRecord){
                        if($aPriceHistory[$i]['high'] > $aDailyRecord['high']){
                            $aDailyRecord['high'] = $aPriceHistory[$i]['high'];
                        }
                        if($aPriceHistory[$i]['low'] < $aDailyRecord['low']){
                            $aDailyRecord['low'] = $aPriceHistory[$i]['low'];
                        }
                        $aDailyRecord['volume'] += $aPriceHistory[$i]['volume'];
                        $aDailyRecord['volumeConverted'] += $aPriceHistory[$i]['volumeConverted'];
                        // $aDailyRecord['average'] = $aDailyRecord['volume'] ? ($aDailyRecord['volumeConverted'] / $aDailyRecord['volume']) : 0;
                    }
                    if($lastRecord){
                        // If volume goes up more than 10 mln times, we suppose it was a bug
                        if($prevVol && (($aDailyRecord['volume'] / $prevVol) > 1000000)){
                            $aDailyRecord['volume'] = $prevVol;
                        }
                        if($prevVolC && (($aDailyRecord['volumeConverted'] / $prevVolC) > 1000000)){
                            $aDailyRecord['volumeConverted'] = $prevVolC;
                        }
                        if($aDailyRecord['volume'] && $aDailyRecord['volumeConverted']){
                            $aDailyRecord['average'] = $aDailyRecord['volumeConverted'] / $aDailyRecord['volume'];
                        }
                        if(!isset($aDailyRecord['average'])) $aDailyRecord['average'] = 0;
                        $aPriceHistoryDaily[] = $aDailyRecord;
                        $prevVol = $aDailyRecord['volume'];
                        $prevVolC = $aDailyRecord['volumeConverted'];                        
                    }
                    $curDate = $aPriceHistory[$i]['date'];
                }
            }
            $rates[$address] = ($type == 'daily' ? $aPriceHistoryDaily : $aPriceHistory);
        }
        if(is_array($rates) && isset($rates[$address])){
            $result = $rates[$address];
        }
        evxProfiler::checkpoint('getTokenPriceHistory', 'FINISH');
        return $result;
    }

    public function getTokenCapHistory($period = 0, $updateCache = FALSE){
        evxProfiler::checkpoint('getTokenCapHistory', 'START', 'period=' . $period);
        $cache = 'cap-history';// . ($period > 0 ? ('period-' . $period) : '');
        $result = $this->oCache->get($cache, false, true);
        if($updateCache || (FALSE === $result)){
            $method = 'getTokensCapHistory';
            $result = $this->_jsonrpcall($this->aSettings['currency'], $method, array());
            if($result){
                $this->oCache->save($cache, $result);
            }
        }
        evxProfiler::checkpoint('getTokenCapHistory', 'FINISH');
        return $result;
    }

    protected function getTokenPriceCurrent($address){
        $this->_getRateByDate($address, date("Y-m-d"));
    }

    public function getTokenPriceHistoryGrouped($address, $period = 365, $type = 'daily', $updateCache = FALSE){
        $aResult = array();

        $aCurrent = $this->getTokenPrice($address);
        $aResult['current'] = $aCurrent;
        unset($aCurrent);

        $aHistoryCount = $this->getTokenHistoryGrouped($period, $address);
        $aResult['countTxs'] = $aHistoryCount;
        unset($aHistoryCount);

        $aHistoryPrices = $this->getTokenPriceHistory($address, $period, $type);
        $aResult['prices'] = $aHistoryPrices;
        unset($aHistory);

        return $aResult;
    }

    public function getAddressPriceHistoryGrouped($address, $updateCache = FALSE, $withEth = FALSE){
        evxProfiler::checkpoint('getAddressPriceHistoryGrouped', 'START', 'address=' . $address . ', withEth=' . ($withEth ? 'TRUE' : 'FALSE'));

        $cache = 'address_operations_history-' . $address . ($withEth ? '-eth' : '');
        $result = $this->oCache->get($cache, false, true);
        $updateCache = false;
        if($result && isset($result['timestamp'])){
            if(time() > ($result['timestamp'] + 3600)) $updateCache = true;
        }
        if(!isset($result['updCache'])){
            $result = false;
            $updateCache = false;
        }
        
        if(FALSE === $result || $updateCache){
            
            $opCount = $this->countOperations($address, FALSE);
            if($opCount >= 10000){
                evxProfiler::checkpoint('getAddressPriceHistoryGrouped', 'FINISH', 'Address has >10000 operations, skip');
                return FALSE;                
            }

            $aSearch = array('from', 'to', 'address'); // @todo: research "addresses"
            $aTypes = array('transfer', 'issuance', 'burn', 'mint');
            $aResult = array();
            $aContracts = array();
            $minTs = false;
            $maxTs = 0;

            if(FALSE === $result){
                $result = array();
                //$result['cache'] = 'noCacheData';
            }else if($updateCache){
                $result['cache'] = 'cacheUpdated';
            }

            foreach($aSearch as $cond){
                $search = array($cond => $address);
                if(!$withEth){
                    if($this->useOperations2){
                        $search['isEth'] = false;
                    }else{
                        $search['contract'] = array('$ne' => 'ETH');
                    }
                }
                if($updateCache){
                    $search = array('$and' => array($search, array('timestamp' => array('$gt' => $result['timestamp']))));
                }

                $cursor = $this->oMongo->find('operations', $search, false, false, false, array('timestamp', 'value', 'contract', 'from', 'type'));
                foreach($cursor as $record){
                    $date = gmdate("Y-m-d", $record['timestamp']);
                    if(!isset($result['txs'][$date])){
                        $result['txs'][$date] = 0;
                    }
                    if($record['type'] == 'transfer'){
                        $result['txs'][$date] += 1;
                        if($record['timestamp'] > $maxTs){
                            $maxTs = $record['timestamp'];
                        }
                        if(!$updateCache && (!$minTs || ($record['timestamp'] < $minTs))){
                            $minTs = $record['timestamp'];
                            $result['firstDate'] = $date;
                        }
                    }

                    if($withEth && ($record['contract'] == 'ETH')){
                        $record['contract'] = self::ADDRESS_ETH;
                    }

                    if((FALSE === array_search($record['contract'], $this->aSettings['updateRates'])) || !in_array($record['type'], $aTypes)){
                        continue;
                    }

                    if(!in_array($record['contract'], $aContracts)){
                        $aContracts[] = $record['contract'];
                    }
                    $indContract = array_search($record['contract'], $aContracts);

                    $add = 0;
                    if(!$updateCache && (!$minTs || ($record['timestamp'] < $minTs))){
                        $minTs = $record['timestamp'];
                        $result['firstDate'] = $date;
                    }
                    if(($record['from'] == $address) || ($record['type'] == 'burn')){
                        $add = 1;
                    }
                    if(!isset($aResult[$record['timestamp']])) $aResult[$record['timestamp']] = array();
                    //$aResult[$record['timestamp']][] = array($record['contract'], $record['value'], $add);
                    $aResult[$record['timestamp']][] = array(is_int($indContract) ? $indContract : $record['contract'], $record['value'], $add);
                }
            }
            if($maxTs > 0) $result['timestamp'] = $maxTs;
            krsort($aResult, SORT_NUMERIC);

            $aAddressBalances = $this->getAddressBalances($address, TRUE, $withEth);
            $ten = Decimal::create(10);

            if(isset($result['tokens'])) $aTokenInfo = $result['tokens'];
            else{
                $result['tokens'] = array();
                $aTokenInfo = array();
            }

            $curDate = false;
            //unset($result['timestamp']);
            foreach($aResult as $ts => $aRecords){
                foreach($aRecords as $record){
                    $date = gmdate("Y-m-d", $ts);
                    $nextDate = false;
                    if($curDate && ($curDate != $date)){
                        $nextDate = true;
                    }

                    //$contract = $record[0];
                    $contract = is_int($record[0]) ? $aContracts[$record[0]] : $record[0];
                    //if(!isset($result['timestamp'])) $result['timestamp'] = $ts;

                    if($contract == self::ADDRESS_ETH){
                        $token = $this->getEthToken();
                    }else{
                        $token = isset($aTokenInfo[$contract]) ? $aTokenInfo[$contract] : $this->getToken($contract, TRUE);
                    }
                    if($token){
                        if(!isset($aTokenInfo[$contract])){
                            $result['tokens'][$contract] = $token;
                            $aTokenInfo[$contract] = $token;
                        }

                        $dec = false;
                        if(isset($token['decimals'])) $dec = Decimal::create($token['decimals']);

                        $balance = Decimal::create(0);
                        if(!isset($aTokenInfo[$contract]['balance'])){
                            foreach($aAddressBalances as $addressBalance){
                                if($addressBalance["contract"] == $contract){
                                    $balance = Decimal::create($addressBalance["balance"]);
                                    if($dec && $contract != self::ADDRESS_ETH){
                                        $balance = $balance->div($ten->pow($dec));
                                    }
                                    break;
                                }
                            }
                            $result['balances'][$date][$token['address']] = '' . $balance;
                        }else{
                            if($nextDate) $result['balances'][$date][$token['address']] = $aTokenInfo[$contract]['balance'];
                            $balance = Decimal::create($aTokenInfo[$contract]['balance']);
                        }

                        if($dec){
                            // operation value
                            $value = Decimal::create($record[1]);
                            if($contract != self::ADDRESS_ETH){
                                $value = $value->div($ten->pow($dec));
                            }

                            // get volume
                            $curDateVolume = Decimal::create(0);
                            if(isset($result['volume'][$date][$token['address']])){
                                $curDateVolume = Decimal::create($result['volume'][$date][$token['address']]);
                            }
                            $curDateVolume = $curDateVolume->add($value);
                            $result['volume'][$date][$token['address']] = '' . $curDateVolume;

                            // get old balance
                            if(1 == $record[2]){
                                $oldBalance = $balance->add($value);
                            }else{
                                $oldBalance = $balance->sub($value);
                            }

                            $aTokenInfo[$contract]['balance'] = '' . $oldBalance;
                        }
                    }
                    $curDate = $date;
                }
            }
            if(!empty($result)){
                $result['updCache'] = 1;
                if(!isset($result['timestamp'])) $result['timestamp'] = time();
                $this->oCache->save($cache, $result);
            }
        }else{
            $result['cache'] = 'fromCache';
        }

        // get prices
        $aPrices = array();
        $result['tokenPrices'] = array();
        $maxTs = 0;
        foreach($result['tokens'] as $token => $data){
            $aPrices[$token] = $this->getTokenPriceHistory($token, 365, 'daily');
            if(!is_array($aPrices[$token]) || !count($aPrices[$token])){
                unset($aPrices[$token]);
                continue;
            }
            $result['tokenPrices'][$token] = $this->getTokenPrice($token);
            if(isset($result['tokenPrices'][$token]['ts']) && ($result['tokenPrices'][$token]['ts'] > $maxTs)){
                $maxTs = $result['tokenPrices'][$token]['ts'];
            }
        }
        if($maxTs) $result['updated'] = gmdate("Y-m-d H:i:s e", $maxTs);
        $result['prices'] = $aPrices;

        evxProfiler::checkpoint('getAddressPriceHistoryGrouped', 'FINISH');
        return $result;
    }

    /**
     * Return true if pool exist
     * @param String poolId
     * @return Array
     */
    public function isPoolExist($poolId) {
        evxProfiler::checkpoint('isPoolExist', 'START');
        $cache = "pool-exist-{$poolId}";
        $result = $this->oCache->get($cache, false, true, 600);
        if ($result === false) {
            $cursor = $this->oMongoPools->find('pools', [ 'uid' => $poolId ], false, 1, false, [ 'uid' => 1 ]);
            foreach($cursor as $pool) break;
            $result = !empty($pool);
            $this->oCache->save($cache, $result);
        }
        evxProfiler::checkpoint('isPoolExist', 'FINISH');
        return $result;
    }

    /**
     * Create pool
     * @param String $apiKey
     * @param String $addresses
     * @return Array
     */
    public function createPool($apiKey, $addresses = NULL){
        return $this->_jsonrpcall($this->aSettings['pools'], 'createPool', array($apiKey, $addresses));
    }

    public function deletePool($poolId = NULL){
        return $this->_jsonrpcall($this->aSettings['pools'], 'deletePool', array($poolId));
        // remove from cache
        $this->oCache->delete("pool_addresses-{$poolId}");
        $this->oCache->delete("pool-exist-{$poolId}");

    }

    public function updatePool($method = NULL, $poolId = NULL, $addresses = NULL){
        $response = $this->_jsonrpcall($this->aSettings['pools'], 'updatePool', array($method, $poolId, $addresses));
        // clean cache
        $this->oCache->delete('pool_addresses-' . $poolId);
        return $response;
    }

    /**
     * Returns pool addresses.
     *
     * @param int $poolId  Pool id
     * @return array
     */
    public function getPoolAddresses($poolId, $updateCache = FALSE) {
        evxProfiler::checkpoint('getPoolAddresses', 'START');
        $cache = 'pool_addresses-' . $poolId;
        $aAddresses = $this->oCache->get($cache, "", true, 600);
        if ($updateCache || (false === $aAddresses)) {
            $cursor = $this->oMongoPools->find('pools', ['uid' => $poolId], false, 1);
            $result = "";
            foreach($cursor as $result) break;
            if(isset($result['addresses'])){
                $aAddresses = $result['addresses'];
                $this->oCache->save($cache, $aAddresses);
            }
        }
        evxProfiler::checkpoint('getPoolAddresses', 'FINISH');
        return $aAddresses;
    }

    /**
     * Returns pool transactions.
     *
     * @param int $poolId  Pool id
     * @param int $period  Period
     * @return array
     */
    public function getPoolLastTransactions($poolId, $period, $updateCache = false) {
        evxProfiler::checkpoint('getPoolLastTransactions', 'START');
        $cache = 'pool_transactions-' . $poolId. '-' . $period;
        $aTxs = $this->oCache->get($cache, false, true, 30);
        if($updateCache || (false === $aTxs)){
            $cursor = $this->oMongoPools->find('transactions', ['pools' => $poolId, 'timestamp' => ['$gte' => time() - $period] ], ['timestamp' => -1]);
            $aTxs = [];
            $poolAddresses = $this->getPoolAddresses($poolId);
            foreach($cursor as $tx) {
                $gasLimit = $tx['gas'];
                $gasUsed = isset($tx['gasUsed']) ? $tx['gasUsed'] : 0;
                $success = ((21000 == $gasUsed) || ($gasUsed < $gasLimit));
                $success = isset($tx['status']) ? $this->txSuccessStatus($tx) : $success;
                $transaction = [
                    'timestamp' => $tx["timestamp"],
                    'blockNumber' => $tx["blockNumber"],
                    'from' => $tx["from"],
                    'to' => $tx["to"],
                    'hash' => $tx["hash"],
                    'value' => $tx["value"],
                    'input' => $tx["input"],
                    'balances' => $tx["balances"],
                    'success' => $success
                ];

                if (stripos($poolAddresses, $tx["from"]) !== false) {
                    if (!is_array($aTxs[$tx["from"]])) {
                        $aTxs[$tx["from"]] = [];
                    }
                    $aTxs[$tx["from"]][] = $transaction;
                }

                if (stripos($poolAddresses, $tx["to"]) !== false) {
                    if (!is_array($aTxs[$tx["to"]])) {
                        $aTxs[$tx["to"]] = [];
                    }
                    $aTxs[$tx["to"]][] = $transaction;
                }
            }
            if($aTxs){
                $this->oCache->save($cache, $aTxs);
            }
        }
        evxProfiler::checkpoint('getPoolLastTransactions', 'FINISH');
        return $aTxs;
    }

    /**
     * Returns pool operations.
     *
     * @param int $poolId  Pool id
     * @param int $period  Period
     * @return array
     */
    public function getPoolLastOperations($poolId, $period, $updateCache = false) {
        evxProfiler::checkpoint('getPoolLastOperations', 'START');
        $cache = 'pool_operations-' . $poolId. '-' . $period;
        $aOps = $this->oCache->get($cache, false, true, 30);
        if($updateCache || (false === $aOps)){
            $cursor = $this->oMongoPools->find('operations', array('pools' => $poolId, 'timestamp' => array('$gte' => time() - $period)), array("timestamp" => -1));
            $aOps = array();
            $poolAddresses = $this->getPoolAddresses($poolId);
            foreach($cursor as $op) {
                $operation = [
                    'timestamp' => $op["timestamp"],
                    'blockNumber' => $op["blockNumber"],
                    'contract' => $op["contract"],
                    'value' => $op["value"],
                    'type' => $op["type"],
                    'priority' => $op["priority"],
                    'from' => $op["from"],
                    'to' => $op["to"],
                    'hash' => $op["hash"],
                    'balances' => $op["balances"]
                ];

                if (stripos($poolAddresses, $op["contract"]) !== false) {
                    if (!is_array($aOps[$op["contract"]])) {
                        $aOps[$op["contract"]] = [];
                    }
                    $aOps[$op["contract"]][] = $operation;
                }
                
                if (stripos($poolAddresses, $op["from"]) !== false) {
                    if (!is_array($aOps[$op["from"]])) {
                        $aOps[$op["from"]] = [];
                    }
                    $aOps[$op["from"]][] = $operation;
                }

                if (stripos($poolAddresses, $op["to"]) !== false) {
                    if (!is_array($aOps[$op["to"]])) {
                        $aOps[$op["to"]] = [];
                    }
                    $aOps[$op["to"]][] = $operation;
                }
            }
            if($aOps){
                $this->oCache->save($cache, $aOps);
            }
        }
        evxProfiler::checkpoint('getPoolLastOperations', 'FINISH');
        return $aOps;
    }

    protected function _getRateByTimestamp($address, $timestamp){
        $result = 0;
        $aHistory = $this->getTokenPriceHistory($address);
        if(is_array($aHistory)){
            foreach($aHistory as $aRecord){
                if(isset($aRecord['volume']) && $aRecord['volume']){
                    $ts = $aRecord['ts'];
                    if($ts <= $timestamp){
                        $result = $aRecord['volumeConverted'] / $aRecord['volume'];
                    }else{
                        break;
                    }
                }
            }
        }
        return $result;
    }

    protected function _getRateByDate($address, $date){
        $result = 0;
        $aHistory = $this->getTokenPriceHistory($address);
        $aHistoryByDate = array();
        if(is_array($aHistory)){
            foreach($aHistory as $aRecord){
                if(isset($aRecord['open'])){
                    $date = $aRecord['date'];
                    if(isset($aHistoryByDate[$date])){
                        continue;
                    }
                }
            }
        }
        return $result;
    }

    protected function _getPDiff($a, $b){
        $res = 100;
        if(!$b){
            return ($a > 0) ? FALSE : 0;
        }
        if($a !== $b){
            if($a && $b){
                $res = ($a / $b) * 100 - 100;
            }else{
                $res *= (($a - $b) < 0) ? -1 : 1;
            }
        }else{
            $res = 0;
        }
        if((abs($res) > 10000) && ($b < 10)){
            $res = FALSE;
        }
        return $res;
    }

    /**
     * JSON RPC request implementation.
     *
     * @param string $method  Method name
     * @param array $params   Parameters
     * @return array
     */
    protected function _callRPC($method, $params = array()){
        if(!isset($this->aSettings['ethereum'])){
            throw new Exception("Ethereum configuration not found");
        }
        return $this->_jsonrpcall($this->aSettings['ethereum'], $method, $params);
    }

    protected function _jsonrpcall($service, $method, $params = array()){
        $data = array(
            'jsonrpc' => "2.0",
            'id'      => time(),
            'method'  => $method,
            'params'  => $params
        );
        $result = false;
        $json = json_encode($data);
        if(filter_input(INPUT_GET, "debugRPC")){
            echo "Request: " . var_export($json, true) . "\n";
        }        
        $ch = curl_init($service);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-Type: application/json',
            'Content-Length: ' . strlen($json))
        );
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $json );
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        $rjson = curl_exec($ch);
        if(filter_input(INPUT_GET, "debugRPC")){
            echo "Response: " . var_export($rjson, true) . "\n";
            die;
        }
        if($rjson && (is_string($rjson)) && ('{' === $rjson[0])){
            $json = json_decode($rjson, JSON_OBJECT_AS_ARRAY);
            if(isset($json["result"])){
                $result = $json["result"];
            }
            if(isset($json["error"])){
                $result = ["error" => $json["error"]];
            }
        }

        return $result;
    }

    public function searchToken($token){
        $result = array('results' => array(), 'total' => 0);
        $found = array();
        $aTokens = $this->getTokens();
        $aTokens['0xf3763c30dd6986b53402d41a8552b8f7f6a6089b'] = array(
            'name' => 'Chainy',
            'symbol' => false,
            'txsCount' => 99999
        );
        if(isset($this->aSettings['client']) && isset($this->aSettings['client']['tokens'])){
            $aClientTokens = $this->aSettings['client']['tokens'];
            foreach($aClientTokens as $address => $aClientToken){
                if(isset($aTokens[$address])){
                    if(isset($aClientToken['name'])){
                        $aTokens[$address]['name'] = $aClientToken['name'];
                    }
                    if(isset($aClientToken['symbol'])){
                        $aTokens[$address]['symbol'] = $aClientToken['symbol'];
                    }
                }
            }
        }
        foreach($aTokens as $address => $aToken){
            $search = strtolower($token);
            if((strpos($address, $search) !== FALSE) || (!empty($aToken['name']) && (strpos(strtolower($aToken['name']), $search) !== FALSE)) || (!empty($aToken['symbol']) && (strpos(strtolower($aToken['symbol']), $search) !== FALSE))){
                $aToken['address'] = $address;
                $found[] = $aToken;
            }
        }
        uasort($found, array($this, 'sortTokensByTxsCount'));
        $i = 0;
        foreach($found as $aToken){
            if($i < 6){
                $aToken += array('name' => '', 'symbol' => '');
                $result['results'][] = array($aToken['name'], $aToken['symbol'], $aToken['address']);
            }
            $i++;
        }
        $result['total'] = $i;
        $result['search'] = $token;
        return $result;
    }

    public function sortTokensByTxsCount($a, $b) {
        if(!isset($a['txsCount'])){
            $a['txsCount'] = 0;
        }
        if(!isset($b['txsCount'])){
            $b['txsCount'] = 0;
        }
        if($a['txsCount'] == $b['txsCount']){
            return 0;
        }
        return ($a['txsCount'] < $b['txsCount']) ? 1 : -1;
    }

    public function getActiveNotes(){
        $result = array();
        if(isset($this->aSettings['adv'])){
            $all = $this->aSettings['adv'];
            foreach($all as $one){
                if(isset($one['activeTill'])){
                    if($one['activeTill'] <= time()){
                        continue;
                    }
                }
                $one['link'] = urlencode($one['link']);
                $one['hasNext'] = (count($all) > 1);
                $result[] = $one;
            }
        }
        return $result;
    }

    public function reportException($exception, $data){
        if(is_object($this->sentryClient)){
            $this->sentryClient->captureException($exception, $data);
        }
    }
    
    protected function txSuccessStatus(array $tx){
        if(isset($tx['status']) && $tx['status'] && is_string($tx['status'])){
            $tx['status'] = str_replace("0x", "", $tx['status']);
        }
        return !!$tx['status'];
    }

    protected function getEthToken(){
        return array(
            'address' => self::ADDRESS_ETH,
            'name' => 'Ethereum',
            'symbol' => 'ETH',
            'decimals' => 18
        );
    }

    protected function _cliDebug($message){
        $showDebug = ((isset($this->aSettings['cliDebug']) && $this->aSettings['cliDebug']) || defined('ETHPLORER_SHOW_OUTPUT')) && (php_sapi_name() === 'cli');
        if($showDebug){
            echo '[' . date("Y-m-d H:i:s") . '] ' . $message . "\n";
        }
    }
}
