<?php

/**
 * 2026-06 Jin-Ying Lee
 * 處理中文異體字
 */


// 前面是匹配字串內有沒有異體字，後面是for solr查詢的regex
global $variants_array;


$url = "https://raw.githubusercontent.com/TaiBIF/tbia-portal/main/data/variants.json";
$curl = curl_init();
curl_setopt($curl, CURLOPT_URL, $url);
curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
curl_setopt($curl, CURLOPT_TIMEOUT, 120);
$result = curl_exec($curl);
$arrayData = json_decode($result, true);


$url = "https://raw.githubusercontent.com/TaiBIF/tbia-portal/main/data/composites.json";
$curl = curl_init();
curl_setopt($curl, CURLOPT_URL, $url);
curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
curl_setopt($curl, CURLOPT_TIMEOUT, 120);
$result = curl_exec($curl);
$mapData = json_decode($result, true);


// 處理陣列形式：每個子陣列 -> ['/綠|綠/', '[綠綠]']
$arrayResult = array_map(function($group) {
    $regex = '/'. implode('|', $group) . '/';
    $charClass = '[' . implode('', $group) . ']';
    return [$regex, $charClass];
}, $arrayData);

// 處理關聯陣列形式：每個鍵值對 -> ['/䳭|即鳥/', '(䳭|即鳥)']
$mapResult = array_map(function($key, $val) {
    $regex = '/'. $key . '|' . $val . '/';
    $group = '(' . $key . '|' . $val . ')';
    return [$regex, $group];
}, array_keys($mapData), array_values($mapData));

// 合併兩者
$variants_array = array_merge($arrayResult, $mapResult);

  
function treat_word_c ($string) {
	foreach ($GLOBALS['variants_array'] as $value) {
		$string = preg_replace($value[0], $value[1], $string);
	}
	return $string;
}

?>
