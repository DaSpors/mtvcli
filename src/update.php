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
		MovieListUrl::Make()->set('url',$url)->set('type','akt')->Save();

	$content = downloadData("http://zdfmediathk.sourceforge.net/diff.xml");
	if( !preg_match_all('|<url>(.+)</url>|i',$content,$matches) )
		return;
	
	foreach( $matches[1] as $url )
		MovieListUrl::Make()->set('url',$url)->set('type','diff')->Save();
		
	storeSync('list_urls');
}

function update_movielist($force)
{
	$storage = \ShellPHP\Storage\Storage::Make();
	
	$doit = $force?$force:($storage->getSetting("next_movielist_update",1)<time());
	if( !$doit )
		return '';
	
	$listid = $storage->getSetting("current_movielist_id",'');
	$listcheck = $storage->getSetting("current_movielist_check",'');
	
	$list_file = SETTINGS_FOLDER.'/movielist.xz';
	if( file_exists($list_file.'.crap') )
		return $list_file.'.crap';
	
	$url = MovieListUrl::Select()->eq('type',$listid?'diff':'akt')->shuffle()->scalar('url');
	
	if( $listid )
		write("Getting updated movie list...");
	else
		write("Getting complete movie list...");
	
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
	$newlistid = $match[5];
	
	if( !$listid )
	{
		$storage->setSetting("current_movielist_id",$newlistid);
		$storage->setSetting("current_movielist_check",md5($match[0]));
		$storage->setSetting("next_movielist_update",time()+3600);
		return $list_file.'.crap';
	}
	if( $listid == $newlistid )
	{
		$storage->setSetting("next_movielist_update",time()+3600);
		if( md5($match[0]) == $listcheck )
		{
			unlink($list_file.'.crap');
			return '';
		}
		$storage->setSetting("current_movielist_check",md5($match[0]));
		return $list_file.'.crap';
	}
	unlink($list_file.'.crap');
	$storage->setSetting("current_movielist_id",'');
	write("Invalid update file $listid != $newlistid");
	return update_movielist($force);
}

function update_movies($force=false,$incremental=true,$age=0)
{
	update_list_urls($force);
	if( !$force && !syncNeeded('movies',1) )
		return;
	
	$age = intval($age);
	$url = MovieListUrl::Select()->eq('type','diff')->shuffle()->scalar('url');
	
	$list_file = update_movielist($force);
	if( !$list_file )
	{
		write("No new data found");
		storeSync('movies');
		return;
	}
	
	write("Importing movie list '$list_file' ...");
	$file = new SplFileObject($list_file);
	$length = $file->getSize(); $op = 0;
	
	$storage = \ShellPHP\Storage\Storage::Make();
	$storage->exec("BEGIN TRANSACTION");
	$sender = ''; $theme = ''; $chunksize = 50000; $counter = 0;
	$new = $updated = 0;
	$lastSync = lastSyncTime('movies');
	
	$validateURL = function($urlbase,$urlval)
	{
		if( !preg_match('/(\d+)\|(.*)/',$urlval,$m) )
			return $urlval;
		return substr($urlbase,0,intval($m[1])).$m[2];
	};
	
	$regex = '/\"X\"\s+:\s+\[\s+\"(.*)\",\s+\"(.*)\",\s+\"(.*)\",\s+\"(.*)\",\s+\"(.*)\",\s+\"(.*)\",\s+\"(.*)\",\s+\"(.*)\",\s+\"(.*)\",\s+\"(.*)\",\s+\"(.*)\",\s+\"(.*)\",\s+\"(.*)\",\s+\"(.*)\",\s+\"(.*)\",\s+\"(.*)\",\s+\"(.*)\",\s+\"(.*)\",\s+\"(.*)\",\s+\"(.*)\"\s+\]/U';
	$lines = array();
	
	\ShellPHP\Storage\Storage::StatClear();
	$station = $channel = false;
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
			if( $vals[1] ) $station = Station::Ensure($vals[1]);
			if( $vals[2] ) $channel = Channel::Ensure($vals[2]);
			
			if( !$station || !$channel )
				continue;
			
			$dur = 0;
			foreach( array_reverse(explode(":",$vals[6])) as $i=>$v)
				$dur += pow(60,$i) * intval($v);
			$program = Program::Ensure($vals[3],$dur,$vals[10],$vals[8]);
			if( !$program )
				continue;
			
			$sent = trim(preg_replace('/(\d\d).(\d\d).(\d\d\d\d)/','$3-$2-$1',$vals[4]).' '.$vals[5]);
			if( !$sent ) $sent = null;
			
			$broadcast = Broadcast::Ensure($station,$channel,$program,$sent);
			if( !$broadcast )
				continue;
			
			$broadcast->AddVideo($validateURL($vals[9],$vals[13]),10);
			$broadcast->AddVideo($vals[9],20);
			$broadcast->AddVideo($validateURL($vals[9],$vals[15]),30);
			
			if( $broadcast->IsNew() )
				$new++;
			else
				$updated++;
			
			if( $counter++ > $chunksize )
			{
				$storage->exec("COMMIT");
				//ShellPHP\Storage\Storage::StatsOut();
				//die();
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
	@unlink($list_file);
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
