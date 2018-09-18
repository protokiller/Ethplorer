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

require_once __DIR__ . '/../../vendor/autoload.php';

/**
 * Cache class.
 */
class evxCache {
    /**
     * Seconds in 1 hour
     */
    const HOUR = 3600;

    /**
     * Seconds in 30 days
     */
    const MONTH = 2592000; // 30 * 24 * 3600

    /**
     * Seconds in 1 day
     */
    const DAY = 43200; // 1 * 24 * 3600

    /**
     * Cache locks ttl in seconds
     */
    const LOCK_TTL = 120;

    /**
     * Waiting time for checking cache data in seconds
     */
    const LOCK_WAITING_TIME = 3;

    /**
     * Repeats number for checking cache data
     */
    const LOCK_WAITING_REPEATS = 20;

    /**
     * Lifetime key value for keys without associated expire time
     */
    const NON_EXPIRATION_LIFETIME = -1;

    /**
     * Cache storage.
     *
     * @var array
     */
    protected $aData = array();

    /**
     * Cache files path.
     *
     * @var string
     */
    protected $path;

    /**
     * Cache driver name [file, memcached]
     *
     * @var string
     */
    protected $driver = 'file';

    /**
     * Cache driver object
     *
     * @var object
     */
    protected $oDriver = null;

    /**
     * Cache lifetime array
     *
     * @var array
     */
    protected $aLifetime = array();

    protected $useLocks = FALSE;

    /**
     * Constructor.
     *
     * @param string  $path  Cache files path
     * @todo params to config
     */
    public function __construct(array $aConfig, $driver = FALSE, $useLocks = FALSE){
        $path = $aConfig['cacheDir'];
        $path = realpath($path);
        if(file_exists($path) && is_dir($path)){
            $this->path = $path;
        }
        if(FALSE !== $driver){
            $this->driver = $driver;
        }
        $this->useLocks = $useLocks;

        if(isset($aConfig['cliCacheDriver']) && (php_sapi_name() === 'cli')) $this->driver = $aConfig['cliCacheDriver'];

        if('memcached' === $this->driver){
            if(class_exists('Memcached')){
                $mc = new Memcached('ethplorer');
                $mc->setOption(Memcached::OPT_LIBKETAMA_COMPATIBLE, true);
                if (!count($mc->getServerList())) {
                    // @todo: servers to config
                    $mc->addServers(array(array('127.0.0.1', 11211)));
                }
                $this->oDriver = $mc;
            }else{
                die('Memcached class not found, use filecache instead');
                $this->driver = 'file';
            }
        }else if('redis' === $this->driver){
            try{
                $rc = new Predis\Client($aConfig['redis']['servers'], $aConfig['redis']['options']);
                $this->oDriver = $rc;
            }catch(\Exception $e){
                die($e->getMessage());
            }
        }
    }

    /**
     * Stores data to memory.
     *
     * @param string  $entryName  Cache entry name
     * @param mixed   $data       Data to store
     */
    public function store($entryName, $data){
        $this->aData[$entryName] = $data;
    }

    public function clearLocalCache(){
        $this->aData = array();
    }

    /**
     * Clean cache by key
     * @param String $entryName
     */
    public function delete($entryName) {
        switch ($this->driver) {
            case 'redis':
                $this->oDriver->del($entryName);
                break;

            case 'memcached':
                $this->oDriver->delete($entryName);
                break;

            case 'file':
                @unlink($this->path . '/' . $entryName . '.tmp');
                break;

            default:
                throw \Exception('The method for delete of cache driver not implemented');
        }
    }

    /**
     * Saves data to file.
     *
     * @param string  $entryName  Cache entry name
     * @param mixed   $data       Data to store
     */
    public function save($entryName, $data, $nonExpiration = FALSE){
        $saveRes = false;
        $this->store($entryName, $data);
        switch($this->driver){
            case 'redis':
            case 'memcached':
                $lifetime = isset($this->aLifetime[$entryName]) ? (int)$this->aLifetime[$entryName] : 0;
                /*if($lifetime > evxCache::MONTH){
                    $lifetime = time() + $cacheLifetime;
                }*/
                $ttl = evxCache::DAY;
                if($nonExpiration){
                    $lifetime = evxCache::NON_EXPIRATION_LIFETIME;
                }else if(!$lifetime){
                    // 1 day if cache lifetime is not set
                    $lifetime = time() + evxCache::DAY;
                }else{
                    $ttl = $lifetime;
                    $lifetime = time() + $lifetime;
                }
                $aCachedData = array('lifetime' => $lifetime, 'data' => $data, 'lock' => true);
                if('redis' == $this->driver){
                    if($nonExpiration){
                        $saveRes = $this->oDriver->set($entryName, json_encode($aCachedData));
                    }else{
                        $saveRes = $this->oDriver->set($entryName, json_encode($aCachedData), 'ex', $ttl);
                    }
                    if('OK' !== (string)$saveRes){
                        error_log("Write data to redis failed: " . $saveRes . " Data: " . json_encode($aCachedData) . " TTL: " . $ttl);
                    }
                    $this->oDriver->getConnection()->switchToSlave();
                }else{
                    $saveRes = $this->oDriver->set($entryName, $aCachedData);
                }
                if(('redis' == $this->driver) || (!in_array($entryName, array('tokens', 'rates')) && (0 !== strpos($entryName, 'rates-history-')))){
                    break;
                }
            case 'file':
                $filename = $this->path . '/' . $entryName . ".tmp";
                //@unlink($filename);
                $json = json_encode($data, JSON_PRETTY_PRINT);
                $saveRes = !!file_put_contents($filename, $json);
                break;
        }
        if($this->useLocks) $this->deleteLock($entryName);
        return $saveRes;
    }

