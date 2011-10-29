<?php

/*
 * Copyright (c) 2011 sakuratan.biz
 *
 * Permission is hereby granted, free of charge, to any person obtaining
 * a copy of this software and associated documentation files (the "Software"),
 * to deal in the Software without restriction, including without limitation
 * the rights to use, copy, modify, merge, publish, distribute, sublicense,
 * and/or sell copies of the Software, and to permit persons to whom
 * the Software is furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL
 * THE AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING
 * FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS
 * IN THE SOFTWARE.
 */

/*
 * 設定
 */

// JSON をキャッシュするディレクトリ
define('JSON_CACHE_DIR', dirname(__file__) . DIRECTORY_SEPARATOR . 'json');

// 作業用ディレクトリ
define('WORK_DIR', dirname(__file__) . DIRECTORY_SEPARATOR . 'work');

// JSON キャッシュの生存期間（秒）
define('JSON_CACHE_LIFETIME', 3600);

// true なら JavaScript の自動コンパイルを行う
define('COMPILE_JS', false);

// JSON キャッシュの更新発生確率
// JSON キャッシュの生存期間を過ぎたファイルに対して JavaScript から
// 更新指示を出す確率で、0.0〜1.0で指定してください。
// min(1 / 閑散期の1分あたりの PV (ページビュー), 1.0) ぐらいの値が適切です。
define('JS_JSON_UPDATE_PROBARILITY', 1.0);

// JavaScript から API を呼び出す際の baseURI
// NULL の場合 $_SERVER[PHP_SELF] から適当な値を使用しますので通常は NULL で
// 問題無いと思います。
// セットする場合、http://example.com/widget/api.php にこのスクリプトを
// 置く場合は /widget/ になります。
define('JS_BASEURI', NULL);

// true なら RSS テンポラリファイルを残す、デバッグ用
define('KEEP_RSS_TEMPORARY_FILES', false);

// 

/*
 * HTML 文字列からタグ等を取り除く
 */
function cleanup_html_string($s) {
    return trim(html_entity_decode(preg_replace('/<[^>]*?>/', '', $s), ENT_QUOTES, 'UTF-8'));
}

/*
 * intconma 形式の HTML 文字列を数値に変換
 */
function cleanup_html_number($s) {
    return (float)cleanup_html_string(str_replace(',', '', $s));
}

/*
 * YYYY/MM/DD 形式の HTML 文字列を ISO-8601 形式に変換
 */
function cleanup_html_date($s) {
    return str_replace('/', '-', cleanup_html_string($s));
}

/*
 * Amazon の画像オプションを削除
 */
function reset_image_flags($s) {
    return cleanup_html_string(preg_replace('/\._.*?_(\.[^\.]*)$/', '\\1', $s));
}

/*
 * Amazon の RSS をパース
 */
