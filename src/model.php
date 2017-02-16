<?php

class Settings 
{
	private static $inst = false;
	private static $file = SETTINGS_FOLDER.'/mtvcli.json';
	
	public function __construct($file=false)
	{
		if( !$file )
			$file = self::$file;
		if( file_exists($file) )
		{
			foreach( json_decode(file_get_contents($file),true) as $k=>$v )
				$this->$k = $v;
		}
	}
	
	private static function _instance()
	{
		if( !self::$inst )
			self::$inst = new Settings();
		return self::$inst;
	}
	
	private function _save()
	{
		file_put_contents(self::$file,json_encode(self::_instance(),JSON_PRETTY_PRINT));
	}
	
	static function Get($name,$default=false)
	{
		$i = self::_instance();
		if( isset($i->$name) )
			return $i->$name;
		return $default;
	}
	
	static function Set($name,$value)
	{
		$i = self::_instance();
		$i->$name = $value;
		$i->_save();
	}
	
	static function Append($name,$value,$limit=false)
	{
		$i = self::_instance();
		if( !isset($i->$name) )
			$i->$name = [];
		if( !is_array($i->$name) )
			$i->$name = [$i->$name];
		array_push($i->$name,$value);
		if( $limit !== false )
			while( count($i->$name)>$limit )
				array_shift($i->$name);
		$i->_save();
	}
}

class Downloads
{
	private static $inst = false;
	private static $file = SETTINGS_FOLDER.'/downloads.json';
	
	public $queue = [];
	public $finished = [];
	public $errors = [];
	
	private function __construct()
	{
		if( file_exists(self::$file) )
		{
			foreach( json_decode(file_get_contents(self::$file),true) as $k=>$v )
				$this->$k = $v;
		}
	}
	
	private static function _instance()
	{
		if( !self::$inst )
			self::$inst = new Downloads();
		return self::$inst;
	}
	
	private static function _ifIn($obj,$array,$callback=false)
	{
		$i = self::_instance();
		foreach( $i->$array as $index=>$o )
			if( $obj->equals($o) )
			{
				if( $callback )
					$callback($i,$obj,$index);
				return $index;
			}
		return false;
	}
	
	private static function _ifNotIn($obj,$array,$callback=false)
	{
		$i = self::_instance();
		foreach( $i->$array as $index=>$o )
			if( $obj->equals($o) )
				return $index;
		if( $callback )
			$callback($i,$obj);
		return false;
	}
	
	private function _save()
	{
		file_put_contents(self::$file,json_encode(self::_instance(),JSON_PRETTY_PRINT));
	}
	
	public static function Queue($bc)
	{
		self::_ifNotIn($bc,'queue',function($i,$obj)
		{
			$i->queue[] = $obj;
			$i->_save();
		});
	}
	
	public static function ListQueue()
	{
		$i = self::_instance();
		return $i->queue;
	}
	
	public static function Remove($bc)
	{
		self::_ifIn($bc,'queue',function($i,$obj,$index)
		{
			array_splice($i->queue,$index,1);
			$i->_save();
			$i->_save();
		});
	}
	
	public static function Skip($bc)
	{
		self::_ifNotIn($bc,'finished',function($i,$obj)
		{
			$i->finished[] = $obj;
			$i->_save();
		});
	}
	
	public static function Error($bc)
	{
		self::_ifNotIn($bc,'errors',function($i,$obj)
		{
			$i->errors[] = $obj;
			$i->_save();
		});
	}
	
	public static function Finished($bc)
	{
		self::Remove($bc);
		self::Skip($bc);
	}
	
	public static function Clear()
	{
		$i = self::_instance();
		$i->queue = [];
		$i->_save();
	}
	
	public static function IsFinished($bc)
	{
		return self::_ifIn($bc,'finished') !== false;
	}
	
	public static function Start($bc,$folder,$fork=false)
	{
		$i = self::_instance();
		
		$id   = $bc->id;
		$url  = $bc->selectStream();
		$file = $folder.'//'.$bc->makeFilename($url);
		
		write("");
		write("Downloading movie $id");
		write("  Station  ".$bc->station);
		write("  Channel  ".$bc->channel);
		write("  Title    ".$bc->title);
		write("  URL      $url");
		write("  Filename $file");
		
		if( file_exists($file) )
			$i->bytes_done = filesize($file);
		else
			$i->bytes_done = 0;
		
		$result = downloadFile($url,$file,array('Downloads','downloadProgress'));
		
		if( !$result )
		{
			@rename($file,"$file.error");
			write("Error downloading movie: $url");
			self::Error($bc,'download error');
			return false;
		}
		if( filesize($file) < 100 && stripos(trim(file_get_contents($file)),'not found')!==false )
		{
			unlink($file);
			write("File not found: $url");
			self::Error($bc,'not found');
			return false;
		}
		
		self::Finished($bc);
		
		return true;
	}
	
