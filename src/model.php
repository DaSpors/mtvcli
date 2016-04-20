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
		$name = str_replace(array('ä','ö','ü','Ä','Ö','Ü','ß'),array('ae','oe','ue','Ae','Oe','Ue','ss'),$name);
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
	public $type = 'text';
}

class Search extends \ShellPHP\Storage\StoredObject
{
	public static function GetTableName(){ return 'searches'; }
	
	public $id           = 'int primary autoinc';
	public $searched     = 'datetime';
	public $pattern      = 'text unique complete';
	public $min_duration = 'int unique complete';
	public $station      = 'text unique complete';
	public $skip_title   = 'bool unique complete';
	public $skip_channel = 'bool unique complete';
	
	public static function Ensure($pattern,$min_duration,$station,$skip_title,$skip_channel)
	{
		$s = Search::Select()
			->eq('pattern',$pattern)
			->eq('min_duration',$min_duration)
			->eq('station',$station)
			->eq('skip_title',$skip_title)
			->eq('skip_channel',$skip_channel)
			->current();
		if( $s )
			return $s;
		$s = Search::Make()
			->set('pattern',$pattern)
			->set('min_duration',$min_duration)
			->set('station',$station)
			->set('skip_title',$skip_title)
			->set('skip_channel',$skip_channel);
		$s->Save();
		return $s;
	}
	
	public function Perform()
	{
		$this->searched = '__NOW__';
		$this->Save();
		
		if( $this->skip_title && $this->skip_channel )
			throw new Exception("Cannot search nowhere");
		
		$q = Broadcast::Select()
			->resolveFK('s','station_id','stations')
			->resolveFK('c','channel_id','channels')
			->resolveFK('p','program_id','programs')
			->gte('p.duration',intval($this->min_duration))
			->gt("(SELECT count(*) FROM videos WHERE videos.broadcast_id=id)",0,false,false);
			
		if( $this->station )
			$q->eq('s.name',$this->station);
		
		$q->any();
		
		if( !$this->skip_title )
			$q->like('p.name',$this->pattern);
		if( !$this->skip_channel )
			$q->like('c.name',$this->pattern);
		return $q;
	}
}

class Subscription extends \ShellPHP\Storage\StoredObject
{
	public $name      = 'text primary';
	public $folder      = 'text';
	public $searched     = 'datetime';
	public $pattern      = 'text';
	public $min_duration = 'int';
	public $station      = 'text';
	public $skip_title   = 'int';
	public $skip_channel = 'int';
	
	public function QueueDownloads()
	{
		$s = Search::Ensure($this->pattern,$this->min_duration,$this->station,$this->skip_title,$this->skip_channel);
		foreach( $s->Perform()->results() as $bc )
			$bc->queue($this->folder);
	}
}

class Download extends \ShellPHP\Storage\StoredObject
{
	public $broadcast_id = 'int primary';
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
	
	private $bytes_done = 0;
	private $_buffer = array();
	
	public function getBroadcast()
	{
		if( !isset($this->_buffer['bc']) )
			$this->_buffer['bc'] = Broadcast::Select()->eq('id',$this->broadcast_id)->current();
		return $this->_buffer['bc'];
	}
	
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
		write("Download stopped: $message");
		return $this->set('stopped',"__NOW__")->set('pid',null)->set('message',$message)->Save();
	}
	
	public function skip()
	{
		return $this->set('finished',"__NOW__")->set('pid',null)->set('message','skipped')->Save();
	}
	
	private function reQueue($message)
	{
		$this->stop($message);
		write("Removing invalid URL, trying to re-queue");
		$vid = Video::Select()->eq('url',$this->url)->current();
		if( $vid ) $vid->Delete();
		
		$bc = $this->getBroadcast();
		$bc->queue(dirname($this->filename));
	}
	
	public function start($fork=false)
	{
		$this->set('started',"__NOW__")
			->set('finished',null)
			->set('stopped',null)
			->set('message',null)
			->set('pid',getmypid())
			->Save();
		
		$bc = $this->getBroadcast();
		if( !$bc )
			return $this->stop('Program not found');
		
		$id   = $bc->id;
		$url  = $this->url;
		$file = $this->filename;
		
		write("");
		write("Downloading movie $id");
		write("  Station  ".$bc->getStation()->name);
		write("  Channel  ".$bc->getChannel()->name);
		write("  Title    ".$bc->getProgram()->name);
		write("  URL      $url");
		write("  Filename $file");
		
		if( file_exists($file) )
			$this->bytes_done = filesize($file);
		$this->storage = \ShellPHP\Storage\Storage::Make();
		$result = downloadFile($url,$file,array($this,'downloadProgress'));
		
		if( !$result )
		{
			@rename($file,"$file.error");
			write("Error downloading movie: $url");
			return $this->reQueue('download error');
		}
		if( filesize($file) < 100 && stripos(trim(file_get_contents($file)),'not found')!==false )
		{
			unlink($file);
			write("File not found: $url");
			return $this->reQueue('not found');
		}
		return $this->set('finished',"__NOW__")->set('pid',null)->set('message','ok')->Save();
	}
	
	function downloadProgress($resource,$download_size, $downloaded, $upload_size, $uploaded)
	{
		if( $download_size == 0 )
			return;
		$download_size += $this->bytes_done;
		$downloaded += $this->bytes_done;
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