    /**
     * Returns true if cached data entry exists.
     *
     * @param string  $entryName  Cache entry name
     * @return boolean
     */
    public function exists($entryName){
        return isset($this->aData[$entryName]);
    }

    /**
     * Returns true if cache lock file created.
     *
     * @param string  $file  File name
     * @return boolean
     */
    public function isLockFileExists($file){
        if(file_exists($file)){
            $lockFileTime = filemtime($file);
            if((time() - $lockFileTime) <= evxCache::LOCK_TTL){
                return TRUE;
            }
        }

        return FALSE;
    }

    /**
     * Adds cache lock.
     *
     * @param string  $entryName  Cache entry name
     * @return boolean
     */
    public function addLock($entryName){
        if('memcached' === $this->driver){
            return $this->oDriver->add($entryName . '-lock', TRUE, evxCache::LOCK_TTL);
        }else if('redis' === $this->driver){
            return $this->oDriver->set($entryName . '-lock', 'true', 'nx', 'ex', evxCache::LOCK_TTL);
        }else{
            $lockFilename = $this->path . '/' . $entryName . "-lock.tmp";

            if($this->isLockFileExists($lockFilename)) return FALSE;

            @unlink($lockFilename);
            $saveLockRes = !!file_put_contents($lockFilename, '1');
            return $saveLockRes;
        }
    }

    /**
     * Deletes cache lock.
     *
     * @param string  $entryName  Cache entry name
     * @return boolean
     */
    public function deleteLock($entryName){
        if('redis' === $this->driver){
            return $this->oDriver->del($entryName . '-lock');
        }elseif('memcached' === $this->driver){
            return $this->oDriver->delete($entryName . '-lock');
        }else{
            return @unlink($this->path . '/' . $entryName . '-lock.tmp');
        }
    }

    /**
     * Returns cached data by entry name.
     *
     * @param string   $entryName
     * @param mixed    $default
     * @param boolean  $loadIfNeeded
     * @return mixed
     */
    public function loadCachedData($entryName, $default = NULL, $cacheLifetime = FALSE){
        $result = array('data' => $default, 'expired' => FALSE);
        $file = ('file' === $this->driver);
        if('memcached' === $this->driver || 'redis' === $this->driver){
            $memcachedData = ('redis' == $this->driver) ? json_decode($this->oDriver->get($entryName), TRUE) : $this->oDriver->get($entryName);
            if($memcachedData && isset($memcachedData['lifetime']) && isset($memcachedData['data'])){
                $result['data'] = $memcachedData['data'];
                if($memcachedData['lifetime'] > 0 && $memcachedData['lifetime'] < time()){
                    $result['expired'] = TRUE;
                }
            }
            // @todo: move hardcode to controller
            if(!$result['data'] || $result['expired'] || (in_array($entryName, array('tokens', 'rates')) || (0 === strpos($entryName, 'rates-history-')))){
                $file = TRUE;
            }
            if('redis' === $this->driver){
                $file = FALSE;
            }
        }
        if($file){
            $filename = $this->path . '/' . $entryName . ".tmp";
            if(file_exists($filename)){
                $isFileExpired = FALSE;
                if(FALSE !== $cacheLifetime){
                    $fileTime = filemtime($filename);
                    $gmtZero = gmmktime(0, 0, 0);
                    if((($gmtZero > $fileTime) && ($cacheLifetime > evxCache::HOUR)) || ((time() - $fileTime) > $cacheLifetime)){
                        $isFileExpired = TRUE;
                    }
                }
                if(!$isFileExpired || !$result['data'] || $result['expired']){
                    $contents = @file_get_contents($filename);
                    $result['data'] = json_decode($contents, TRUE);
                    $result['expired'] = $isFileExpired;
                }
            }
        }
        return $result;
    }

    /**
     * Returns cached data by entry name.
     *
     * @param string   $entryName
     * @param mixed    $default
     * @param boolean  $loadIfNeeded
     * @return mixed
     */
    public function get($entryName, $default = NULL, $loadIfNeeded = FALSE, $cacheLifetime = FALSE){
        $result = $default;
        $isExpired = FALSE;
        if(FALSE !== $cacheLifetime){
            $this->aLifetime[$entryName] = $cacheLifetime;
        }
        if($this->exists($entryName)){
            $result = $this->aData[$entryName];
        }elseif($loadIfNeeded){
            $aCachedData = $this->loadCachedData($entryName, $default, $cacheLifetime);
            $result = $aCachedData['data'];
            $isExpired = $aCachedData['expired'];
        }else{
            return $result;
        }

        if($result && !$isExpired){
            return $result;
        }else{
            if(!$this->useLocks) return FALSE;

            // try to create cache lock
            if($this->addLock($entryName)){
                return FALSE;
            }else{
                // return any cached data if exist
                if($result){
                    return $result;
                }
                // waiting when other process creates cache data
                for($i = 0; $i < evxCache::LOCK_WAITING_REPEATS; $i++){
                    set_time_limit(20);
                    sleep(evxCache::LOCK_WAITING_TIME);
                    $aCachedData = $this->loadCachedData($entryName, $default, $cacheLifetime);
                    if($aCachedData['data']){
                        return $aCachedData['data'];
                    }
                }
            }
        }

        $this->send503Header(180);
        die();
    }

    /**
     * Sends HTTP error code 503.
     *
     * @param int  $timeout  Retry timeout
     */
    protected function send503Header($timeout){
        header((isset($_SERVER["SERVER_PROTOCOL"]) ? $_SERVER["SERVER_PROTOCOL"] : '') . ' 503 Service Temporarily Unavailable');
        header('Status: 503 Service Temporarily Unavailable');
        header('Retry-After: ' . $timeout);
    }
}