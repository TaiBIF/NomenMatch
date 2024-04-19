<?php

use LDAP\Result;

ini_set("memory_limit", "1024M");
set_time_limit(3600);
$stime = microtime(true);

if (!empty($_REQUEST['names'])){
	$names = explode("|", str_replace("\n", "|", @$_REQUEST['names']));
} else {
	$names = explode("|", str_replace("\n", "|", @$_POST['names']));
}
$names = array_filter($names); // 移除空值
$names_str = implode("|",$names);

require_once "./include/functions.php";

if (@$_REQUEST['lang']) {
	$gui_lang_file = "./conf/lang/error_codes.".$_REQUEST['lang'].".php";
	if (file_exists($gui_lang_file)) {
		require_once "./conf/lang/error_codes.".$_REQUEST['lang'].".php";
	}
	else {
		require_once "./conf/lang/error_codes.php";
	}
}
else {
	require_once "./conf/lang/error_codes.php";
}

require_once "./include/queryNames.php";

// $format = (!empty($_REQUEST['format']))?$_REQUEST['format']:'';

if (!empty($_REQUEST['format'])){
	$format = $_REQUEST['format'];
} else {
	(!empty($_POST['format']))?$_POST['format']:'';
}

// $against = (!empty($_REQUEST['source']))?$_REQUEST['source']:''; // source backbone 
if (!empty($_REQUEST['source'])){
	$against = $_REQUEST['source'];
} else {
	$against = (!empty($_POST['source']))?$_POST['source']:'';
}

if ($against=='taicol'){
	$against = 'taicol_2';
}


// $best = (!empty($_REQUEST['best']))?$_REQUEST['best']:'yes';
if (!empty($_REQUEST['best'])){
	$best = $_REQUEST['best'];
} else {
	$best = (!empty($_POST['best']))?$_POST['best']:'yes';
}

$ep = (!empty($_REQUEST['ep']))?$_REQUEST['ep']:file_get_contents(dirname(realpath(__FILE__)).'/conf/solr_endpoint'); // endpoint
$ep = trim($ep, " /\r\n");

$res = array();

if (count($names)>20 & $format!='csv' & $format!='json'){
	$total_page = ceil(count($names) / 20);
	$params = $_REQUEST;
	// 重新寫url, 不然會無限延長
	$params = Array();
	$params['format'] = $format;
	$params['best'] = $best;
	$params['against'] = $against;
	$params['ep'] = $ep;
	$params['names'] = implode("|",$names);;

	$page = (!empty($_REQUEST['page']))?$_REQUEST['page']:1; // current page

	$params['page'] = $page + 1;  // next page
	$uri = '/api.php?';
	$x = http_build_query($params);
	if ($page + 1 <= $total_page){
		$next_page = $page + 1;
	} else {
		$next_page = '';
	}
	if (($page - 1) > 0){
		$params['page'] = $page - 1;  // previous page
		$x = http_build_query($params);
		$previous_page = $page - 1;
	} else {
		$previous_page = '';
	}
		
	// 每次10筆
	$names = array_slice($names, ($page-1)*20, 20);

} else {
	$next_page = '';
	$previous_page = '';
}


