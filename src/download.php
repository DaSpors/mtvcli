<?php

function _getBroadcastById($id)
{
	$bc = Broadcast::Find($id);
	if( !$bc )
		write("Movie $id not found");
	return $bc;
}

function get($id)
{
	$bc = _getBroadcastById($id);
	if( $bc )
	{
		Downloads::Queue($bc);
		Downloads::Start($bc,'.');
	}
}

function skip($id)
{
	$bc = _getBroadcastById($id);
	if( $bc )
		Downloads::Skip($bc);
}

function add($id)
{
	$bc = _getBroadcastById($id);
	if( $bc )
		Downloads::Queue($bc);
}

function remove($id)
{
	$bc = _getBroadcastById($id);
	if( $bc )
		Downloads::Remove($bc);
}

function clear()
{
	Downloads::Clear();
}

function subscribe($name,$folder,$id)
{
	$name = trim($name);
	$id = intval($id);
	$searches = Settings::Get('searches',[]);
	
	if( $id )
		$search = isset($searches[$id])?$searches[$id]:false;
	else
		$search = array_pop($searches);
	
	if( !$search )
	{
		write("Recent search not found");
		return;
	}

	unset($search['searched']);
	$search = array_merge(compact('name','folder'),$search);
	Settings::Append('subscriptions',$search);
	
	write("Subscription added");	
}

function sub_list()
{	
	$subs = Settings::Get('subscriptions',[]);
	
	if( count($subs) == 0 )
	{
		write("No subscriptions");
		return;
	}
	
	startTable(array('Name','Folder','Pattern','Min duration','Station','Skip title','Skip channel','Skip description'));
	foreach( $subs as $id=>$sub )
	{
		$row = array_values($sub);
		$row[3] = formatDuration($row[3]);
		addTableRow($row);
	};
	flushTable();
}

function down_list()
{	
	write("ToBeDone");
	return;
	startTable(array('ID','Filename','Progress','Started','Message'));
	foreach( Downloads::ListQueue() as $bc )
	{
		$row = $dl->get('broadcast_id','filename','downloaded','started','message');
		$row['downloaded'] = $row['downloaded']?sprintf("%3d",$row['downloaded'])."%":'';
		addTableRow($row);
	};
	flushTable();
}

function unsubscribe($name)
{
	$name = trim($name);
	$old = Settings::Get('subscriptions',[]);
	$subs = [];
	foreach( $old as $sub )
		if( $sub->name != $name )
			$subs[] = $sub;
	
	if( count($old) != count($subs) )
	{
		Settings::Set('subscriptions',$subs);
		write("Subscription removed");
	}
	else
		write("Subscription not found");
}

function sub_run($name)
{
	$name = trim($name);
	$subs = []; $bcs = [];
	foreach( Settings::Get('subscriptions',[]) as $sub )
	{
		if( $name && $sub->name != $name )
			continue;
		write("Processing subscription {$sub['name']}...");
		foreach( Broadcast::Search($sub,100) as $bc )
		{
			if( !Downloads::IsFinished($bc) )
				$bcs[] = [$bc,$sub['folder']];
		}
	}	
	foreach( $bcs as $i=>$bc )
	{
		$i++;
		write("\nStarting download $i/".count($bcs)."...");
		Downloads::Start($bc[0],$bc[1]);
	}
}