function parse_rss_string($ctx) {
    $rss = simplexml_load_string($ctx);

    $items = array();
    foreach ($rss->channel->item as $item) {
	$datum = array();

	// asin は guid から調べる
	$guid = (string)($item->guid);
	$datum['asin'] = preg_replace('/^.*_/', '', $guid);

	// title
	if ($item->title) {
	    $datum['title'] = preg_replace('/^#\d*:\s*/', '', (string)($item->title));
	} else {
	    $datum['title'] = '';
	}

	if ($item->description) {
	    $html = (string)($item->description);

	    // 最初の img タグが画像
	    if (preg_match('/<img src="(.*?)"/', $html, $mo)) {
		$datum['image'] = reset_image_flags($mo[1]);
	    }

	    foreach (preg_split(',<br\s*/?>,', $html) as $line) {
		// 作者とか
		if (preg_match(',<span class="riRssContributor">(.*)</span>,', $line)) {
		    foreach (preg_split(',<span class=["\']byLinePipe["\']>\|</span>,', $line) as $s) {
			if (!isset($datum['info'])) {
			    $datum['info'] = array();
			}
			$datum['info'][] = cleanup_html_string($s);
		    }
		    continue;
		}

		// 新品の値段
		if (preg_match('/新品&#65306;/u', $line)) {
		    if (preg_match('|<strike>￥\s*([\d,]+)</strike>|u', $line, $mo)) {
			$datum['list_price'] = cleanup_html_number($mo[1]);
		    }

		    if (preg_match('|<b>￥\s*([\d,]+)\s*-\s*￥\s*([\d,]+)</b>|u', $line, $mo)) {
			$datum['lower_new_price'] = cleanup_html_number($mo[1]);
			$datum['upper_new_price'] = cleanup_html_number($mo[2]);
		    } elseif (preg_match('|<b>￥\s*([\d,]+)</b>|u', $line, $mo)) {
			$datum['new_price'] = cleanup_html_number($mo[1]);
			if (!isset($datum['list_price']) && isset($datum['new_price'])) {
			    $datum['list_price'] = $datum['new_price'];
			}
		    }
		    continue;
		}

		// 中古品の値段
		if (preg_match('|中古品を見る.*<span class="price">￥\s*([\d,]+)</span>|u', $line, $mo)) {
		    $datum['used_price'] = cleanup_html_number($mo[1]);
		    continue;
		}

		// ゲームのプラットフォーム
		if (preg_match(',<b>プラットフォーム:</b>(.*)$,', $line, $mo)) {
		    $datum['platform'] = cleanup_html_string($mo[1]);
		    if (preg_match('/<img src="(.*?)"/', $mo[1], $mo)) {
			$datum['platform_image'] = cleanup_html_string($mo[1]);
		    }
		}

		// 発売日かリリース日
		if (preg_match(',発売日: (\d+/\d+/\d+),u', $line, $mo)) {
		    $datum['date'] = cleanup_html_date($mo[1]);
		    continue;
		} elseif (preg_match(',出版年月: (\d+/\d+/\d+),u', $line, $mo)) {
		    $datum['date'] = cleanup_html_date($mo[1]);
		    continue;
		}
	    }
	}

	$items[] = $datum;
    }

    return array(
	'items' => $items,
	'expire' => gmdate('r', time() + JSON_CACHE_LIFETIME),
    );
}

// 

/*
 * mkdir -p
 */
function mkdir_p($dir, $mode=0777) {
    if (!is_dir($dir)) {
	if (!mkdir($dir, $mode, true)) {
	    return FALSE;
	}
    }
    return TRUE;
}

// 

function google_closure($js) {
    $ch = curl_init('http://closure-compiler.appspot.com/compile');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
    curl_setopt($ch, CURLOPT_BINARYTRANSFER, TRUE);
    curl_setopt($ch, CURLOPT_POST, TRUE);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query(array(
	'js_code' => $js,
	'compilation_level' => 'SIMPLE_OPTIMIZATIONS',
	'output_info' => 'compiled_code',
	'output_format' => 'text',
    )));
    $ctx = curl_exec($ch);
    if (!$ctx) {
	trigger_error('Google closure API call failed', E_USER_ERROR);
    }
    curl_close($ch);
    return $ctx;
}

function minify_script($src, $dest) {
    if (file_exists($dest)) {
	// 存在して書き込めない時は何もしない
	if (!is_writable($dest)) {
	    return;
	}

	// タイムスタンプを比較
	$mtime = filemtime($dest);
	if (filemtime($src) <= $mtime && filemtime(__file__) <= $mtime) {
	    return;
	}
    }

    // ロックする
    $lock_path = WORK_DIR . DIRECTORY_SEPARATOR . 'minify.lock';
    $lock = fopen($lock_path, 'w+');
    if (!flock($lock, LOCK_EX)) {
	exit(1);
    }
    fwrite($lock, "0\n");	// For NFS
    fflush($lock);

    // もう一度タイムスタンプをチェック
    clearstatcache();
    if (file_exists($dest)) {
	$mtime = filemtime($dest);
	if (filemtime($src) <= $mtime && filemtime(__file__) <= $mtime) {
	    @unlink($lock_path);
	    fclose($lock);
	    return;
	}
    }

    // 設定値で上書き
    $lines = @file($src);
    if ($lines === FALSE) {
	@unlink($lock_path);
	fclose($lock);
	return;
    }

    $nlines = count($lines);
    for ($i = 0; $i < $nlines; ++$i) {
	if (preg_match('/^\s*(baseuri|jsonUpdateProbability)\s*:\s*.*?(,?)$/',
	    $lines[$i], $mo)) {
	    switch ($mo[1]) {
	    case 'baseuri':
		$baseuri = JS_BASEURI;
		if (is_null($baseuri)) {
		    if (isset($_SERVER['PHP_SELF'])) {
			$re = '/' . preg_quote(basename(__file__), '/') . '$/';
			$baseuri = preg_replace($re, '', $_SERVER['PHP_SELF']);
		    } else {
			$baseuri = '';
		    }
		}
		$var = json_encode($baseuri);
		break;
	    case 'jsonUpdateProbability':
		$var = json_encode(JS_JSON_UPDATE_PROBARILITY);
		break;
	    }
	    $lines[$i] = "{$mo[1]}:{$var}{$mo[2]}\n";
	}
    }

    $ctx = "// AZlink/TinyWidget\n" .
	   "// This file licensed under the MIT license.\n" .
	   google_closure(implode('', $lines));
    file_put_contents($dest, $ctx);

    @unlink($lock_path);
    fclose($lock);
}