// kim: 每一個輸入的name進行比對
foreach ($names as $nidx => $name) {

	$name = trim($name, "\t\r\n");

	// kim: 比對前去除掉特殊字元 & trim 空白字元，不保留subgenus的括號
	if (preg_match("/\p{Han}+/u", $name)){
		$name_cleaned = trim(preg_replace('/^[\pZ\pC]+|[\pZ\pC]+$/u','',$name), " \t\r\n.,;|");
	}
	else{
		$name_cleaned = canonical_form(trim(preg_replace('/^[\pZ\pC]+|[\pZ\pC]+$/u','',$name), " \t\r\n.,;|"), true);
	}
	
	// 如果可用空白鍵拆成array，則維持以原先的演算法match
	if (count(explode(" ", $name_cleaned)) > 1 && !(preg_match("/\p{Han}+/u", $name_cleaned))) {	

	/*
		for ($i=0; $i<strlen($name); $i++) {
			echo $name[$i].",".ord($name[$i]).",";
		}
	//*/
		$undecide = false;
		$onlyOne = false;
		$moreThan1 = false;
		$multiAnc = false;
		$multiNc = false;

		$scores = array();
		//if (empty($name)) continue;

		// kim: 進行比對
		$all_matched = queryNames($name, $against, $best, $ep);
			//echo '<pre>'.print_r($all_matched).'</pre>';exit();
		//ksort($all_matched);
		// kim: 比對後計算similarity
		foreach ($all_matched as $matched_name => $matched) {
			
			$scores[$matched_name] = nameSimilarity($matched['matched_clean'], $name_cleaned, $matched['type']);


		}
		// kim: 根據score排序
		arsort($scores);

		// 如果選best, 要顯示所有最高同分的結果
		// 先計算有幾個best，for loop跑到該數字
		$arr1 = array();
		foreach($scores as $k=>$score)
		{
			$arr1[$k] = (string) $score;
		}
		$score_vals = array_count_values($arr1);
		$num_highest_score = current($score_vals);
		$current_index = 0;

		foreach ($scores as $matched_name => $score) {
			// foreach ($score_array as $score){
				if ($score < 0) { // kim: 最小為零
					$score = 0;
				}
				if ($best == 'yes') {
					$matched_only = array_keys($scores); // 名字拆成array
					$scores_only = array_values($scores);
					$score0 = $scores_only[0]; // kim: 最高分
					if (count($scores) > 1) {
						$moreThan1 = true;
						$score1 = $scores_only[1]; // kim: 第二高分
						//var_dump(array($score0, $score1));
						if (round($score0/3.5,3) == round($score1/3.5,3)) { // kim: 如果同分的話代表無法決定
							$undecide = true;
						}
						//var_dump($undecide);
					}
					else {
						$onlyOne = true;
					}

					if ($undecide || $onlyOne) {
						$comb = array();
						$comb_string = array();
						foreach ($matched_only as $m_idx => $mo) {
							$comb_dmin = 999;
							$comb[$m_idx] = array('[whatever]','[whatever]','[whatever]');
							$comb_string[$m_idx] = "";
							$comb_common = array();

							// matched name 和 search term
							$parts1 = explode(" ", canonical_form($mo, true));
							$parts2 = explode(" ", $name_cleaned);

							// 種小名 & 種下
							$parts_bc_1 = array_slice($parts1, 1);
							$parts_bc_2 = array_slice($parts2, 1);
							$diff_rank = false;
							foreach ($parts_bc_1 as $idx1 => $pbc1) {
								foreach ($parts_bc_2 as $idx2 => $pbc2) {
									// var_dump(levenshtein($pbc1, $pbc2));
									if (levenshtein($pbc1, $pbc2) < $comb_dmin) {
										// $comb = $parts1[0] . " " . $pbc1;
										$comb[$m_idx][0] = $parts1[0];
										$comb_common = array('idx' => $idx1, 'name' => $pbc1);
										$comb_dmin = levenshtein($pbc1, $pbc2);
										if ($idx1 != $idx2) {
											$diff_rank = true;
										}
									}
								}
							}
							$comb[$m_idx][$comb_common['idx']+1] = $comb_common['name'];
							$comb_string[$m_idx] = implode(" ", $comb[$m_idx]);
						}
						if ($moreThan1 || $diff_rank) {
							$undecide = true;
							$all_matched[$matched_name]['score'] = 'N/A';
							// $all_matched[$matched_name]['matched'] = implode("|", array_unique($comb_string));
							// echo $all_matched[$matched_name]['matched'];
							if ($moreThan1) {
								// 內容移除掉??
								// $all_matched[$matched_name]['namecode'] = array();
								// $all_matched[$matched_name]['accepted_namecode'] = array();
								// $all_matched[$matched_name]['source'] = array();
								$all_matched[$matched_name]['type'] .= " / Undecidable: Multiple cross-ranked matches";
							}
							elseif ($diff_rank) {
								$all_matched[$matched_name]['type'] .= " / Undecidable: Cross-ranked match";
							}
						}
					}
					$srcMatchedAncCnt = array(); // source matched accepted name code count
					$srcMatchedAnc = array(); // source matched accepted name code
					$srcAnc = array(); // source accepted name code
					// $all_matched[$matched_name]['best'] = array();
					if (!empty($all_matched[$matched_name]['accepted_namecode']) && !$undecide) {
		//var_dump($all_matched[$matched_name]);
						$ncs = $all_matched[$matched_name]['namecode'];
						$ancs = $all_matched[$matched_name]['accepted_namecode'];
						$srcs = $all_matched[$matched_name]['source'];
						foreach ($srcs as $src_idx => $src) {
							if ($ncs[$src_idx] === $ancs[$src_idx]) {
								$srcMatchedAncCnt[$src] += 1;
								$srcMatchedAnc[$src][] = $ancs[$src_idx];
							}
							else {
								$srcMatchedAncCnt[$src] += 0;
							}
							$srcAnc[$src][] = $ancs[$src_idx];
						}

						$max_count = 0;
						if (count($srcMatchedAncCnt) > 0) {					
							$original_type = $all_matched[$matched_name]['type'];
							$all_matched[$matched_name]['type'] = '';
							foreach ($srcMatchedAncCnt as $src => $srcMatchedAnc_cnt) {
								if ($srcMatchedAnc_cnt > 1) {
									// $all_matched[$matched_name]['score'] = 'N/A';
									$all_matched[$matched_name]['type'] .= $original_type.' / '."Undecidable: Multiple matched, accepted names|";
									$undecide = true;
								}
								elseif ($srcMatchedAnc_cnt == 0) {
									if (count(array_unique($srcAnc[$src])) > 1) {
										// $all_matched[$matched_name]['score'] = 'N/A';
										$all_matched[$matched_name]['type'] .= $original_type.' / '."Undecidable: Multiple accepted names of matched synonyms|";
										$undecide = true;
									}
									else {
										// $all_matched[$matched_name]['best'][$src] = $srcAnc[$src][0];
										$all_matched[$matched_name]['type'] .= $original_type .'|';
									}
								}
								else {
									// $all_matched[$matched_name]['best'][$src] = $srcMatchedAnc[$src][0];
									$all_matched[$matched_name]['type'] .= $original_type .'|';
								}
							}
						}
					}
				}
			// }
		
			// $all_matched[$matched_name]['taxonRank'] = detRank($all_matched[$matched_name]['matched'], $all_matched[$matched_name]['matched_clean']);
			
			
			// $res[$nidx][] = array_merge(array('score' => round($score/3.5,3)), $all_matched[$matched_name]);
			
			// 最後再把score加上去
			$final_score = array();


			foreach ($all_matched[$matched_name]['matched'] as $mmm) {
				array_push($final_score, $score);
			}

			$all_matched[$matched_name]['score'] = $final_score;
			
			
			$res[$nidx][] = $all_matched[$matched_name];
			if ($best == 'yes' &&  $current_index+1 == $num_highest_score) {
				break;
			}
			$current_index += 1;

		}
	} else {

		/**
		 * 單一字的比對，best與否只有差query語法
		 */
		$scores = array();
		$total_score_array = array();

		$all_matched = queryNameSingle($name, $name_cleaned, $against, $best, $ep);

		// kim: 比對後計算similarity
		
		foreach ($all_matched as $matched_name => $matched) {
			// print_r($matched);
			if (preg_match("/\p{Han}+/u", $name)){
				
				$return_score = nameSimilarityC($matched['common_name'], $name_cleaned);

				$all_matched[$matched_name]['score'] = $return_score;

				if ($best == 'yes') {
					// 如果選擇最佳的話，要把不是分數最高的部分移除
					$removing_array = array();
					$return_score_keys = array_keys($return_score);
					foreach($return_score_keys as $key) {
						if ($return_score[$key] != max($return_score)){
							array_push($removing_array, $key);
							unset($return_score[$key]);
						}
					}

					$return_keys = array_keys($all_matched[$matched_name]);
					unset($return_keys[0]);
					unset($return_keys[1]);
					unset($return_keys[2]);

					// print_r($all_matched[$matched_name]);

					if (max($return_score) != 0) {

						foreach ($return_keys as $rk) {
							if (!in_array($rk, array('name', 'name_cleaned', 'matched_clean', 'type'))){
								$now_keys = array_keys($all_matched[$matched_name][$rk]);						
								foreach($now_keys as $key) {
									if (in_array($key, $removing_array)){
										unset($all_matched[$matched_name][$rk][$key]);
									}
								}
								$all_matched[$matched_name][$rk] = array_values($all_matched[$matched_name][$rk]);
							}
						}
					}

					// 如果有多個最高同分結果 要優先給予種階層

					if (count($return_score) > 1) {
						
						// 應該先確定有沒有存在species階層 且同時有其他種下階層

						if (in_array('species', $all_matched[$matched_name]['taxon_rank'])){

							$removing_array = array();
							$sub_array = array ('subspecies',
												'nothosubspecies',
												'variety',
												'subvariety',
												'nothovariety',
												'form',
												'subform',
												'special-form',
												'race',
												'stirp',
												'morph',
												'aberration',
												'hybrid-formula');

							foreach( array_keys($all_matched[$matched_name]['taxon_rank']) as $key ) {


								if (in_array($all_matched[$matched_name]['taxon_rank'][$key],$sub_array)) {
									array_push($removing_array, $key);
									unset($return_score[$key]);		
								}

							}

							if (count($removing_array) > 0){
								foreach ($return_keys as $rk) {
									if (!in_array($rk, array('name', 'name_cleaned', 'matched_clean', 'type'))){
										$now_keys = array_keys($all_matched[$matched_name][$rk]);						
										foreach($now_keys as $key) {
											if (in_array($key, $removing_array)){
												unset($all_matched[$matched_name][$rk][$key]);
											}
										}
										$all_matched[$matched_name][$rk] = array_values($all_matched[$matched_name][$rk]);
									}
								}
							}
						}
					}

				}
				$return_score = array_values($return_score);
				$scores[$matched_name] = $return_score;
				$total_score_array = array_merge($total_score_array, $return_score);

			} else {

				// echo $name_cleaned;
				$return_score = nameSimilaritySingle($matched['matched_clean'], $name_cleaned);

				$final_score = array();

				foreach($matched['matched'] as $mmm){
					array_push($final_score, $return_score);
				}

				$scores[$matched_name] = $final_score;
				$all_matched[$matched_name]['score'] = $final_score;

				$total_score_array = array_merge($total_score_array, $final_score);
			}

		}

		if (count($total_score_array)>1){
			$highest_score = max($total_score_array);
		}

		foreach ($scores as $matched_name => $score_array) {
			// 合併各單位的type

			$srcMatchedAncCnt = array(); // source matched accepted name code count
			$srcMatchedAnc = array(); // source matched accepted name code
			$srcAnc = array(); // source accepted name code
			$type_array = array(); // source accepted name code

			// 分數一樣且有多個結果
			if (!empty($all_matched[$matched_name]['accepted_namecode'])) {
				$ncs = $all_matched[$matched_name]['namecode'];
				$ancs = $all_matched[$matched_name]['accepted_namecode'];
				$srcs = $all_matched[$matched_name]['source'];
				$now_scores = $all_matched[$matched_name]['score'];
				foreach ($srcs as $src_idx => $src) {
					$now_score = $now_scores[$src_idx];
					// print_r($src);
					if ($ncs[$src_idx] === $ancs[$src_idx]) {
						$srcMatchedAncCnt[$src] += 1;
						$srcMatchedAnc[$src][] = $ancs[$src_idx];
					}
					else {
						$srcMatchedAncCnt[$src] += 0;
					}

					if ($now_score==1){
						$type_array[$src] = 'Full match';
					} elseif (preg_match("/\p{Han}+/u", $matched_name) && $now_score==0.95) {
						// 這邊有可能是fullmatch 但有被加權減掉分數
						// 如果是中文的話 基本上只會有 1 / 0.95 / 0 這幾種分數
						$type_array[$src] = 'Full match';
					} elseif ($now_score < 1 and $matched_name != '') {
						$type_array[$src] = 'Fuzzy match';
					}			

					
					$srcAnc[$src][] = $ancs[$src_idx];
				}
				$max_count = 0;
				if (count($srcMatchedAncCnt) > 0) { 
					$original_type = $type_array[$src];
					$all_matched[$matched_name]['type'] = '';
					foreach ($srcMatchedAncCnt as $src => $srcMatchedAnc_cnt) {
						if ($srcMatchedAnc_cnt > 1) {
							$all_matched[$matched_name]['type'] .= $original_type.'/ '."Undecidable: Multiple matched, accepted names|";
							$undecide = true;
						}
						elseif ($srcMatchedAnc_cnt == 0) {
							if (count(array_unique($srcAnc[$src])) > 1) {
								$all_matched[$matched_name]['type'] .= $original_type.'/ '."Undecidable: Multiple accepted names of matched synonyms|";
								$undecide = true;
							} else {
								$all_matched[$matched_name]['type'] .= $original_type .'|';
							}
						} else {
							$all_matched[$matched_name]['type'] .= $original_type .'|';
						}
					}
				}
			} 

		# END of type

			
			$res[$nidx][] = $all_matched[$matched_name];
		
			// print_r($all_matched[$matched_name]['score']);
			if (count($all_matched[$matched_name]['score']) > 0){
				$current_max_score = max($all_matched[$matched_name]['score']);

				if (!preg_match("/\p{Han}+/u", $matched_name) && $best == 'yes' &&  $current_max_score == $highest_score) {
					break;
				}
			}

		}

	}
}


