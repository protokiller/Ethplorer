<?php
namespace apiTests;
require "vendor/autoload.php";

use DOMDocument;
use EverexIO\PHPUnitIterator\TestCase;

class ratesTest extends TestCase
{
    protected $url = 'http://rates.everex.io';

    /**
     * @dataProvider ratesProvider
     */
    public function testAPI($test)
    {
        $this->_iterateTest($test);
    }

    public function ratesProvider()
    {
        return [
            // ===================
            // historical tests
            [[
                'type' => 'compare',
                'method' => 'getCurrencyHistory',
                'description' => '= Comparing historical currencies (THB, MMK, EUR, CNY, GBP, JPY, RUB, BTC) with USD =',
                'compareFrom' => 'USD',
                'compareTo' => ['THB', 'MMK', 'EUR', 'CNY', 'GBP', 'JPY', 'RUB', 'BTC'],
                'compareType' => 'normal',
                'compareTime' => 'historic',
                'callback' => function($currency){
                    $apiKey = $this->getOxrApiKey();
                    $randomDate = date("Y-m-d", mt_rand(1427846400, time() - (24 * 60 * 60)));
                    $url = 'https://openexchangerates.org/api/historical/' . $randomDate . '.json?app_id=' . $apiKey;
                    $json = file_get_contents($url);
                    $result = json_decode($json, TRUE);
                    $rate = 0;
                    if(isset($result['rates']) && isset($result['rates'][$currency])){
                        $rate = $result['rates'][$currency];
                    }
                    return array('currency' => $currency, 'date' => $randomDate, 'rate' => $rate);
                }
            ]],
            [[
                'type' => 'compare',
                'method' => 'getCurrencyHistory',
                'description' => '= Comparing historical ETH to USD =',
                'compareFrom' => '0x0000000000000000000000000000000000000000',
                'compareTo' => ['USD'],
                'compareType' => 'cycle',
                'compareSourceParam' => 'ethereum',
                'compareTime' => 'historic',
                'callback' => function($currency){
                    return $this->getHtmlData($currency);
                }
            ]],
            [[
                'type' => 'compare',
                'method' => 'getCurrencyHistory',
                'description' => '= Comparing historical token to USD =',
                'compareFrom' => '0xf3db5fa2c66b7af3eb0c0b782510816cbe4813b8',
                'compareTo' => ['USD'],
                'compareType' => 'cycle',
                'compareSourceParam' => 'everex',
                'compareTime' => 'historic',
                'callback' => function($currency){
                    return $this->getHtmlData($currency);
                }
            ]],
            [[
                'type' => 'compare',
                'method' => 'getCurrencyHistory',
                'description' => '= Comparing historical token to USD =',
                'compareFrom' => '0x86fa049857e0209aa7d9e616f7eb3b3b78ecfdb0',
                'compareTo' => ['USD'],
                'compareType' => 'cycle',
                'compareSourceParam' => 'eos',
                'compareTime' => 'historic',
                'callback' => function($currency) {
                    return $this->getHtmlData($currency);
                }
            ]],
            [[
                'type' => 'compare',
                'method' => 'getCurrencyHistory',
                'description' => '= Comparing historical token to USD =',
                'compareFrom' => '0xd26114cd6ee289accf82350c8d8487fedb8a0c07',
                'compareTo' => ['USD'],
                'compareType' => 'cycle',
                'compareSourceParam' => 'omisego',
                'compareTime' => 'historic',
                'callback' => function($currency){
                    return $this->getHtmlData($currency);
                }
            ]],
            // ===================
            // current tests
            [[
                'type' => 'compare',
                'method' => 'getCurrencyCurrent',
                'description' => '= Comparing current ETH to USD =',
                'compareFrom' => '0x0000000000000000000000000000000000000000',
                'compareTo' => ['USD'],
                'compareType' => 'cycle',
                'compareSourceParam' => 'ethereum',
                'compareTags' => [['availableSupply', 'available_supply'], ['marketCapUsd', 'market_cap_usd']],
                'compareTime' => 'current',
                'callback' => function($currency){
                    $url = 'https://api.coinmarketcap.com/v1/ticker/?convert=USD&limit=0';
                    $data = json_decode(file_get_contents($url), true);
                    foreach ($data as $item)
                    {
                        if ($item['id'] == $currency)
                            return $item;
                    }
                }
            ]],
            [[
                'type' => 'compare',
                'method' => 'getCurrencyCurrent',
                'description' => '= Comparing current token to USD =',
                'compareFrom' => '0xf3db5fa2c66b7af3eb0c0b782510816cbe4813b8',
                'compareTo' => ['USD'],
                'compareType' => 'cycle',
                'compareSourceParam' => 'everex',
                'compareTags' => [['availableSupply', 'available_supply'], ['marketCapUsd', 'market_cap_usd']],
                'compareTime' => 'current',
                'callback' => function($currency){
                    $url = 'https://api.coinmarketcap.com/v1/ticker/?convert=USD&limit=0';
                    $data = json_decode(file_get_contents($url), true);
                    foreach ($data as $item)
                    {
                        if ($item['id'] == $currency)
                            return $item;
                    }
                }
            ]],
            [[
                'type' => 'compare',
                'method' => 'getCurrencyCurrent',
                'description' => '= Comparing current token to USD =',
                'compareFrom' => '0x86fa049857e0209aa7d9e616f7eb3b3b78ecfdb0',
                'compareTo' => ['USD'],
                'compareType' => 'cycle',
                'compareSourceParam' => 'eos',
                'compareTags' => [['availableSupply', 'available_supply'], ['marketCapUsd', 'market_cap_usd']],
                'compareTime' => 'current',
                'callback' => function($currency){
                    $url = 'https://api.coinmarketcap.com/v1/ticker/?convert=USD&limit=0';
                    $data = json_decode(file_get_contents($url), true);
                    foreach ($data as $item)
                    {
                        if ($item['id'] == $currency)
                            return $item;
                    }
                }
            ]],
            [[
                'type' => 'compare',
                'method' => 'getCurrencyCurrent',
                'description' => '= Comparing current token to USD =',
                'compareFrom' => '0xd26114cd6ee289accf82350c8d8487fedb8a0c07',
                'compareTo' => ['USD'],
                'compareType' => 'cycle',
                'compareSourceParam' => 'omisego',
                'compareTags' => [['availableSupply', 'available_supply'], ['marketCapUsd', 'market_cap_usd']],
                'compareTime' => 'current',
                'callback' => function($currency){
                    $url = 'https://api.coinmarketcap.com/v1/ticker/?convert=USD&limit=0';
                    $data = json_decode(file_get_contents($url), true);
                    foreach ($data as $item)
                    {
                        if ($item['id'] == $currency)
                            return $item;
                    }
                }
            ]],
           [[
               'type' => 'compare',
               'method' => 'getCurrencyCurrent',
               'description' => '= Comparing current currencies with USD =',
               'compareFrom' => 'USD',
               'compareTo' => ['THB', 'MMK', 'EUR', 'CNY', 'GBP', 'JPY', 'RUB'],
               'compareType' => 'key',
               'compareTime' => 'current',
               'callback' => function(){
                   $apiKey = $this->getOxrApiKey();
                   $url = 'https://openexchangerates.org/api/latest.json?app_id='.$apiKey;
                   $json = file_get_contents($url);
                   $result = json_decode($json, TRUE);
                   return $result['rates'];
               }
            ]],
            [[
                'type' => 'compare',
                'method' => 'getBTCPrice',
                'description' => '= Comparing current BTC with USD =',
                'compareFrom' => 'USD', //
                'compareTo' => ['USD'],
                'compareType' => 'cycle-key',
                'compareTime' => 'current',
                'callback' => function(){
                    $url = 'http://www.bitstamp.net/api/ticker/';
                    $json = file_get_contents($url);
                    $result = json_decode($json, TRUE);
                    return $result;
                }
            ]],
            [[
                'type' => 'compare',
                'method' => 'getCurrencyCurrent',
                'description' => '= Comparing current THBEX with USD =',
                'compareFrom' => '0xff71cb760666ab06aa73f34995b42dd4b85ea07b',
                'compareTo' => ['USD'],
                'compareTags' => ['THB'],
                'compareType' => 'key-reverse',
                'compareTime' => 'current',
                'callback' => function(){
                    $apiKey = $this->getOxrApiKey();
                    $url = 'https://openexchangerates.org/api/latest.json?app_id='.$apiKey;
                    $json = file_get_contents($url);
                    $result = json_decode($json, TRUE);
                    return $result['rates'];
                }
            ]]
        ];
    }

