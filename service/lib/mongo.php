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

/**
 * Mongo class.
 */
class evxMongo {

    /**
     * Instance object.
     *
     * @var evxMongo
     */
    protected static $oInstance;

    /**
     * Mongo driver.
     *
     * @var string
     */
    protected $driver;

    /**
     * MongoDB connection object.
     *
     * @var mixed
     */
    protected $oMongo;

    /**
     * Database name.
     *
     * @var string
     */
    protected $dbName;

    /**
     * Database collections set.
     *
     * @var array
     */
    protected $aDBs = array();

    /**
     * Logfile path.
     *
     * @var string
     */
    protected $logFile;

    /**
     * Mongo profiler
     *
     * @var array
     */
    protected $aProfile = array();

    protected $aSettings = array();
    
    protected $isConnected = false;

    protected $useOperations2 = FALSE;

    /**
     * Constructor.
     *
     * @param array $aSettings
     * @throws \Exception
     */
    protected function __construct(array $aSettings, $useOperations2 = FALSE){
        
        $this->logFile = __DIR__ . '/../log/mongo-profile.log';
        // Default config
        $aSettings += array(
            'driver' => 'mongodb',
            'server' => 'mongodb://127.0.0.1:27017',
            'dbName' => 'ethplorer',
            'prefix' => 'everex.'
        );

        $this->aSettings = $aSettings;
        $this->useOperations2 = $useOperations2;
        $this->dbName = $aSettings['dbName'];
        $this->driver = $aSettings['driver'];
    }

    /**
     * Converts timestamp to Mongo driver object.
     *
     * @param int $timestamp
     * @return \MongoDate|\MongoDB\BSON\UTCDateTime
     */
    public function toDate($timestamp = 0){
        $result = false;
        switch($this->driver){
            case 'fake':
                $result = $timestamp;
                break;

            case 'mongo':
                return new MongoDate($timestamp);
                break;

            case 'mongodb':
                return new MongoDB\BSON\UTCDateTime($timestamp);
                break;
        }
        return $result;
    }


    /**
     * MongoDB "find" method implementation.
     *
     * @param string $collection
     * @param array $aSearch
     * @param array $sort
     * @param int $limit
     * @param int $skip
     * @return array
     */
    public function find($collection, array $aSearch = array(), $sort = false, $limit = false, $skip = false, $fields = false, $hint = false){
        $this->connectDb();
        $aResult = false;
        $start = microtime(true);
        $aOptions = array();
        switch($this->driver){
            case 'fake':
                $aResult = array();
                break;

            case 'mongo':
                $cursor = is_array($fields) ? $this->aDBs[$collection]->find($aSearch, $fields) : $this->aDBs[$collection]->find($aSearch);
                if(is_array($sort)){
                    $cursor = $cursor->sort($sort);
                }
                if(false !== $skip && $skip > 0){
                    $cursor = $cursor->skip($skip);
                }
                if(false !== $limit){
                    $cursor = $cursor->limit($limit);
                }
                if(false !== $hint){
                    $cursor = $cursor->hint($hint);
                }
                $aResult = $cursor;
                break;

            case 'mongodb':
                if(is_array($sort)){
                    $aOptions['sort'] = $sort;
                }
                if(false !== $skip && $skip > 0){
                    $aOptions['skip'] = $skip;
                }
                if(false !== $limit){
                    $aOptions['limit'] = $limit;
                }
                if((false !== $fields) && is_array($fields)){
                    $aOptions['projection'] = array();
                    foreach($fields as $field){
                        $aOptions['projection'][$field] = 1;
                    }
                }
                if(false !== $hint){
                    $aOptions['hint'] = $hint;
                }
                $query = new MongoDB\Driver\Query($aSearch, $aOptions);
                $cursor = $this->oMongo->executeQuery($this->dbName . '.' . $this->aDBs[$collection], $query);

                $cursor->setTypeMap(['root' => 'array', 'document' => 'array', 'array' => 'array']);
                $aResult = new \IteratorIterator($cursor);
                $aResult->rewind();
                /*
                $cursor = MongoDB\BSON\fromPHP($cursor->toArray());
                $cursor = json_decode(MongoDB\BSON\toJSON($cursor), true);
                $aResult = $cursor;
                 */
                break;
        }
        $aQuery = [
            'collection' => (string)$this->aDBs[$collection],
            'find' => $aSearch,
            'opts' => $aOptions,
            'time' => round(microtime(true) - $start, 4)
        ];
        if($aQuery['time'] > 1){
            $this->log('(' . ($aQuery['time']) . 's) ' . $aQuery['collection'] . '.find(' . json_encode($aQuery['find']) . ', ' . json_encode($aQuery['opts']) . ')');
        }
        $this->aProfile[] = $aQuery;
        return $aResult;
    }

