<?php

function search($pattern,$min_duration,$station,$skip_title,$skip_channel,$skip_desc)
{
	if( $skip_title && $skip_channel && $skip_desc )
		throw new Exception("Cannot search nowhere");
	
	$dur = 0;
	foreach( array_reverse(explode(":",$min_duration)) as $i=>$v)
		$dur += pow(60,$i) * intval($v);
	$min_duration = $dur;
	
	$searched = time();
	$search = compact('searched','pattern','min_duration','station','skip_title','skip_channel','skip_desc');
	Settings::Append('searches',$search,10);
	
	startTable(array('ID','Station','Channel','Title','Sent','Duration'),101);
	foreach( Broadcast::Search($search,100) as $bc )
	{
		$row = [$bc->id,$bc->station,$bc->channel,$bc->title,date("Y-m-d H:i:s",$bc->sent),formatDuration($bc->duration)];
		addTableRow($row);
	}
	flushTable();
}

function recent($id,$delete,$clear)
{
	$searches = Settings::Get('searches',[]);
	
	if( $delete )
	{
		if( !is_numeric($id) )
			throw new Exception("Missing argument: id");
		
		$ids = array_map(function($i){ return intval(trim($i)); },explode(",",$id));
		rsort($ids);
		foreach( $ids as $i )
			unset($searches[$i]);
		Settings::Set('searches',$searches);
		write("Search(es) removed");
	}
	
	if( $clear )
	{
		Settings::Set('searches',[]);
		write("All searches removed");
		return;
	}
	
	$id = intval($id);
	if( $id )
	{
		$search = isset($searches[$id])?$searches[$id]:false;
		if( !$search )
			throw new Exception("Recent search not found");
		search($search['pattern'],$search['min_duration'],$search['station'],$search['skip_title'],$search['skip_channel'],$search['skip_desc']);
		return;
	}
	
	if( count($searches) == 0 )
	{
		write("No recent searches");
		return;
	}
	
	startTable(array('ID','DateTime','Pattern','Min duration','Station','Skip title','Skip channel','Skip description'));
	foreach( $searches as $id=>$search )
	{
		$row = array_values($search);
		array_unshift($row,$id);
		$row[1] = date("Y-m-d- H:i:s",$row[1]);
		$row[3] = formatDuration($row[3]);
		addTableRow($row);
	};
	flushTable();
}

function details($id)
{
	foreach( explode(",",$id) as $id )
	{
		$bc = Broadcast::Find($id);
		if( !$bc )
		{
			write("");
			write("Movie $id not found.");
			continue;
		}
		
		$desc = wordwrap($bc->description,75,"\n    ",true);
		write("");
		write("Movie $id details:");
		write("  Station   ".$bc->station);
		write("  Channel   ".$bc->channel);
		write("  Title     ".$bc->title);
		write("  Sent      ".date("Y-m-d H:i:s",$bc->sent));
		write("  Duration  ".formatDuration($bc->duration));
		write("  Description\n    ".$desc);
	}
}