	public static function downloadProgress($resource,$download_size, $downloaded, $upload_size, $uploaded)
	{
		if( $download_size == 0 )
			return;
		$i = self::_instance();
		$download_size += $i->bytes_done;
		$downloaded += $i->bytes_done;
		writeProgress($downloaded,$download_size);
	}
}

class Broadcast
{
	private static $fieldMap = ['id'=>'a','channel'=>'b','title'=>'c','station'=>'d','sent'=>'e','duration'=>'f','description'=>'g','streams'=>'h'];
	public $id;
	public $channel;
	public $title;
	public $station;
	public $sent;
	public $duration;
	public $description;
	public $streams = [];
	
	public function __construct($data=[])
	{
		$map = array_flip(self::$fieldMap);
		if( is_string($data) )
			$data = json_decode($data,true);
		
		foreach( $data as $k=>$v )
		{
			if( isset($map[$k]) ) $k = $map[$k];
			$this->$k = $v;
		}
	}
	
	public function equals($o)
	{
		$o = (object)$o;
		if( !isset($o->channel) || !isset($o->title) )
			return false;
		return md5($this->channel.$this->title) == md5($o->channel.$o->title);
	}
	
	public static function Make($channel,$title,$more_data=[])
	{
		$id = md5($channel.$title);
		$bc = new Broadcast(array_merge($more_data,compact('id','channel','title')));
		return $bc;
	}
	
	public static function Each($callback)
	{
		$done = 0;
		$total = Settings::Get('movie_count',500000);
		foreach( glob(SETTINGS_FOLDER.'/movies.*.dat') as $file )
		{
			$chunk = unserialize(file_get_contents($file));
			foreach( $chunk as $id=>$bc )
			{
				if( $callback($bc,$done++,$total) === false )
					return;
			}
		}
	}
	
	public static function Find($id)
	{
		$chunksize = Settings::Get('movie_chunksize',10000);
		foreach( glob(SETTINGS_FOLDER.'/movies.*.dat') as $file )
		{
			$parts = explode(".",$file);
			$max = intval($parts[count($parts)-2]);
			if( $id > $max || $id < $max-$chunksize )
				continue;
			
			$chunk = unserialize(file_get_contents($file));
			return isset($chunk[$id])?$chunk[$id]:false;
		}
	}
	
	public static function Search($search,$limit=false)
	{
		$res = [];
		extract($search);
		self::Each(function($bc,$done,$total)use(&$res,$limit,$pattern,$min_duration,$station,$skip_title,$skip_channel,$skip_desc)
		{
			writeProgress($done,$total);
			if( $bc->duration < $min_duration )
				return;
			$hit = false;
			if( !$skip_desc )
				$hit |= stripos($bc->description,$pattern) !== false;
			if( !$skip_title )
				$hit |= stripos($bc->title,$pattern) !== false;
			if( !$skip_channel )
				$hit |= stripos($bc->channel,$pattern) !== false;
			if( $station )
				$hit &= stripos($bc->station,$station) !== false;
			if( !$hit )
				return;
			
			$res[] = $bc;
			if( $limit && count($res) >= $limit )
			{
				write("Search limit $limit reached");
				return false;
			}
		});
		writeProgress(100,100);
		return $res;
	}
		
	public function __toString()
	{
		$row = [];
		foreach( get_object_vars($this) as $k=>$v )
			$row[self::$fieldMap[$k]] = $v;
		return json_encode($row);
	}
	
	public function AddStream($quality,$url)
	{
		$this->streams[$quality] = $url;
	}
	
	public function selectStream()
	{
		foreach( [1,0,2] as $q )
			if( $this->streams[$q] )
				return $this->streams[$q];
		return false;
	}
	
	public function makeFilename($url)
	{
		if( strcasecmp($this->station,$this->channel) != 0 )
			$name = $this->channel." - ".$this->title.".".pathinfo($url,PATHINFO_EXTENSION);
		else
			$name = $this->title.".".pathinfo($url,PATHINFO_EXTENSION);
		$name = str_replace(array('ä','ö','ü','Ä','Ö','Ü','ß'),array('ae','oe','üe','Ae','Oe','Ue','ss'),$name);
		$name = preg_replace('/[^a-z0-9\._-]/i',' ',$name);
		while( strpos($name,'  ') !== false )
			$name = str_replace('  ',' ',$name);
		return $name;
	}
}
