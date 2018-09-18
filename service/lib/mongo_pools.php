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
 * Pools mongo class.
 */
class evxMongoPools extends evxMongo {

    /**
     * Initialization.
     *
     * @param array $aSettings
     */
    public static function init(array $aSettings = array()){
        self::$oInstance = new evxMongoPools($aSettings);
    }

    protected function connectDb(){
        if($this->isConnected) return;

        $aSettings = $this->aSettings;

        $start = microtime(true);
        switch($aSettings['driver']){
            // Fake mongo driver to run without real mongo instance
            case 'fake':
                // @todo: implement
                break;
            // php version <= 5.5
            case 'mongo':
                $this->oMongo = new MongoClient($aSettings['server']);
                $oDB = $this->oMongo->{$this->dbName};
                $this->aDBs = array(
                    'pools'        => $oDB->pools,
                    'transactions' => $oDB->monitor_transactions,
                    'operations'   => $oDB->monitor_operations
                );
                break;
            // php version 5.6, 7.x use mongodb extension
            case 'mongodb':
                $this->oMongo = new MongoDB\Driver\Manager($aSettings['server']);
                $this->aDBs = array(
                    'pools'        => "pools",
                    'transactions' => "monitor_transactions",
                    'operations'   => "monitor_operations"
                );
                break;                
            default:
                throw new \Exception('Unknown mongodb driver ' . $dbDriver);
        }
        $finish = microtime(true);
        $qTime = $finish - $start;
        if($qTime > 0.1){
            $this->log('(' . ($qTime) . 's) Connection to ' . $aSettings['server']);
        }

        $this->isConnected = true;
    }
}