$etime = microtime(true);


render($res, $format, $etime - $stime, $best, $against, $next_page, $previous_page, $names_str);


function color_class ($idx) {
/*	$colors = array(
		'row_red',
		'row_orange',
		'row_yellow',
		'row_green',
		'row_blue',
		'row_purple',
	);
 */

	$colors = array(
		'row_yellow',
		'row_green',
	);
	return $colors[$idx % count($colors)];
}


function render_table ($data, $time, $hardcsv=false, $next_page, $previous_page,$names_str) {
	header("Content-type: text/html; charset=utf-8");
	$src_conf = read_src_conf();

	global $against, $best;

	$not_show = array(
		'name_cleaned',
		'url_id',
		'a_url_id',
		'best',
		//'score',
		'simple_name'
	);

	echo "<head>";
	echo "<link href='https://fonts.googleapis.com/css?family=Roboto|Slabo+27px&subset=latin,latin-ext' rel='stylesheet' type='text/css'>";
	echo "<script src='https://code.jquery.com/jquery-2.1.4.min.js'></script>";
	echo "<link href='https://maxcdn.bootstrapcdn.com/bootswatch/3.3.7/cerulean/bootstrap.min.css' rel='stylesheet' integrity='sha384-zF4BRsG/fLiTGfR9QL82DrilZxrwgY/+du4p/c7J72zZj+FLYq4zY00RylP9ZjiT' crossorigin='anonymous'>";
	echo "<script src='https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/js/bootstrap.min.js' integrity='sha384-Tc5IQib027qvyjSMfHjOMaLkfuWVxZxUPnCJA7l2mCWNIpG9mGCD8wGNIcPD7Txa' crossorigin='anonymous'></script>";
	echo "<script src='./js/diff.js'></script>";
	echo "</head>";

	$prev_name = "";
	$row_class = "even";

	$serial_no = 0;

	echo "<style>";
	echo "table, tr, td { border:solid 1px black;}\n";
	echo ".row_red { background:#FF9999;}\n";
	echo ".row_orange { background:#FFB547;}\n";
	echo ".row_yellow { background:#FFFF70;}\n";
	echo ".row_green { background:#8DE28D;}\n";
	echo ".row_blue { background:#91DAFF;}\n";
	echo ".row_purple { background:#E9A9FF;}\n";
	echo "tbody:nth-child(odd) {background: #F5F5F5;}\n";
	echo "td {vertical-align : middle !important;}\n";
	echo ".title td {text-align:center;}\n";
	echo "</style>";

	echo "<body>";
	echo "<div class='navbar navbar-fixed-top navbar-default'>";
	echo "<div class='container-fluid'>";
	echo "<div class='navbar-header'>";
	echo "<button type='button' class='navbar-toggle collapsed' data-toggle='collapse' data-target='#bs-example-navbar-collapse-2'>";
	echo "<span class='sr-only'>Toggle navigation</span>";
	echo "<span class='icon-bar'></span>";
	echo "<span class='icon-bar'></span>";
	echo "<span class='icon-bar'></span>";
	echo "</button>";
	echo "<a class='navbar-brand' href='index.html' style='color:#fff' >NomenMatch</a>";
	echo "</div>";
	echo "<div class='collapse navbar-collapse' id='bs-example-navbar-collapse-2'>";
	echo "<ul class='nav navbar-nav'>";
	echo "<li><a href='about.html'>About</a></li>";
	echo "<li><a href='index.html'>Match</a></li>";
	echo "<li><a href='howto.html'>How-To</a></li>";
	echo "<li><a href='api-doc.html'>API</a></li>";
	echo "</ul>";
	echo "</div>";
	echo "</div>";
	echo "</div><div class='container'><br/><br/><br/></div>";

	echo "<div class='container' style='width:98%;'>";
	echo "<h1 class='navbar-brand m-b-0'>Matching results</h1>";
	echo "<p>";
	echo "query time: " . round($time, 3) . " s<br/>";
	echo "memory usage: " . round(memory_get_usage(true) / (1024 * 1024), 1) . " MB<br/>";
	echo "matched diff: <span style='color:blue;'>added</span> <span style='color:grey;'>common</span><br/>";
	echo "source: <span style='color:#DC143C;'>accepted</span> <span>invalid</span>";
	echo "</p>";
	if ($previous_page!=''){
		echo "<button onclick='$(`input[name=page]`).val(" .$previous_page. "); $(`form`).submit();'> Previous page</button>";
	}
	if ($next_page!=''){
		echo "<button onclick='$(`input[name=page]`).val(" .$next_page. "); $(`form`).submit();'> Next page</button>";
	}
	//echo $names;
	//if (count($names)>20){
	//	foreach(range(1,(count($names)/20)+1) as $i) {
	//		echo count($names);
	//			echo "<div id='page-".$i."' style='display: none'>".implode("|",array_slice($names, ($i-1)*20, 20))."</div>";
	//	}
	//}
	echo "<form action='api.php' method='POST'>";
	echo "<input type='hidden' name='names' value='".$names_str."'>";
	echo "<input type='hidden' name='source' value='".$against."'>";
	echo "<input type='hidden' name='best' value='".$best."'>";
	echo "<input type='hidden' name='page' value=''>";
	//echo "<input type='submit'>";
	echo "</form>";
	echo "<div class='table-responsive'>";

	echo "<table class='table table-bordered' style='width: 95vw'>";

	$tmp_data0 = $data[0][0];
	foreach ($not_show as $ns) {
		unset($tmp_data0[$ns]);
	}

	// $columns = array_keys($tmp_data0);

	// print_r($columns );

	$columns = array('name','matched_clean','score','matched','common_name','accepted_namecode','namecode','name_status','source',
		'kingdom','phylum','class','order','family','genus','taxon_rank','type','id'
	);

	$titles = array('search<br/>term','matched<br/>clean','score','matched','common<br/>name','accepted<br/>namecode','namecode',
					'name<br/>status','source','kingdom','phylum','class','order','family','genus','taxon<br/>rank','match<br/>type');
	
	// 標題
	echo "<tbody><tr class='title'><td>" . implode("</td><td>", $titles) . "</td></tr></tbody>\n";
	// $prev_score = -100;
	/*
	unset($columns[0]); // score
	unset($columns[1]); // name
	unset($columns[2]); // matched_clean
	unset($columns[15]); // type
	unset($columns[17]); // id
	print_r($columns);*/

	// $not_col_array = array('score','name','matched_clean','type','id');
	$not_col_array = array('name','matched_clean','type','id');
	
	// 內文
	foreach ($data as $nidx => $name_d) {
		foreach ($name_d as $d) {

			// echo '=======';
			// print_r($d);
			// echo '=======';

			$source_for_type = $d['source'];

			$ncs = $d['namecode'];
			$ancs = $d['accepted_namecode'];
			$sources = $d['source'];
			$url_ids = $d['url_id'];
			$aurl_ids = $d['a_url_id'];

			$html_ncs = array();
			$html_ancs = array();
			$html_sources = array();
			$url_anc_srcs = array();

			foreach ($sources as $src_idx => $src) {
				// TODO: this part must be implemented dynamicaly reading configurations from some file
				if (!empty($src_conf[$src]['url_base'])) {
					$url_base = $src_conf[$src]['url_base'];
				}
				else {
					$url_base = "http://example.org/species/id/";
				}

				$url = $url_base . $url_ids[$src_idx];
				$aurl = $url_base . $aurl_ids[$src_idx];

				$html_ncs[$src_idx] = "<a target='_blank' href='$url'>" . $ncs[$src_idx] . "</a>";
				if ($ancs[$src_idx]){
					$html_ancs[$src_idx] = "<a target='_blank' href='$aurl'>" . $ancs[$src_idx] ."</a>";
				} else {
					$html_ancs[$src_idx] = $ancs[$src_idx];
					$html_ncs[$src_idx] = $ncs[$src_idx];

				}
				$url_anc_srcs[$src][$ancs[$src_idx]] = "<a target='_blank' href='$aurl'>" . $ancs[$src_idx] ."</a>";

				if ($ncs[$src_idx] == $ancs[$src_idx]) {
					$html_sources[$src_idx] = "<font color='#DC143C'>$src</font>";
				}
				else {
					$html_sources[$src_idx] = $src;
				}
			}

			$d['namecode'] = $html_ncs;
			$d['accepted_namecode'] = $html_ancs;
			$d['source'] = $html_sources;
			echo "<tbody>";
			// $d 是每個matched_clean的集合
			$d['name'] = htmlentities($d['name']);
			$d['name'] = "<span name_cleaned='" . $d['name'] . "'>" . $d['name'] . "</span>";

			$rowspan = count($d['accepted_namecode']);

			// 第一行
			// echo "<tr class='row_result' id='row_".$serial_no."'><td rowspan='".$rowspan."'>".$d['score']."</td><td rowspan='".$rowspan."'>";
			echo "<tr class='row_result' id='row_".$serial_no."'><td rowspan='".$rowspan."'>";
			echo $d['name']."</td>";
			echo "<td rowspan='".$rowspan."'>".$d['matched_clean']."</td>";
			foreach ($columns as $c) {
				if (!in_array($c, $not_col_array)){
					if (($c == 'matched' && !preg_match("/\p{Han}+/u", $d['name'])) || ($c == 'common_name' && preg_match("/\p{Han}+/u", $d['name']))){
						if (!$d[$c]) {
							echo "<td class='matched'></td>";
						} else {
							echo "<td class='matched'>".$d[$c][0]."</td>";
						}
					} else {
						if (!$d[$c]) {
							echo "<td></td>";
						} else if ($c == 'source'){
							echo "<td>".str_replace('taicol_2','taicol',$d[$c][0])."</td>";
						} else {
							echo "<td>".$d[$c][0]."</td>";
						}
					}
				}
			}

			$d['type'] = explode('|',$d['type']);
			$d['type'] = array_filter($d['type']); // 移除空值
			
			$source_count_values = array_count_values($source_for_type);
						
			// if (count(array_unique($d['type'])) >1 && count(explode(" ", $d['matched_cleaned'])) == 1){
			// 	echo "<td rowspan='".$source_count_values[$source_for_type[0]]."'>".$d['type'][0]."</td>";
			// }
			if (count(explode(" ", $d['matched_cleaned'])) == 1){
				echo "<td rowspan='".$source_count_values[$source_for_type[0]]."'>".$d['type'][0]."</td>";
			} 
			else {
				echo "<td rowspan='".$rowspan."'>".$d['type'][0]."</td>";
			}
			echo "</tr>\n";

			// 其他行
			if ($rowspan > 1){
				$current_source_index = 0;
				$type_count = $source_count_values[$source_for_type[0]];
				foreach (range(1, $rowspan-1) as $n) {
					echo "<tr class='row_result' id='row_".$serial_no."'>";
					foreach ($columns as $c) {
						if (!in_array($c, $not_col_array)){
							if (($c == 'matched' && !preg_match("/\p{Han}+/u", $d['name']))|| ($c == 'common_name' && preg_match("/\p{Han}+/u", $d['name']))){
								echo "<td class='matched'>".$d[$c][$n]."</td>";						
							} else if ($c == 'source'){
								echo "<td>".str_replace('taicol_2','taicol',$d[$c][$n])."</td>";
							} 
							else {
								echo "<td>".$d[$c][$n]."</td>";
							}
						}
					}
					// type
					// if (count(array_unique($d['type'])) >1 && count(explode(" ", $d['matched_cleaned'])) == 1){
					if ($n == $type_count){
						$current_source_index += 1;
						$type_count += $source_count_values[$source_for_type[$n]];
						echo "<td rowspan='".$source_count_values[$source_for_type[$n]]."'>".$d['type'][$current_source_index]."</td>";
					}
					// }
					// if (count(array_unique($d['type'])) >1 && count(explode(" ", $d['matched_cleaned'])) == 1){
					// 	if ($n == $type_count){
					// 		$current_source_index += 1;
					// 		$type_count += $source_count_values[$source_for_type[$n]];
					// 		echo "<td rowspan='".$source_count_values[$source_for_type[$n]]."'>".$d['type'][$current_source_index]."</td>";
					// 	}
					// }
					echo "</tr>\n";	
				}
				
			}
			echo "</tbody>";
		}
	}
	echo "</table>\n";
	echo "</div>\n";
	echo "<script src='./js/diffName.js?v1'></script>";

	echo "</div>";
	echo "</body>";
}