    /**
     * MongoDB "count" method implementation.
     *
     * @param string $collection
     * @param array $aSearch
     * @return int
     */
    public function count($collection, array $aSearch = array(), $limit = FALSE){
        $this->connectDb();
        $result = false;
        $start = microtime(true);
        $aOptions = array();
        if(FALSE !== $limit){
            $aOptions['limit'] = (int)$limit;
        }
        switch($this->driver){
            case 'fake':
                $result = 0;
                break;
            case 'mongo':
                $result = $this->aDBs[$collection]->count($aSearch, $aOptions);
                break;

            case 'mongodb':
                $query = new MongoDB\Driver\Query($aSearch, $aOptions);
                $cursor = $this->oMongo->executeQuery($this->dbName . '.' . $this->aDBs[$collection], $query);
                try {
                    $result = iterator_count($cursor);
                }catch(\Exception $e){
                    if(class_exists("Ethplorer")){
                        Ethplorer::db()->reportException($e, array(
                            'extra' => array(
                                'query' => 'count',
                                'search' => $aSearch,
                                'options' => $aOptions
                            )
                        ));
                    }
                    $result = FALSE;
                }
                /*
                $command = new MongoDB\Driver\Command(array("count" => $this->aDBs[$collection], "query" => $aSearch));
                $count = $this->oMongo->executeCommand($this->dbName, $command);
                $res = current($count->toArray());
                $result = $res->n;
                */
                /*
                $aOptions = array();
                $query = new MongoDB\Driver\Query($aSearch, $aOptions);
                $cursor = $this->oMongo->executeQuery($this->aDBs[$collection], $query);
                $result = iterator_count($cursor);
                */
                break;
        }
        $aQuery = [
            'collection' => (string)$this->aDBs[$collection],
            'count' => $aSearch,
            'opts' => $aOptions,
            'time' => round(microtime(true) - $start, 4)
        ];
        if($aQuery['time'] > 1){
            $this->log('(' . ($aQuery['time']) . 's) ' . $aQuery['collection'] . '.count(' . json_encode($aQuery['count']) . ', ' . json_encode($aQuery['opts']) . ')');
        }
        $this->aProfile[] = $aQuery;
        return $result;
    }

    /**
     * MongoDB "aggregate" method implementation.
     *
     * @param string $collection
     * @param array $aSearch
     * @param array $sort
     * @param int $limit
     * @param int $skip
     * @return array
     */
    public function aggregate($collection, array $aSearch = array()){
        $this->connectDb();
        $aResult = false;
        $start = microtime(true);
        $aOptions = array();
        switch($this->driver){
            case 'fake':
                $aResult = array();
                break;

            case 'mongo':
                $aResult = $this->aDBs[$collection]->aggregate($aSearch);
                break;

            case 'mongodb':
                $aResult = array();
                $command = new MongoDB\Driver\Command(array(
                    'aggregate' => $this->aDBs[$collection],
                    'pipeline' => $aSearch,
                    'cursor' => new stdClass,
                ));
                $cursor = $this->oMongo->executeCommand($this->dbName, $command);
                if(@count($cursor) > 0){
                    $aResult['result'] = array();
                    $cursor = new IteratorIterator($cursor);
                    foreach($cursor as $record){
                        $aResult['result'][] = (array)$record;
                    }
                }
                break;
        }
        $aQuery = [
            'collection' => (string)$this->aDBs[$collection],
            'aggregate' => $aSearch,
            'opts' => $aOptions,
            'time' => round(microtime(true) - $start, 4)
        ];
        if($aQuery['time'] > 1){
            $this->log('(' . ($aQuery['time']) . 's) ' . $aQuery['collection'] . '.aggregate(' . json_encode($aQuery['aggregate']) . ')');
        }
        $this->aProfile[] = $aQuery;
        return $aResult;
    }

    /**
     * Returns query profiler.
     *
     * @return array
     */
    public function getQueryProfileData(){
        return $this->aProfile;
    }

    public function dbConnected(){
        return $this->isConnected;
    }
    
    /**
     * Singleton implementation.
     *
     * @param array $aSettings
     * @return type
     * @throws \Exception
     */
    public static function getInstance(array $aSettings = array()){
        if(is_null(self::$oInstance)){
            throw new \Exception('Mongo class was not initialized.');
        }
        return self::$oInstance;
    }

    protected function log($message){
        $logString = '[' . date('Y-m-d H:i:s') . '] - ' . $message . "\n";
        file_put_contents($this->logFile, $logString, FILE_APPEND);
    }

    protected function connectDb(){
        // @todo: throw an exception
    }
}
