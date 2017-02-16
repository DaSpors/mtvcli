<?php

$INIFILE = ISWIN?$_SERVER['USERPROFILE'].'/mtvcli.ini':('/etc/mtvcli.ini');
$QUALITY = array(20,30,10);
if( file_exists($INIFILE) )
{
	$ini = parse_ini_file($INIFILE);
	if( isset($ini['folder']) && $ini['folder'] )
		$SETTINGS_FOLDER = $ini['folder'];
	
	if( isset($ini['quality']) && $ini['quality'] )
		$QUALITY = explode(",",str_replace(array('h','m','l'),array(30,20,10),strtolower($ini['quality'])));
}
if( !isset($SETTINGS_FOLDER) || !$SETTINGS_FOLDER )
	$SETTINGS_FOLDER = ISWIN?$_SERVER['USERPROFILE'].'/.mtvcli':($_SERVER['HOME'].'/.mtvcli');
if( !file_exists($SETTINGS_FOLDER) ) mkdir($SETTINGS_FOLDER);
@define('SETTINGS_FOLDER',realpath($SETTINGS_FOLDER));
@define('QUALITY',implode(",",$QUALITY));
unset($SETTINGS_FOLDER);
unset($QUALITY);

if( ISWIN )
	define('UNXZ',__DIR__.'/bin/xz/xzdec.exe {in} > {out}');
else
	define('UNXZ','unxz -k -c {in} > {out}');

function write()
{
	call_user_func_array('\ShellPHP\CmdLine\CLI::writeln', func_get_args());
}

function writeProgress($done,$total)
{
	return \ShellPHP\CmdLine\CLI::progress($done,$total);
}

function startTable($columns, $auto_flush_rows=100)
{
	return \ShellPHP\CmdLine\CLI::startTable($columns, $auto_flush_rows);
}

function addTableRow($row)
{
	return \ShellPHP\CmdLine\CLI::addTableRow($row);
}

function flushTable()
{
	return \ShellPHP\CmdLine\CLI::flushTable();
}

function formatDuration($duration)
{
	return \ShellPHP\CmdLine\Format::duration($duration);
}

function loadSettings()
{
	$file = SETTINGS_FOLDER.'/mtvcli.json';
	if( !file_exists($file) )
		return new stdClass();
	return json_decode(file_get_contents($file));
}

function lastSyncTime($part)
{
	return Settings::Get("last_sync_$part",0);
}

function syncNeeded($part,$max_age_in_hours)
{
	$last = lastSyncTime($part);
	return $last < time() - ($max_age_in_hours * 3600);
}

function storeSync($part)
{
	Settings::Set("last_sync_$part",time());
}

function sanitizeFields($fields)
{
	if( !is_array($fields) )
		$fields = explode(",",$fields);
	$wrong = array_diff($fields,array('sender','theme','title','description','website'));
	if( count($wrong)>0 )
		throw new Exception("Invalid field values: ".implode(",",$wrong));
	return $fields;
}

function sanitizeQuality($quality)
{
	$quality = strtolower(preg_replace('/[^mhl]/i','',$quality));
	if( strlen($quality) != 3 )
		throw new Exception("Invalid value for 'quality'. Allowed is a combination of 'h','m','l' with exactly three chars.");
	return $quality;
}

function isNewer($url,$last_time)
{
	$h = implode("\n",get_headers($url));
	if( preg_match("/Last-Modified: (.*)/", $h, $m) )
	{
		$dt = strtotime($m[1]);
		if( $dt > $last_time )
			return true;
	}
	return false;
}

