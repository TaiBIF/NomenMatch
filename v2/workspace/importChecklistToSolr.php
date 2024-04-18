<?php

require_once "../include/cleanname.inc";
require_once "../include/treat_word.inc";

$ep = trim(file_get_contents("../conf/solr_endpoint"), " /\r\n");

if (empty($argv[1])) {
	echo "
Usage:
php importChecklistToSolr.php {/path/to/source_data.csv} [source_id]
if [source_id] is empty, \"source_data\" will be used as the source_id  

Example(s):
";
	exec("ls -h ./data/*.csv", $o);
	echo implode("\n", $o) . "\n\n";
	return;
}




function readSpecificRow($filePath, $myrownumber)
{
    $f = fopen($filePath, "r");
    $i = 0;
    $myrownumber = (int) $myrownumber; // ensure it's actually a number.
    while (($line = fgetcsv($f,0, "\t" )) !== false)
    {
        if (++$i < $myrownumber) continue;
        fclose($f);
        return $line;
    }
    return false; // if the specified line wasn't there return false
}

function submitJson($dat, $tmp_path="./tmp/save_storage.json") {
	global $ep;
	static $total = 0;
	if (!empty($dat)) {
		$numString = ($total + 1) . "-" . ($total + count($dat));
		echo "Submitting $numString ...\n";
		$json = json_encode($dat);

		if (!empty($tmp_path)) {
			$json_path = $tmp_path;
		}
		else {
			$json_path = "./tmp/json/test_solr_".$numString.".json";
		}

		file_put_contents($json_path, $json);
		$jf = "curl '" . $ep . "/update/json?commit=true' --data-binary @".$json_path." -H 'Content-type:application/json'";
		$response = exec($jf, $out);
//		var_dump($out);
		$total+=count($dat);
	}
}


$counter = 0;
$ret = array();

$file = (empty($argv[1]))?"":$argv[1];
$fp = fopen($file, "r");

$source = (empty($argv[2]))?basename($file, '.csv'):$argv[2];

// kim: tab-separated
while ($vals = fgetcsv($fp, 0, "\t" )) {
	$vals = array_map("trim", $vals, array_fill(0, count($vals), "\r\n\t ,'\"")); // 開頭&結尾移除不要的標點符號

	// kim: 如果本身沒有name_id的話，建一個hash
	if (empty($vals[0]) && empty($vals[1])) {
		$vals[0] = "sci_hash_" . md5($vals[2]); 
		$vals[1] = $vals[0];
	}
	else if (empty($vals[0])) {
		$vals[0] = $vals[1] . '-s5-v' . md5($vals[2]);
	}

	/**
	 * 0 namecode
	 * 1 accepted_namecode
	 * 2 scientific_name
	 * 3 name_url_id
	 * 4 accepted_url_id
	 * 5 common_name_c
	 * 6 taxon_rank
	 * 7 genus
	 * 8 family
	 * 9 order
	 * 10 class
	 * 11 phylum
	 * 12 kingdom
	 * 13 simple_name
	 * 14 name_status
	 * 15 alternative_name_c
	 */

	$rec = array();
	//$rec['id'] = $source . '-' . $vals[0];
	$rec['source'] = $source;

	$rec['url_id'] = $vals[3];
	if (empty($vals[4]) && $source != 'taicol' ) {
		$rec['a_url_id'] = $vals[3];
	}
	else {
		$rec['a_url_id'] = $vals[4];
	}

	$rec['simple_name'] = $vals[13];
	$rec['name_status'] = $vals[14];
	
	// kim: 只取單字
	$rec['genus'] = array_shift(explode(" ", $vals[7]));
	$rec['family'] = array_shift(explode(" ", $vals[8]));
	$rec['order'] = array_shift(explode(" ", $vals[9]));
	$rec['class'] = array_shift(explode(" ", $vals[10]));
	$rec['phylum'] = array_shift(explode(" ", $vals[11]));
	$rec['kingdom'] = array_shift(explode(" ", $vals[12]));

	$rec['sound_genus'] = treat_word($rec['genus']);
	$rec['sound_family'] = treat_word($rec['family']);
	$rec['sound_order'] = treat_word($rec['order']);
	$rec['sound_class'] = treat_word($rec['class']);
	$rec['sound_phylum'] = treat_word($rec['phylum']);
	$rec['sound_kingdom'] = treat_word($rec['kingdom']);

	$rec['taxon_rank'] = $vals[6];

	// kim: 如果高階層和自己同階層就拿掉
	if (array_key_exists(strtolower($rec['taxon_rank']), $rec)) {
		$rec[strtolower($rec['taxon_rank'])] = null;
	}

	$rec['namecode'] = $vals[0];
//	$rec['taibnet_url'] = "http://taibnet.sinica.edu.tw/chi/taibnet_species_detail.php?name_code=" . $vals[0];

	if (empty($vals[1]) && $source != 'taicol' ) {
		$rec['accepted_namecode'] = $vals[0];
	}
	else {
		$rec['accepted_namecode'] = $vals[1];
	}
	
	// $rec['accepted_namecode'] = $vals[1];
	$rec['original_name'] = $vals[2];
	$rec['canonical_name'] = canonical_form($vals[2], true);
	//if ($rec['canonical_name'] == 'Bombyx pernyi') {
		//var_dump($rec);
	//}
	// $rec['common_name_c'] = explode(",", $vals[5]);  
	$rec['common_name_c'] = $vals[5];
	$rec['alternative_name_c'] = explode(",", $vals[15]);  

	$rec['sound_name'] = treat_word($rec['canonical_name']);

	$frags = explode(" ", $rec['canonical_name']);
	if (count($frags) > 1){
		$rec['latin_part_a'] = $frags[0];
		// $rec['genus'] = $frags[0];
		$rec['sound_part_a'] = treat_word($frags[0]);
		$rec['sound_genus'] = $frags[0];
		$rec['sound_part_a_strip_ending'] = treat_word($frags[0], true);

		$rec['nameSpell'][] = $frags[0];

		if (!empty($frags[1])) {
			$rec['latin_part_bc'][] = $frags[1];
			$rec['nameSpell'][] = $frags[1];
			$rec['nameSpell'][] = $frags[0] . " " . $frags[1];
			$rec['sound_part_bc'][] = treat_word($frags[1]);
			$rec['sound_part_bc_strip_ending'][] = treat_word($frags[1], true);
		}
		else {
			continue;
		}

		if (!empty($frags[2])) {
			$rec['latin_part_bc'][] = $frags[2];
			$rec['nameSpell'][] = $frags[2];
			$rec['nameSpell'][] = $frags[1] . " " . $frags[2];
			$rec['nameSpell'][] = $frags[0] . " " . $frags[2];
			$rec['nameSpell'][] = $frags[0] . " " . $frags[1] . " " . $frags[2];
			$rec['sound_part_bc'][] = treat_word($frags[2]);
			$rec['sound_part_bc_strip_ending'][] = treat_word($frags[2], true);
		}
		$rec['is_single_word'] = 0;

	} elseif (count($frags)==1) {
		$rec['is_single_word'] = 1;
		$rec['latin_part_a'] = $frags[0];
		$rec['sound_part_a'] = treat_word($frags[0]);
		$rec['sound_part_a_strip_ending'] = treat_word($frags[0], true);
		$rec['nameSpell'][] = $frags[0];
	}
	
	$ret[] = $rec;

	if ($counter % 1000 == 999) {
		submitJson($ret);
		$ret = array();
	}
	$counter++;
}

if (!empty($ret)) {
	submitJson($ret);
}

?>