/*
function render_plain ($data, $time) {
	header("Content-type: text/plain; charset=utf-8");
	echo "query time: " . $time . "s\n";
	echo implode("\t", array_keys($data[0][0])) . "\n";
	foreach ($data as $d) {
		foreach ($d as $col) {
			foreach ($col as $idx => $val) {
				if (is_array($val)) {
					$new_val = implode("|", $val);
				}
				else {
					$new_val = implode("|", explode("|", trim($val, "\r\n ")));
				}
				$col[$idx] = $new_val;
			}
			echo implode("\t", $col) . "\n";
		}
	}
}
*/

function render_csv ($data) {
	header("Content-type: text/csv; charset=utf-8");
	header("Content-Disposition: attachment; filename=results.csv");
	header("Pragma: no-cache");
	header("Expires: 0");
	$utf8_bom = "\xEF\xBB\xBF"; 
	echo $utf8_bom;
	// echo "sep=\t\n";

	$header = array(
		'search_term',
		'name_cleaned',
		'matched_clean',
		'score',
		'matched',
		'simple_name',
		'common_name',
		'accepted_namecode',
		'namecode',
		'name_status',
		'source',
		'kingdom',
		'phylum',
		'class',
		'order',
		'family',
		'genus',
		'taxon_rank',
		'match_type');

	$columns = $header;
	//unset($columns[16]); // match_type
	unset($columns[0]); // search_term
	unset($columns[1]); // name_cleaned
	unset($columns[2]); // matched_clean
	// unset($columns[0]); // score

	$results = array();
	array_push($results, $header);

	foreach($data as $d){
		foreach($d as $dsub){
			$types = explode('|',$dsub['type']);
			$types = array_filter($types); // 移除空值

			$source_for_type = $dsub['source'];
			$source_count_values = array_count_values($source_for_type);

			$tmp_keys = array_keys($dsub['matched']);
			if ($tmp_keys){
				foreach($tmp_keys as $k){
					$tmp = array();
					// $tmp['score'] = $dsub['score'];
					$tmp['search_term'] = $dsub['name'];
					$tmp['name_cleaned'] = $dsub['name_cleaned'];
					$tmp['matched_clean'] = $dsub['matched_clean'];
					foreach($columns as $c){
						if ($c == 'source'){
							$tmp[$c] = str_replace('taicol_2','taicol',$dsub[$c][$k]);
						} else {
							$tmp[$c] = $dsub[$c][$k];
						}
					}

					$current_source_index = 0;

					if ($k == $source_count_values[$source_for_type[$current_source_index]]){
						$current_source_index += 1;
					}
					$tmp['match_type'] = $types[$current_source_index];
					array_push($results, $tmp);
				}
			} else {
				$tmp = array();
				// $tmp['score'] = $dsub['score'];
				$tmp['search_term'] = $dsub['name'];
				$tmp['name_cleaned'] = $dsub['name_cleaned'];
				$tmp['matched_clean'] = $dsub['matched_clean'];
				$tmp['score'] = '';
				$tmp['matched'] = '';
				$tmp['simple_name'] = '';
				$tmp['common_name'] = '';
				$tmp['accepted_namecode'] = '';
				$tmp['namecode'] = '';
				$tmp['name_status'] = '';
				$tmp['source'] = '';
				$tmp['kingdom'] = '';
				$tmp['phylum'] = ''; 
				$tmp['class'] = '';
				$tmp['order'] = '';
				$tmp['family'] = '';
				$tmp['genus'] = '';
				$tmp['taxon_rank'] = '';
				$tmp['match_type'] = $dsub['type'];
				array_push($results, $tmp);
			}
		}
	}

	$f = fopen('php://output', 'w');

    foreach ($results as $line) {
        fputcsv($f, $line, $delimiter=",");
    }
}