// 

/*
 * 403 Forbidden
 */
function forbidden() {
    header('HTTP/1.1 403 Forbidden');
    header('Status: 403 Forbidden');
    echo '<html><head><title>403 Forbidden</title></head>';
    echo '<body><h1>403 Forbidden</h1></body></html>';
}

/*
 * $json_path が存在し生存期間内なら出力する
 */
function put_json_if_available($json_path) {
    $ctx = file_get_contents($json_path);
    if ($ctx === FALSE)
	return FALSE;

    $data = @json_decode($ctx);
    if (!$data || !($data->expire))
	return FALSE;

    if (strtotime($data->expire) < time())
	return FALSE;

    header('Content-Type: application/json');
    file_put_contents($ctx);
    return TRUE;
}

/*
 * JSON レスポンスを出力する
 */
function json_response($node) {
    // パラメータチェックとか
    $node = trim($node, '/');
    $json_path = JSON_CACHE_DIR . DIRECTORY_SEPARATOR . $node . '.js';
    if (basename($json_path) == '.js') {
	forbidden();
	trigger_error("Invalid node {$node}", E_USER_ERROR);
    }

    // json があれば出力して終わり
    if (put_json_if_available($json_path)) {
	exit(0);
    }

    // rss を読み込む準備
    if (!mkdir_p(WORK_DIR)) {
	forbidden();
	exit(1);
    }
    $rss_path = WORK_DIR . DIRECTORY_SEPARATOR . rawurlencode($node);
    $fh = fopen($rss_path, "wb");
    if (!$fh) {
	forbidden();
	exit(1);
    }

    // ロック
    if (!flock($fh, LOCK_EX)) {
	forbidden();
	exit(1);
    }
    rewind($fh);
    fwrite($fh, "0");

    // flock してからファイルが更新されていれば出力して終わり
    if (put_json_if_available($json_path)) {
	fclose($fh);
	exit(0);
    }

    // CURL で RSS を読み込む
    $ch = curl_init("http://www.amazon.co.jp/rss/{$node}");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
    curl_setopt($ch, CURLOPT_BINARYTRANSFER, TRUE);
    $ctx = curl_exec($ch);
    if ($ctx === FALSE) {
	curl_close($ch);
	@unlink($rss_path);
	fclose($fh);
	forbidden();
	exit(1);
    }
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code != 200) {
	@unlink($rss_path);
	fclose($fh);
	forbidden();
	exit(1);
    }

    // json を出力
    if (!mkdir_p(dirname($json_path))) {
	forbidden();
	exit(1);
    }
    $json = json_encode(parse_rss_string($ctx));
    file_put_contents($json_path, $json);

    // ロック解除
    if (KEEP_RSS_TEMPORARY_FILES) {
	ftruncate($fh, 0);
	rewind($fh);
	fwrite($fh, $ctx);
    } else {
	@unlink($rss_path);
    }
    fclose($fh);

    // json を出力
    header('Content-Type: application/json');
    echo $json;
}

// 

/*
 * MAIN
 */

if (COMPILE_JS) {
    minify_script('tinywidget.js', 'tinywidget.min.js');
}

// node パラメータがあれば JSON 出力
if (isset($_GET['node']) && $_GET['node']) {
    json_response($_GET['node']);
}

// vim:ts=8:sw=4: