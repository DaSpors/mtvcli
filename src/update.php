<?php

function update_list_urls($force=false)
{
	$list_file = SETTINGS_FOLDER.'/list_urls.txt';
	if( !$force && !syncNeeded('list_urls',7*24) )
		return;
	@unlink($list_file);
	
	write("Getting new list URLs...");
	$content = downloadData("http://zdfmediathk.sourceforge.net/akt.xml");
	if( !preg_match_all('|<url>(.+)</url>|i',$content,$matches) )
		return;
	
	MovieListUrl::Truncate();
	foreach( $matches[1] as $url )
		MovieListUrl::Make()->set('url',$url)->Save();
	storeSync('list_urls');
}

function update_movies($force=false,$incremental=true,$age=0)
{
	update_list_urls($force);
	if( !$force && !syncNeeded('movies',7*24) )
		return;
	
	$age = intval($age);
	$url = MovieListUrl::Select()->shuffle()->scalar('url');
	
	$list_file = SETTINGS_FOLDER.'/movielist.xz';
	if( !file_exists($list_file.'.crap') )
	{
		write("Getting new movie list...");
		if( !downloadFile($url,$list_file) )
			throw new Exception("Error getting the movie list.");
		
		$cmd = UNXZ;
		if( ISWIN && stripos(__FILE__,"phar://") === 0 )
		{
			if( !file_exists(sys_get_temp_dir()."/mtvcli/bin") )
			{
				$phar = new Phar(Phar::running(false));
				$phar->extractTo(sys_get_temp_dir()."/mtvcli");
				$phar = null;
			}
			$fn = explode(".phar.gz",$cmd,2);
			$cmd = sys_get_temp_dir()."/mtvcli".$fn[1];
		}
		
		write("Unpacking movie list...");
		$cmd = str_replace(array('{in}','{out}'),array($list_file,$list_file.'.crap'),$cmd);
		shell_exec($cmd);
		@unlink($list_file);
	}
	
	write("Importing movie list...");
	$file = new SplFileObject($list_file.'.crap');
	$length = $file->getSize(); $op = 0;
	
	$storage = \ShellPHP\Storage\Storage::Make();
	$storage->exec("BEGIN TRANSACTION");
	$sender = ''; $theme = ''; $chunksize = 50000; $counter = 0;
	$new = $updated = 0;
	$lastSync = lastSyncTime('movies');
	
	$fields = explode(",","hash,sender,theme,title,sent,duration,size,url_low,url_mid,url_high,website,description");
	$params = array_map(function($i){ return ":$i"; },$fields);
	$combined = array_map(function($i){ return "$i=:$i"; },$fields);
	
	Movie::Select(); // ensure table presence
	
	$validateURL = function($urlbase,$urlval)
	{
		if( !preg_match('/(\d+)\|(.*)/',$urlval,$m) )
			return $urlval;
		return substr($urlbase,intval($m[1])).$m[2];
	};
	
	$regex = '/\"X\"\s+:\s+\[\s+\"(.*)\",\s+\"(.*)\",\s+\"(.*)\",\s+\"(.*)\",\s+\"(.*)\",\s+\"(.*)\",\s+\"(.*)\",\s+\"(.*)\",\s+\"(.*)\",\s+\"(.*)\",\s+\"(.*)\",\s+\"(.*)\",\s+\"(.*)\",\s+\"(.*)\",\s+\"(.*)\",\s+\"(.*)\",\s+\"(.*)\",\s+\"(.*)\",\s+\"(.*)\",\s+\"(.*)\"\s+\]/U';
	$lines = array();
	while( !$file->eof() )
	{
		$line = $file->fgets();
		$line = str_replace('\"',"'",trim($line));
		$lines[] = $line;
		if( count($lines)<1000 && !$file->eof() )
			continue;
		
		$content = implode("\n",$lines);
		$lines = array();
		//$start = microtime(true);
		if( !preg_match_all($regex, $content, $matches, PREG_SET_ORDER ) )
			continue;
		//$start = \ShellPHP\Storage\Storage::StatCount("PREG",microtime(true)-$start);
		
		foreach( $matches as $vals )
		{
			$sent = trim(preg_replace('/(\d\d).(\d\d).(\d\d\d\d)/','$3-$2-$1',$vals[4]).' '.$vals[5]);
			if( !$sent ) $sent = null;

			if( $incremental && $sent != null )
			{
				if( $sent < $lastSync )
					continue;
			}
			
			$dur = 0;
			foreach( array_reverse(explode(":",$vals[6])) as $i=>$v)
				$dur += pow(60,$i) * intval($v);
			//$start = \ShellPHP\Storage\Storage::StatCount("PREPARE3",microtime(true)-$start);
			if( $vals[1] ) $sender = $vals[1];
			if( $vals[2] ) $theme = $vals[2];
			//$start = \ShellPHP\Storage\Storage::StatCount("PREPARE4",microtime(true)-$start);
			$data = array(
				'sender' => $sender,
				'theme' => $theme,
				'title' => $vals[3],
				'sent' => $sent,
				'duration' => $dur,
				'size' => intval($vals[7]),
				'url_low' => $validateURL($vals[9],$vals[13]),
				'url_mid' => $vals[9],
				'url_high' => $validateURL($vals[9],$vals[15]),
				'website' => $vals[10],
				'description' => $vals[8]
			);
			//$start = \ShellPHP\Storage\Storage::StatCount("PREPARE5",microtime(true)-$start);
			$hash = md5("{$sender}{$theme}{$vals[3]}{$sent}{$dur}{$vals[7]}{$vals[13]}{$vals[9]}{$vals[15]}{$vals[10]}{$vals[8]}");
			//$start = \ShellPHP\Storage\Storage::StatCount("PREPARE6",microtime(true)-$start);
			try
			{
				$test = $storage->querySingle("SELECT 1 FROM movies WHERE hash=:h",array('h'=>$hash));
				if( $test > 0 )
					continue;
			}catch(\ShellPHP\Storage\StorageException $ex){ /* catch table does not exist on first import */ };
			
			$data['hash'] = $hash;
			
			$movie = Movie::Make($data);
			if( !$movie->UpdateBy('sender','theme','title','sent') )
			{
				$new++;
				if( !$movie->Save() )
					write("Error saving",$movie);
			}
			else
				$updated++;
			
			if( $counter++ > $chunksize )
			{
				$storage->exec("COMMIT");
				$storage->exec("BEGIN TRANSACTION");
				$counter = 0;
			}
		}
		writeProgress($file->ftell(),$length);
	}
	$storage->exec("COMMIT");
	writeProgress($length,$length);
	$file = null;

	write("Updated $updated movies, added $new ones.");
	write("Cleaning up...");
	@unlink($list_file.'.crap');
	if( $age > 0 )
	{
		$oldest = date("Y-m-d H:i:s",strtotime("-$age day"));
		$storage->exec("DELETE FROM movies WHERE sent<'$oldest'");
		$storage->exec("VACUUM");
	}
	elseif( time()-$lastSync > 7*86400 )
		$storage->exec("VACUUM");
	
	storeSync('movies');
	ShellPHP\Storage\Storage::StatsOut();
}
