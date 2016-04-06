<?php

class Movie extends \ShellPHP\Storage\StoredObject
{
	public static function GetTableName(){ return 'movies'; }
	
	public $id = 'int primary autoinc';
	public $hash = 'text unique';
	public $sender = 'text unique sender_theme_title_sent';
	public $theme = 'text unique sender_theme_title_sent';
	public $title = 'text unique sender_theme_title_sent';
	public $sent = 'datetime unique sender_theme_title_sent';
	public $duration = 'int';
	public $size = 'int';
	public $url_low = 'text';
	public $url_mid = 'text';
	public $url_high = 'text';
	public $website = 'text';
	public $description = 'text';	
	
	public static function Search($pattern,$min_duration,$fields)
	{
		$fields = sanitizeFields($fields);
		$min_duration = intval($min_duration);
		
		$q = Movie::Select()
			->gte('duration',$min_duration)
			->any();
		foreach( $fields as $f )
			$q->like($f,$pattern);

		$fields = implode(",",$fields);
		Search::Make(compact('pattern','min_duration','fields'))
			->set('searched','__NOW__')->Save(true);
			
		return $q;
	}
	
	public function selectUrl($quality='mhl')
	{
		foreach( str_split(strtolower($quality)) as $q )
		{
			if( $q=='h' && isset($this->url_high) && $this->url_high )
				return $this->url_high;
			if( $q=='m' && isset($this->url_mid) && $this->url_mid )
				return $this->url_mid;
			if( $q=='l' && isset($this->url_low) && $this->url_low )
				return $this->url_low;
		}
		return '';
	}
	
	public function makeFilename()
	{
		$url = $this->url_mid;
		if( strcasecmp($this->sender,$this->theme) != 0 )
			$name = $this->theme." - ".$this->title.".".pathinfo($url,PATHINFO_EXTENSION);
		else
			$name = $this->title.".".pathinfo($url,PATHINFO_EXTENSION);
		$name = str_replace(array('ä','ö','ü','Ä','Ö','Ü','ß'),array('ae','oe','üe','Ae','Oe','Ue','ss'),$name);
		$name = preg_replace('/[^a-z0-9\._-]/i',' ',$name);
		while( strpos($name,'  ') !== false )
			$name = str_replace('  ',' ',$name);
		return $name;
	}
	
	public function getByteSize()
	{
		return intval($this->size) * 1024 * 1024;
	}
	
	public function queue($folder='.', $quality='mhl')
	{
		$url = $this->selectUrl($quality);
		if( !$url )
			return;
		if( Download::Select()->eq('url',$url)->count() > 0 )
			return Download::Select()->eq('url',$url)->current();
		
		$res = Download::Make(array(
			'movie_id' => $this->id,
			'url' => $url,
			'filename' => realpath($folder)."/".$this->makeFilename(),
			'size' => $this->getByteSize(),
			'queued' => '__NOW__'
		));
		$res->Save();
		write("Movie queued for download: '$this'");
		return $res;
	}
	
	public function __toString()
	{
		return "{$this->title}";
	}
}

class MovieListUrl extends \ShellPHP\Storage\StoredObject
{
	public $url = 'text primary';
}

class Search extends \ShellPHP\Storage\StoredObject
{
	public static function GetTableName(){ return 'searches'; }
	
	public $id           = 'int primary autoinc';
	public $searched     = 'datetime';
	public $pattern      = 'text unique pattern_dur_fields';
	public $min_duration = 'int unique pattern_dur_fields';
	public $fields       = 'text unique pattern_dur_fields';
}

class Subscription extends \ShellPHP\Storage\StoredObject
{
	public $name      = 'text primary';
	public $folder      = 'text';
	public $quality      = 'text';
	public $searched     = 'datetime';
	public $pattern      = 'text';
	public $min_duration = 'int';
	public $fields       = 'text';
	
	public function QueueDownloads()
	{
		Movie::Search($this->pattern,$this->min_duration,$this->fields)->each(function($movie)
		{
			$movie->queue($this->folder,$this->quality);
		});
	}
}

class Download extends \ShellPHP\Storage\StoredObject
{
	public $movie_id = 'int primary';
	public $url = 'text unique';
	public $filename = 'text';
	public $size = 'int';
	public $downloaded = 'int';
	public $pid = 'int';
	public $queued = 'datetime';
	public $started = 'datetime';
	public $finished = 'datetime';
	public $stopped = 'datetime';
	public $message = 'text';
	
	public static function Cleanup()
	{
		try
		{
			Download::Select()->notNull('pid')->each(function($dl)
			{
				if( \ShellPHP\Process\Process::running($dl->pid) )
					return;
				$dl->set('pid',null)->Save();
			});
		}catch(Exception $ex){}
	}
	
	public function stop($message)
	{
		return $this->set('stopped',"__NOW__")->set('pid',null)->set('message',$message)->Save();
	}
	
	public function skip()
	{
		return $this->set('finished',"__NOW__")->set('pid',null)->set('message','skipped')->Save();
	}
	
	public function start($fork=false)
	{
		$this->set('started',"__NOW__")
			->set('finished',null)
			->set('stopped',null)
			->set('message',null)
			->set('pid',getmypid())
			->Save();
		
		$movie = Movie::Select()->eq('id',$this->movie_id)->current();
		if( !$movie )
			return $this->stop('Movie not found');
		
		$id   = $movie->id;
		$url  = $this->url;
		$file = $this->filename;
		
		write("Downloading movie $id");
		write("  Sender   {$movie['sender']}");
		write("  Theme    {$movie['theme']}");
		write("  Title    {$movie['title']}");
		write("  URL      $url");
		write("  Filename $file");
		
		$this->storage = \ShellPHP\Storage\Storage::Make();
		$result = downloadFile($url,$file,array($this,'downloadProgress'));
		
		if( !$result )
		{
			@rename($file,"$file.error");
			write("Error downloading movie: $movie");
			return $this->stop('download error');
		}
		if( filesize($file) < 100 && strtolower(trim(file_get_contents($file))) == 'not found' )
		{
			unlink($file);
			write("File not found: $movie");
			return $this->stop('not found');
		}
		return $this->set('finished',"__NOW__")->set('pid',null)->set('message','ok')->Save();
	}
	
	function downloadProgress($resource,$download_size, $downloaded, $upload_size, $uploaded)
	{
		if( $download_size == 0 )
			return;
		$p = floor($downloaded * 100 / $download_size);
		if( $p != $this->downloaded )
		{
			try
			{
				$this->downloaded = $p;
				$this->storage->exec(
					"UPDATE downloads SET size=$download_size, downloaded=$p WHERE url='{$this->url}'",
					false,false,false);
			}catch(Exception $ex){}
		}
		writeProgress($downloaded,$download_size);
	}
}
