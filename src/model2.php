<?php

class Station extends \ShellPHP\Storage\StoredObject
{
	public $id = 'int primary autoinc';
	public $name = 'text unique';
	
	private static $_buf = array();
	public static function Ensure($name)
	{
		if( isset(self::$_buf[$name]) )
			return self::$_buf[$name];
		$res = Station::Select()->eq('name',$name)->current();
		if( $res )
			return $res;
		self::$_buf[$name] = Station::Make()->set('name',$name);
		self::$_buf[$name]->Save();
		return self::$_buf[$name];
	}
}
class Channel extends \ShellPHP\Storage\StoredObject
{
	public $id = 'int primary autoinc';
	public $name = 'text unique';
	
	private static $_buf = array();
	public static function Ensure($name)
	{
		if( isset(self::$_buf[$name]) )
			return self::$_buf[$name];
		$res = Channel::Select()->eq('name',$name)->current();
		if( $res )
			return $res;
		self::$_buf[$name] = Channel::Make()->set('name',$name);
		self::$_buf[$name]->Save();
		return self::$_buf[$name];
	}
}
class Program extends \ShellPHP\Storage\StoredObject
{
	public $id = 'int primary autoinc';
	public $name = 'text unique';
	public $duration = 'int';
	public $website = 'text';
	public $description = 'text';	
	
	public static function Ensure($name,$duration,$website,$description)
	{
		$res = $res = Program::Select()->eq('name',$name)->current();
		$save = false;
		if( !$res )
		{
			$res = Program::Make()->set('name',$name);
			$save = true;
		}
		if( $duration && !$res->duration )
		{
			$res->set('duration',$duration);
			$save = true;
		}
		if( $website && !$res->website )
		{
			$res->set('website',$website);
			$save = true;
		}
		if( $description && !$res->description )
		{
			$res->set('description',$description);
			$save = true;
		}
		if( $save )
		{
			$res->Save();
			if( !$res->id )
				$res = Program::Select()->eq('name',$name)->current();
		}
		return $res;
	}
}
class Broadcast extends \ShellPHP\Storage\StoredObject
{
	public $id = 'int primary autoinc';
	public $station_id = 'int unique station_channel_program_sent';
	public $channel_id = 'int unique station_channel_program_sent';
	public $program_id = 'int unique station_channel_program_sent';
	public $sent = 'datetime unique station_channel_program_sent';
	
	private $_buffer = array();
	
	public static function Ensure($station,$channel,$program,$sent)
	{
		$res = Broadcast::Select()
			->eq('station_id',$station->id)
			->eq('channel_id',$channel->id)
			->eq('program_id',$program->id);
		if( $sent )
			$res = $res->eq('sent',$sent)->current();
		else
			$res = $res->isNull('sent')->current();
		
		if( $res )
			return $res;
		
		$res = Broadcast::Make()
			->set('station_id',$station->id)
			->set('channel_id',$channel->id)
			->set('program_id',$program->id)
			->set('sent',$sent);
		if( $res->Save() )
			;//write("New Broadcast {$station->id}/{$channel->id}/{$program->id}/{$sent}");
		$res->_buffer['isNew'] = true;
		return $res;
	}
	
	public function isNew()
	{
		return isset($this->_buffer['isNew']);
	}
	
	public function getStation()
	{
		if( !isset($this->_buffer['station']) )
			$this->_buffer['station'] = Station::Select()->eq('id',$this->station_id)->current();
		return $this->_buffer['station'];
	}
	
	public function getChannel()
	{
		if( !isset($this->_buffer['channel']) )
			$this->_buffer['channel'] = Channel::Select()->eq('id',$this->channel_id)->current();
		return $this->_buffer['channel'];
	}
	
	public function getProgram()
	{
		if( !isset($this->_buffer['program']) )
			$this->_buffer['program'] = Program::Select()->eq('id',$this->program_id)->current();
		return $this->_buffer['program'];
	}
	
	public function AddVideo($url,$quality)
	{
		if( $url )
			Video::Ensure($this,$url,$quality);
		return $this;
	}
	
	public function queue($folder='.')
	{
		$vids = $this->getVideos();
		if( count($vids) == 0 )
		{
			write("No URLs found");
			$this->Delete();
			return;
		}
		$url = $vids[0]->url;
		
		if( Download::Select()->eq('url',$url)->count() > 0 )
			return Download::Select()->eq('url',$url)->current();
		
		$res = Download::Make(array(
			'broadcast_id' => $this->id,
			'url' => $url,
			'filename' => realpath($folder)."/".$this->makeFilename($url),
			'queued' => '__NOW__'
		));
		$res->Save();
		write("Movie queued for download: '{$res['filename']}'");
		return $res;
	}
	
	public function getVideos()
	{
		$res = array_fill_keys(explode(",",QUALITY),false);
		foreach( Video::Select()->eq('broadcast_id',$this->id)->results() as $v )
			$res[$v->quality] = $v;
		return array_values(array_filter($res));
	}
	
	public function makeFilename($url)
	{
		$station = $this->getStation()->name;
		$channel = $this->getChannel()->name;
		$program = $this->getProgram()->name;
		if( strcasecmp($station,$channel) != 0 )
			$name = $channel." - ".$program.".".pathinfo($url,PATHINFO_EXTENSION);
		else
			$name = $program.".".pathinfo($url,PATHINFO_EXTENSION);
		$name = str_replace(array('ä','ö','ü','Ä','Ö','Ü','ß'),array('ae','oe','üe','Ae','Oe','Ue','ss'),$name);
		$name = preg_replace('/[^a-z0-9\._-]/i',' ',$name);
		while( strpos($name,'  ') !== false )
			$name = str_replace('  ',' ',$name);
		return $name;
	}
}
class Video extends \ShellPHP\Storage\StoredObject
{
	public $id = 'int primary autoinc';
	public $broadcast_id = 'int unique broadcast_url';
	public $url = 'text unique broadcast_url';
	public $quality = 'int'; // 10,20,30 low,mid,high
	
	public static function Ensure($broadcast,$url,$quality)
	{
		$res = Video::Select()->eq('broadcast_id',$broadcast->id)->eq('url',$url)->current();
		$save = false;
		if( !$res )
		{
			$res = Video::Make()->set('broadcast_id',$broadcast->id)->set('url',$url);
			$save = true;
		}
		if( $quality && !$res->quality )
		{
			$res->set('quality',$quality);
			$save = true;
		}
		if( $save )
		{
			$res->Save();
			if( !$res->id )
				$res = Video::Select()->eq('broadcast_id',$broadcast->id)->eq('url',$url)->current();
		}
		return $res;
	}
}