function render_json ($data, $time, $best, $against, $next_page, $previous_page, $names_str) {
	header('Content-Type: application/json; charset=utf-8');

	$columns = array(
		'score',
		'matched',
		'simple_name',
		'common_name',
		'accepted_namecode',
		'namecode',
		'name_status',
		'source',
		'kingdom',
		'phylum',
		'class',
		'order',
		'family',
		'genus',
		'taxon_rank');
	
	$results = array();
	//$test = array();

	foreach($data as $d){
		$tmp_res = array();
	
		foreach($d as $dsub){
			$types = explode('|',$dsub['type']);
			$types = array_filter($types ); // 移除空值

			$source_for_type = $dsub['source'];
			$source_count_values = array_count_values($source_for_type);

			$tmp_array = array(
				'search_term' => $dsub['name'],
				'name_cleaned' => $dsub['name_cleaned'],
				'matched_clean' => $dsub['matched_clean'],
				// 'score' => $dsub['score']
			);
			$tmp_keys = array_keys($dsub['matched']);
			$tmp_results = array();
			foreach($tmp_keys as $k){
				$tmp = array();

				foreach($columns as $c){
					if ($c == 'source'){
						$tmp[$c] = str_replace('taicol_2','taicol',$dsub[$c][$k]);
					} else {
						$tmp[$c] = $dsub[$c][$k];
					}
				}

				$current_source_index = 0;

				if ($k == $source_count_values[$source_for_type[$current_source_index]]){
					$current_source_index += 1;
				}
				$tmp['match_type'] = $types[$current_source_index];
				array_push($tmp_results, $tmp);
			}
			$tmp_array['results'] = $tmp_results;
			//array_push($results, $tmp_array);
			array_push($tmp_res, $tmp_array);
		}
		array_push($results, $tmp_res);
	}

	//print_r($test);

	if ($against=='taicol_2') {
		$against = 'taicol';
	}

	echo json_encode(array(
		'query' => array(
			'query_time' => $time,
			'best' => $best,
			'source' => $against,
			),
		'data' => $results,
		));
}


