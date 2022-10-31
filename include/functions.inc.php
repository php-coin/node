<?php

require_once __DIR__ . "/common.functions.php";

// simple santization function to accept only alphanumeric characters
function san($a, $b = "")
{
    $a = preg_replace("/[^a-zA-Z0-9".$b."]/", "", $a);

    return $a;
}

function san_ip($a)
{
    $a = preg_replace("/[^a-fA-F0-9\[\]\.\:]/", "", $a);
    return $a;
}

function san_host($a)
{
    $a = @preg_replace("/[^a-zA-Z0-9\.\-\:\/]/", "", $a);
    return $a;
}

// api  error and exit
function api_err($data, $verbosity = 4)
{
    global $_config;
    _log("api_err: ".json_encode($data),$verbosity);

    if (!headers_sent()) {
        header('Content-Type: application/json');
	    header('Access-Control-Allow-Origin: *');
    }
    echo json_encode(["status" => "error", "data" => $data, "coin" => COIN, "version"=>VERSION, "network"=>NETWORK, "chain_id"=>CHAIN_ID]);
	//Nodeutil::measure();
    exit;
}

// api print ok and exit
function api_echo($data, $verbosity=5)
{
    global $_config;

    if (!headers_sent()) {
        header('Content-Type: application/json');
	    header('Access-Control-Allow-Origin: *');
    }
    _log("api_echo: " . json_encode($data), $verbosity);
    echo json_encode(["status" => "ok", "data" => $data, "coin" => COIN, "version"=>VERSION, "network"=>NETWORK, "chain_id"=>CHAIN_ID]);
	//Nodeutil::measure();
    exit;
}

// log function, shows only in cli atm
function _log($data, $verbosity = 0)
{
    global $_config;
    $date = date("[Y-m-d H:i:s]");
    $trace = debug_backtrace();
    $loc = count($trace) - 1;
    $file = substr($trace[$loc]['file'], strrpos($trace[$loc]['file'], "/") + 1);

    global $log_prefix;

	$pid = getmypid();

	$dev_part = '';
	if(DEVELOPMENT) {
		if(!empty(PeerRequest::$ip)) {
			$dev_part .= "[".PeerRequest::$ip."]";
		}
		if(!empty(PeerRequest::$requestId)) {
			$dev_part .= "[".PeerRequest::$requestId."]";
		}
		$ua = @$_SERVER['HTTP_USER_AGENT'];
		$dev_part .= " $ua";
	}

    $res = "$date [$verbosity] [$pid] $dev_part $log_prefix ".$file.":".$trace[$loc]['line'];

    if (!empty($trace[$loc]['class'])) {
        $res .= "---".$trace[$loc]['class'];
    }
    if (!empty($trace[$loc]['function']) && $trace[$loc]['function'] != '_log') {
        $res .= '->'.$trace[$loc]['function'].'()';
    }

	$prefix = $res . " ";
	$lines = explode(PHP_EOL, $data);
	foreach ($lines as $line) {
		$res = $prefix . $line . PHP_EOL;
	    if (($_config && $_config['enable_logging'] == true && $_config['log_verbosity'] >= $verbosity) || !empty(getenv('LOG_DEBUG'))) {
		    if (php_sapi_name() === 'cli') {
		        if(!defined("CLI_UTIL") || CLI_UTIL == 0) {
		            echo $res;
			    }
		    } else {
		        if(isset($_config['server_log']) && $_config['server_log']) {
		            error_log($res);
			    }
		    }
		    $log_file = $_config['log_file'];
		    if(substr($log_file, 0, 1)!= "/") {
			    $log_file = ROOT . "/" . $log_file;
		    }
	        @file_put_contents($log_file, $res, FILE_APPEND);
	    }
	}

}

// converts PEM key to hex
function pem2hex($data)
{
    $data = str_replace("-----BEGIN PUBLIC KEY-----", "", $data);
    $data = str_replace("-----END PUBLIC KEY-----", "", $data);
    $data = str_replace("-----BEGIN EC PRIVATE KEY-----", "", $data);
    $data = str_replace("-----END EC PRIVATE KEY-----", "", $data);
    $data = str_replace("\n", "", $data);
    $data = base64_decode($data);
    $data = bin2hex($data);
    return $data;
}