function downloadData($url, $postdata=false, $request_timeout=120)
{
	$ch = curl_init();
	curl_setopt($ch, CURLOPT_URL, $url);
	curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
	curl_setopt($ch, CURLOPT_TIMEOUT, abs($request_timeout));
	if($postdata)
	{
		curl_setopt($ch, CURLOPT_POST, 1);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $postdata);
	}
	curl_setopt($ch, CURLOPT_HEADER, 1);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
	curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
	curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
	curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
	
	if( isset($GLOBALS['download']['proxy']) )
	{
		//log_debug("Using download proxy {$GLOBALS['download']['proxy']}");
		curl_setopt($ch, CURLOPT_PROXY, $GLOBALS['download']['proxy']);
		curl_setopt($ch, CURLOPT_PROXYTYPE, $GLOBALS['download']['proxy_type']);
	}

	$result = curl_exec($ch);	
	$info = curl_getinfo($ch);
	if($result === false)
	{
		write('Curl error: ' . curl_error($ch),"url = ",$url,"curl_info = ",$info);
		curl_close($ch);
		return $result;
	}
	//log_info($info);
	curl_close($ch);

	$result = substr($result, $info['header_size']);
	
	return $result;
}

function downloadFile($url,$filename=false,$progress_function=false)
{
	$parsed_url = parse_url($url);
	$GLOBALS['downloadFile_data'] = array();
	$GLOBALS['downloadFile_data']['error'] = 0;
	$GLOBALS['downloadFile_data']['name'] = basename($parsed_url['path']);
	$GLOBALS['downloadFile_data']['percent'] = 0;

	$ch = curl_init();
	curl_setopt($ch, CURLOPT_URL, $url);
	curl_setopt($ch, CURLOPT_TIMEOUT, 0);
	curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);

	if( isset($parsed_url['user']) && $parsed_url['user'] && isset($parsed_url['pass']) && $parsed_url['pass'] )
	{
		curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
		curl_setopt($ch, CURLOPT_USERPWD, "{$parsed_url['user']}:{$parsed_url['pass']}");
	}

	curl_setopt($ch, CURLOPT_RETURNTRANSFER, 0);
	curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
	curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);

	curl_setopt($ch, CURLOPT_HEADERFUNCTION, 'downloadFile_header');

	if( !$filename ) $filename = tempnam(sys_get_temp_dir(), 'DOWNLOAD_');
	if( file_exists($filename) )
	{
		curl_setopt($ch, CURLOPT_RANGE, filesize($filename)."-");
		$mode = 'a';
		//unlink($filename);
	}
	else
		$mode = 'w';
	
	$GLOBALS['downloadFile_data']['tmp_name'] = $filename;
	$tmp_fp = fopen($GLOBALS['downloadFile_data']['tmp_name'],$mode);
	curl_setopt($ch, CURLOPT_FILE, $tmp_fp);

	if( !$progress_function ) $progress_function = 'downloadFile_progress';
	curl_setopt($ch, CURLOPT_PROGRESSFUNCTION, $progress_function);
	curl_setopt($ch, CURLOPT_NOPROGRESS, false);
	
	$result = curl_exec($ch);
	if( curl_errno($ch) )
		$result = false;
	
	if( $result === false )
	{
		$info = curl_getinfo($ch);
		write('Curl error: ' . curl_error($ch),"url = ",$url,"curl_info = ",$info);
	}
	
	curl_close($ch);
	fclose($tmp_fp);

	if( !$result )
		return false;

	$result = $GLOBALS['downloadFile_data'];
	$result['size'] = filesize($GLOBALS['downloadFile_data']['tmp_name']);
	
	unset($GLOBALS['downloadFile_data']);
	return $result;
}

function downloadFile_header($ch, $header)
{
	if( preg_match('/Content-Disposition:\s*(.*)/i', $header, $res) )
	{
		$p = explode(";",$res[1]);
		foreach( $p as $part )
		{
			$args = explode("=",trim($part));
			if( count($args) < 2 || $args[0] != 'filename' )
				continue;
			
			$name = trim(trim($args[1],"\""));
			if( $name )
			{
				$GLOBALS['downloadFile_data']['name'] = $name;
				break;
			}
		}
	}
	elseif( strtoupper(substr($header, 0, 12)) == "HTTP/1.1 401" )
		$GLOBALS['downloadFile_data']['error'] = UPLOAD_ERR_NO_FILE;
	return strlen($header);
}

function downloadFile_progress($resource,$download_size, $downloaded, $upload_size, $uploaded)
{
	if( $download_size == 0 )
		return;
	writeProgress($downloaded,$download_size);
}