function render ($data, $format='table', $time, $best, $against, $next_page, $previous_page,$names_str) {
	$func_name = "render_" . $format;
	if ($func_name=='render_table'){
		render_table($data, $time, $hardcsv=false, $next_page, $previous_page,$names_str);
	} else if (function_exists($func_name)) {
		call_user_func($func_name, $data, $time, $best, $against, $next_page=$next_page, $previous_page=$previous_page,$names_str);
	}
	else {
		render_table($data, $time, $hardcsv=false, $next_page, $previous_page,$names_str);
	}
}


// 計算score: 根據不同重要性給予權重
function nameSimilarity ($matched, $name, $type=null) {

	if ($matched == 'N/A') return 0;

	//$matched_cleaned = canonical_form($matched, true);
	$matched_cleaned = $matched;

	if (empty($matched_cleaned)) return 0;

	$score = 3;

	// kim: 比對後學名 & 原本輸入學名 拆成list
	$parts1 = explode(" ", $matched_cleaned);
	$parts2 = explode(" ", $name);

	if (count($parts1) === count($parts2)) { // kim: 相等的話 latin_genus, latin_s1, 其他rank等都相同
		// kim: 如果levenshtein距離小於3 or type是full match, score + 0.5
		if ((levenshtein($matched_cleaned, $name) <= 3)||(preg_match('/full/i', $type))) {
			$score += 0.5;
		}
		for ($pidx=0; $pidx<count($parts1); $pidx++) { // 如果排序不一樣, 扣1.5
			if ($parts1[$pidx][0] != $parts2[$pidx][0]) {
				$score -= 1.5;
			}
		}
	}

	// kim: 如果是sound or look like的匹配, 扣掉標準化後的levenshtein
	if (preg_match('/sound|look/i', $type)) {
		// $score -= 0.05;
		$score -= levenshtein($name, $matched_cleaned) / 20;
	}


	$penalty = 0;
	if (count($parts1) == count($parts2)) {
		$penalty = 0;
	}
	elseif (count(array_unique($parts1)) != count(array_unique($parts2))) {
		$penalty = 0.01;
	} 

	if (count(array_unique($parts1)) > count(array_unique($parts2))) {		
		$penalty += 0.5;
//		$penalty = $penalty / (3 - count(array_unique(array_slice($parts1, 1))));
	}
	elseif (count(array_unique($parts1)) < count(array_unique($parts2))) {
		if (count(array_unique($parts1)) < count($parts1)) {
			$penalty -= 0.0;
		}
		else {
			$penalty -= 0.2;
		}
//		$penalty = ($penalty < 0)?0:$penalty;
	}


	// 屬的比對, 加權*2
	$score -= (2 * levenshtein($parts1[0] /* Genus */, $parts2[0]) / strlen($parts1[0]));

	// 取得屬以下的array
	$sub_parts1 = array_slice($parts1, 1); 
	$sub_parts2 = array_slice($parts2, 1);

	$total_err = 0;
	$min_errs = array();
	foreach ($sub_parts2 as $sp2_idx => $sp2) {
//		$min_err = 999.0;
//		$min_errs[$sp1] = 999.0;
		if (is_null($min_errs[$sp2])) { 
			$min_errs[$sp2] = 999.0; // kim: error default = 999
		}
		foreach ($sub_parts1 as $sp1_idx => $sp1) {
			// kim: treat_word -> 可能互相替換的字視為minor error
			if ((levenshtein($sp1, $sp2) <= 3)&&(treat_word($sp1[0])==treat_word($sp2[0]))) {
//				echo "<xmp>$sp1 $sp2 ". levenshtein($sp1, $sp2) . "</xmp>";
				$tmp_err = (float) levenshtein($sp1, $sp2) / (float) strlen($sp1);
//				$min_err = min($min_err, $tmp_err);
				$min_errs[$sp2] = min($min_errs[$sp2], $tmp_err);
//				echo "$sp1, $sp2, $min_err, $total_err, $matched<br/>";
			}
			else {
//				$min_err = 0.5;
				if (count($sub_parts1) != count($sub_parts2)) {
					$factor = count($sub_parts1) + count($sub_parts2) - ($sp1_idx + $sp2_idx + 1);
				}
				else {
					$factor = 1;
				}
/*
echo "<xmp>";
var_dump(array($sp2, $sp1, $factor, min($min_errs[$sp2], 1 / $factor)));
echo "</xmp>";
//*/
				$min_errs[$sp2] = min($min_errs[$sp2], 1 / $factor);
/*
echo "<xmp>";
var_dump(array($sub_parts1, $sub_parts2));
echo "</xmp>";
//*/
			}

		}
	}
		/*
		echo "<xmp>";
		var_dump($min_errs);
		echo "</xmp>";
		//*/
	foreach (array_unique($sub_parts2) as $sp2) {
		$total_err += $min_errs[$sp2];
	}

//	$score -= (($total_err>1.5)?1.5:$total_err);
	$score -= $total_err;
		/*
		echo "<xmp>";
		var_dump(array($matched, $penalty, $score, $score - $penalty));
		echo "</xmp>";
		//*/
	// echo $score;
	// echo '----';
	// echo $penalty;

	return round(($score - $penalty)/3.5,3);
}