    protected function getOxrApiKey(){
        global $argv;
        return $argv[2];
    }

    private function getDataFromHtml($html)
    {
        $DOM = new DOMDocument;
        libxml_use_internal_errors(true);
        $DOM->loadHTML($html);
        libxml_clear_errors();
        $data = $DOM->getElementsByTagName('tr');
        return $data;
    }

    private function getHtmlData($currency)
    {
        $url = 'https://coinmarketcap.com/currencies/'.$currency.'/historical-data/?start=20130428&end=20180122';
        $html = file_get_contents($url);
        $data = $this->getDataFromHtml($html);
        $result = array();
        foreach($data as $key => $node)
        {   //first node is header node, so we skip it
            if ($key == 0) continue;
            $nodeValue = $node->nodeValue;
            $values = explode("\n", $nodeValue);
            $values = $this->remove_empty($values);
            $array_elem = array();
            foreach ($values as $innerkey => $val)
            {
                if ($innerkey == 0){
                    $val = date('Y-m-d', strtotime($val));
                } else {
                    $val = str_replace( ',', '', $val );
                    $val = floatval($val);
                }
                array_push($array_elem, $val);
            }
            array_push($result, $array_elem);
        }
        return $result;
    }

    function remove_empty($array) {
        return array_filter($array, function($value) {
            return !empty($value) || $value === 0;
        });
    }
}