// converts hex key to PEM
function hex2pem($data, $is_private_key = false)
{
    $data = hex2bin($data);
    $data = base64_encode($data);
    if ($is_private_key) {
        return "-----BEGIN EC PRIVATE KEY-----\n".$data."\n-----END EC PRIVATE KEY-----";
    }
    return "-----BEGIN PUBLIC KEY-----\n".$data."\n-----END PUBLIC KEY-----";
}


// converts PEM key to the base58 version used by PHP
function pem2coin($data)
{
    $data = str_replace("-----BEGIN PUBLIC KEY-----", "", $data);
    $data = str_replace("-----END PUBLIC KEY-----", "", $data);
    $data = str_replace("-----BEGIN EC PRIVATE KEY-----", "", $data);
    $data = str_replace("-----END EC PRIVATE KEY-----", "", $data);
    $data = str_replace("\n", "", $data);
    $data = base64_decode($data);


    return base58_encode($data);
}

function priv2pub($private_key) {
	$pk = coin2pem($private_key, true);
	$pkey = openssl_pkey_get_private($pk);
	$pub = openssl_pkey_get_details($pkey);
	$public_key = pem2coin($pub['key']);
	return $public_key;
}

function ec_verify($data, $signature, $key, $chain_id = CHAIN_ID)
{
    // transform the base58 key to PEM
    $public_key = coin2pem($key);

	$data = $chain_id . $data;

    $signature = base58_decode($signature);

    $pkey = openssl_pkey_get_public($public_key);

    $res = openssl_verify($data, $signature, $pkey, OPENSSL_ALGO_SHA256);

	_log("Sign: verify signature for data: $data chain_id=$chain_id res=$res", 5);
    if ($res === 1) {
        return true;
    }
    return false;
}
// verify the validity of an url
function isValidURL($url)
{
    return preg_match('|^(ht)?(f)?tp(s)?://[a-z0-9-]+(.[a-z0-9-]+)*(:[0-9]+)?(/.*)?$|i', $url);
}

// POST data to an URL (usualy peer). The data is an array, json encoded with is sent as $_POST['data']
function peer_post($url, $data = [], $timeout = 30, &$err= null)
{
    global $_config;

    if(isset($_config) && $_config['offline']==true) {
	    _log("Peer is set to offline");
	    return false;
    }

    if (!isValidURL($url)) {
    	_log("Not valid peer post url $url");
        return false;
    }
    $postdata = http_build_query(
        [
            'data' => json_encode($data),
            "coin" => COIN,
	        "version"=>VERSION,
	        "network"=>NETWORK,
	        "chain_id"=>CHAIN_ID,
	        "requestId" => uniqid(),
	        "info"=>Peer::getInfo()
        ]
    );

//    $opts = [
//        'http' =>
//            [
//                'timeout' => $timeout,
//                'method'  => 'post',
//                'header'  => 'content-type: application/x-www-form-urlencoded',
//                'content' => $postdata,
//            ],
//	    "ssl"=>array(
//		    "verify_peer"=>!development,
//		    "verify_peer_name"=>!development,
//	    ),
//    ];

//    $context = stream_context_create($opts);

    _log("Posting to $url data ".$postdata." timeout=$timeout", 5);

	$ch = curl_init();

	curl_setopt($ch, CURLOPT_URL,$url);
	curl_setopt($ch, CURLOPT_POST, 1);
	curl_setopt($ch, CURLOPT_POSTFIELDS,$postdata );
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
	curl_setopt($ch,CURLOPT_SSL_VERIFYHOST, !DEVELOPMENT);
	curl_setopt($ch,CURLOPT_SSL_VERIFYPEER, !DEVELOPMENT);
	curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeout);
	curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
	$result = curl_exec($ch);

	$curl_error = curl_errno($ch);
	if($curl_error) {
		$error_msg = curl_error($ch);
		_log("CURL error=".$curl_error." ".$error_msg. " url=$url timeout=$timeout", 5);
		//6 - Could not resolve host: miner1.phpcoin.net
		//7 - Failed to connect to miner1.phpcoin.net port 80: Connection refused
		//28 - Connection timed out after 5001 milliseconds
		return false;
	}

	$curl_info = curl_getinfo($ch);

	curl_close ($ch);