// 種以上階層的score計算

function nameSimilaritySingle($matched_cleaned, $name){
	
	if ($matched_cleaned == 'N/A' or empty($matched_cleaned)) {
		return 0;
	} else {
		$penalty = levenshtein($matched_cleaned, $name) / max(strlen($matched_cleaned), strlen($name));
		return round((1 - $penalty), 3);
	} 
}


// 計算中文名的分數
function nameSimilarityC($matched_cleaned, $name){


	if ($matched_cleaned == 'N/A' or empty($matched_cleaned)) {

		// foreach ($ as $mc){
		// 	$final_score = array_merge(array(0), array(0));
		// }
		return array(0);

	} else {
		// 如果是中文的話, 先把common_name_c(matched_clean)用逗號分隔,分別計算並取最高值
		$common_name_score_array = array();

		foreach ($matched_cleaned as $mc){

			$common_name_array = explode(",", $mc);
			$common_name_score = array();
			$current_count = 0;

			foreach ($common_name_array as $cn) {
				// $penalty = levenshtein($cn, $name) / max(strlen($cn), strlen($name));
				// $penalty 越低代表越相近
				// 如果 $current_count > 0 代表是別名 $penalty要給較高的權重
				$penalty = levenshtein(treat_word_c($cn), treat_word_c($name)) / max(strlen($cn), strlen($name));
				if ($current_count > 0){
					// 不能用乘法 因為原本就是0 
					$penalty += 0.05;
				}
				array_push($common_name_score, 1 - $penalty);
				$current_count += 1;
				
			}
			
			array_push($common_name_score_array,  max($common_name_score));
		}

		return $common_name_score_array;

	} 
}

/** 
 *  @todo 這邊需不需要加上其他種下階層? or 改成直接從匯入的資料取得
*/
function detRank ($sciname, $sciname_clean) {
	$numParts = count(explode(" ", $sciname_clean));
	switch ($numParts) {
		case 2:
			return 'species';
			break;
		case 3:
			if (preg_match('/ var\.? /', $sciname)) {
				return 'variety';
			}
			else {
				return 'subspecies';
			}
			break;
		default:
			return 'unknown';
	}
}



function array_sort_by_column(&$arr, $col, $dir = SORT_STRING) {
    $sort_col = array();
    foreach ($arr as $key => $row) {
        $sort_col[$key] = $row[$col];
    }

    array_multisort($sort_col, $dir, $arr);
}



?>
