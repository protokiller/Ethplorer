<?php
require "vendor/autoload.php";
require_once __DIR__ . '/../service/lib/ethplorer.php';

use EverexIO\PHPUnitIterator\TestCase;

class serviceTest extends TestCase
{
    private $ethplorer;
    private $config;
    private $pendingFilterId;

    const MAX_CHECKING_COUNT = 10;
    const WAIT_INTERVAL = 1; // 1 sec

    protected function setUp()
    {
        $this->config = include(__DIR__ . '/../service/config.php');
        $this->ethplorer = Ethplorer::db($this->config);
    }

    protected function setDown() {
        if (!empty($this->pendingFilterId)) {
            // remove filter
            $this->_callRPC('eth_uninstallFilter', [$this->pendingFilterId]);
        }
    }

    /**
     * JSON RPC request implementation.
     *
     * @param string $method  Method name
     * @param array $params   Parameters
     * @return array
     */
    private function _callRPC($method, $params = array()){
        if(!isset($this->config['ethereum'])){
            throw new Exception("Ethereum configuration not found");
        }
        return $this->_jsonrpcall($this->config['ethereum'], $method, $params);
    }

    private function _jsonrpcall($service, $method, $params = []){
        $data = array(
            'jsonrpc' => "2.0",
            'id'      => time(),
            'method'  => $method,
            'params'  => $params
        );
        $result = false;
        $json = json_encode($data);
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
        if($rjson && (is_string($rjson)) && ('{' === $rjson[0])){
            $json = json_decode($rjson, JSON_OBJECT_AS_ARRAY);
            if(array_key_exists('result', $json)){
                $result = $json["result"];
            }
        }
        return $result;
    }

    private function getLastPendingTransaction() {
        // Create filter
        $this->pendingFilterId = $this->_callRPC('eth_newPendingTransactionFilter');

        // Check filter
        echo("\n");
        for($i = 0; $i < self::MAX_CHECKING_COUNT; $i++) {
            $pendingTransactions =  $this->_callRPC('eth_getFilterChanges', [$this->pendingFilterId]);
            $length = count($pendingTransactions);
            echo("[{$i}] Amount of pending transactions {$length}\n");
            if (!empty($pendingTransactions)) {
                break;
            }
            sleep(self::WAIT_INTERVAL);
        }

        // remove filter
        if (!empty($this->pendingFilterId)) {
            // remove filter
            $this->_callRPC('eth_uninstallFilter', [$this->pendingFilterId]);
        }

        // if pending transactions finded
        if (empty($pendingTransactions)) {
            throw Exception('Pending transactions not found!');
        }

        return $pendingTransactions[0];
    }

    /**
     * Checking showing details of pending transactions
     */
    public function testGettingTransactionDetails() {
        $txHash = $this->getLastPendingTransaction();
        $txDetails = $this->ethplorer->getTransactionDetails($txHash);
        $this->assertArrayHasKey('pending', $txDetails);
        $this->assertTrue($txDetails['pending']);
    }
}