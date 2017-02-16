<?php

function update_list_urls($force=false)
{
	$urls = Settings::Get('list_urls',[]);
	$force |= count($urls)==0;
	$needs_update = $force || isNewer("http://zdfmediathk.sourceforge.net/akt.xml",lastSyncTime('list_urls'));
	if( !$needs_update )
		return;
	
	write("Getting new list URLs...");
	$urls = [];
	$content = downloadData("http://zdfmediathk.sourceforge.net/akt.xml");
	if( preg_match_all('|<url>(.+)</url>|i',$content,$matches) )
		Settings::Set('list_urls',$matches[1]);
	storeSync('list_urls');
}

function update_movielist($force)
{
	$list_file = SETTINGS_FOLDER.'/movielist.xz';
	$urls = Settings::Get('list_urls',[]);
	shuffle($urls);
	$url = array_pop($urls);
	
	$force |= count(glob(SETTINGS_FOLDER.'/movies.*.dat')) == 0;
	if( !$force )
	{
		if( !isNewer("http://zdfmediathk.sourceforge.net/akt.xml",lastSyncTime('movielist')) )
			return '';
	}
	
	if( file_exists($list_file) )
		@unlink($list_file);
	if( file_exists($list_file.'.crap') )
	{
		if( filemtime($list_file.'.crap') > lastSyncTime('movielist') )
			return $list_file.'.crap';
		@unlink($list_file.'.crap');
	}
	
	write("Getting updated movie list...");
	
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
	
	$content = file_get_contents($list_file.'.crap',false,null,-1,1024);
	$regex = '/\"Filmliste\"\s+:\s+\[\s+\"(.*)\",\s+\"(.*)\",\s+\"(.*)\",\s+\"(.*)\",\s+\"(.*)\"\s+\]/U';
	if( !preg_match($regex, $content, $match ) )
		throw new Exception("Invalid movie list");
	
	storeSync('movielist');
	return $list_file.'.crap';
}

function update_movies($force=false)
{
	update_list_urls($force);
	$list_file = update_movielist($force);
	if( !$list_file )
		return;
	
	$min_duration = Settings::Get('movies_min_length',180);
	$max_age = time() - Settings::Get('movies_max_age',365) * 86400;
	
	
	write("Importing movie list '$list_file' ...");
	$file = new SplFileObject($list_file);
	$length = $file->getSize(); $op = 0;
	
	$sender = ''; $theme = ''; $counter = 0;
	
	$validateURL = function($urlbase,$urlval)
	{
		if( !preg_match('/(\d+)\|(.*)/',$urlval,$m) )
			return $urlval;
		return substr($urlbase,0,intval($m[1])).$m[2];
	};
	
	$regex = '/\"X\"\s+:\s+\[\s+\"(.*)\",\s+\"(.*)\",\s+\"(.*)\",\s+\"(.*)\",\s+\"(.*)\",\s+\"(.*)\",\s+\"(.*)\",\s+\"(.*)\",\s+\"(.*)\",\s+\"(.*)\",\s+\"(.*)\",\s+\"(.*)\",\s+\"(.*)\",\s+\"(.*)\",\s+\"(.*)\",\s+\"(.*)\",\s+\"(.*)\",\s+\"(.*)\",\s+\"(.*)\",\s+\"(.*)\"\s+\]/U';
	$lines = array();
	
	$station = $channel = $title = false;
	writeProgress(0,$length);
	//$out = new SplFileObject(SETTINGS_FOLDER."/movies.0.new",'w');
	
	$chunksize = Settings::Get('movie_chunksize',10000);
	
	$chunk = [];
	while( !$file->eof() )
	{
		$line = $file->fgets();
		$line = str_replace('\"',"'",trim($line));
		$lines[] = $line;
		if( count($lines)<100 && !$file->eof() )
			continue;
		
		$content = implode("\n",$lines);
		$lines = array();
		if( !preg_match_all($regex, $content, $matches, PREG_SET_ORDER ) )
			continue;
		
		foreach( $matches as $vals )
		{
			$duration = 0;
			foreach( array_reverse(explode(":",$vals[6])) as $i=>$v)
				$duration += pow(60,$i) * intval($v);
			if( $duration < $min_duration )
				continue;

			$sent = trim(preg_replace('/(\d\d).(\d\d).(\d\d\d\d)/','$3-$2-$1',$vals[4]).' '.$vals[5]);
			if( !$sent )
				continue;
			$sent = strtotime($sent);
			if( $sent < $max_age )
				continue;
			
			if( $vals[1] ) $station = trim($vals[1]);
			if( $vals[2] ) $channel = trim($vals[2]);
			if( $vals[3] ) $title = trim($vals[3]);
			
			if( !$station || !$channel || !$title )
				continue;
			
			$description = $vals[8];
			$bc = new Broadcast(compact('channel','title','station','sent','duration','description'));
			$bc->id = $counter++;
			$bc->AddStream(0,$validateURL($vals[9],$vals[13]));
			$bc->AddStream(1,$vals[9]);
			$bc->AddStream(2,$validateURL($vals[9],$vals[15]));
			$chunk[$bc->id] = $bc;
			//$out->fwrite("$bc,\n");
			if( $counter % $chunksize == 0 )
			{
				//$out = new SplFileObject(SETTINGS_FOLDER."/movies.{$counter}.new",'w');
				file_put_contents(SETTINGS_FOLDER."/movies.{$counter}.new", serialize($chunk));
				$chunk = [];
			}
		}
		writeProgress($file->ftell(),$length);
	}
	writeProgress($length,$length);
	$out = null;
	$file = null;
	
	write("Cleaning up...");
	@unlink($list_file);
	foreach( glob(SETTINGS_FOLDER.'/movies.*.dat') as $f )
		unlink($f);
	foreach( glob(SETTINGS_FOLDER.'/movies.*.new') as $f )
		rename($f,str_replace('.new','.dat',$f));

	Settings::Set('movie_count',$counter);
	write("Database now contains $counter movies.");
}