//    $result = file_get_contents($url, false, $context);
    //_log("Peer response: ".$result, 5);
    $res = json_decode($result, true);

    // the function will return false if something goes wrong
    if ($res['status'] != "ok" || $res['coin'] != COIN || (isset($res['network']) && $res['network'] != NETWORK && $res['chain_id'] != CHAIN_ID)) {
    	_log("Peer response to $url not ok res=$result", 5);
	    $err = $res['data'];
        return false;
    } else {
    	Peer::storePing($url, $curl_info);
    }
    return $res['data'];
}

function url_get($url,$timeout = 30) {
	$ch = curl_init();
	curl_setopt($ch, CURLOPT_URL,$url);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
	curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
	if(DEVELOPMENT) {
		curl_setopt($ch,CURLOPT_SSL_VERIFYHOST, 0);
		curl_setopt($ch,CURLOPT_SSL_VERIFYPEER, 0);
	} else {
		curl_setopt($ch,CURLOPT_SSL_VERIFYHOST, 2);
		curl_setopt($ch,CURLOPT_SSL_VERIFYPEER, 1);
	}
	$result = curl_exec($ch);
	if($result === false) {
		$err = curl_error($ch);
		_log("Curl error: url=$url error=$err", 5);
	}
	curl_close ($ch);
	return $result;
}

function url_post($url, $postdata) {
	$ch = curl_init();
	curl_setopt($ch, CURLOPT_URL,$url);
	curl_setopt($ch, CURLOPT_POST, 1);
	curl_setopt($ch, CURLOPT_POSTFIELDS, $postdata );
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

	curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
	if(DEVELOPMENT) {
		curl_setopt($ch,CURLOPT_SSL_VERIFYHOST, 0);
		curl_setopt($ch,CURLOPT_SSL_VERIFYPEER, 0);
	} else {
		curl_setopt($ch,CURLOPT_SSL_VERIFYHOST, 2);
		curl_setopt($ch,CURLOPT_SSL_VERIFYPEER, 1);
	}
	$result = curl_exec($ch);
	if($result === false) {
		$err = curl_error($ch);
		_log("Curl error: url=$url error=$err", 5);
	}
	curl_close ($ch);
	return $result;
}

// converts base58 to hex
function coin2hex($data)
{
    $bin = base58_decode($data);
    return bin2hex($bin);
}

function gmp_hexdec($n) {
	$gmp = gmp_init(0);
	$mult = gmp_init(1);
	for ($i=strlen($n)-1;$i>=0;$i--,$mult=gmp_mul($mult, 16)) {
		$gmp = gmp_add($gmp, gmp_mul($mult, hexdec($n[$i])));
	}
	return $gmp;
}

function display_date($ts) {
	$s = "";
	if(!empty($ts)) {
		$s = '<span class="text-nowrap" title="'.$ts.'">' . date("Y-m-d H:i:s", $ts) .'</span>';
	}
	return $s;
}

function hashimg($hash, $title=null) {
	if(empty($title)) {
		$title=$hash;
	}
	$hash = $hash . "00";
	$parts = str_split($hash, 6);
	$s= '<div class="hash" title="'.$title.'" data-bs-toggle="tooltip">';
	foreach ($parts as $part) {
		$s.='<div style="background-color: #'.$part.'"></div>';
	}
	$s.='</div>';
	return $s;
}

function try_catch($callback, &$error = null) {
	try {
		return call_user_func($callback);
	} catch (Exception $e) {
		$stack = debug_backtrace();
		$error = $stack[1]['class'].$stack[1]['type'].$stack[1]['function'].":".$stack[1]['line'].": " . $e->getMessage();
		_log($error);
		return false;
	}
}

function load_db_config() {
	global $_config, $db;
	$query = $db->run("SELECT cfg, val FROM config");
	if(is_array($query)) {
		foreach ($query as $res) {
			$_config[$res['cfg']] = trim($res['val']);
		}
	}
	return $_config;
}
