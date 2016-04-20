<?php

function get($id)
{
	foreach( Broadcast::Select()->in('id',explode(",",$id))->results() as $bc ) 
	{
		$dl = $bc->queue();
		if( $dl ) $dl->start();
	}
}

function skip($id)
{
	Broadcast::Select()->in('id',explode(",",$id))->each(function($bc)
	{
		$dl = $bc->queue();
		if( $dl ) $dl->skip();
	});
}

function add($id)
{
	Broadcast::Select()->in('id',explode(",",$id))->each(function($bc)
	{
		$dl = $bc->queue();
	});
}

function remove($id)
{
	Download::Select()->in('broadcast_id',explode(",",$id))->each(function($dl){ $dl->Delete(); });
}

function clear()
{
	Download::Select()->isNull('finished')->each(function($dl){ $dl->Delete(); });
}

function subscribe($name,$folder,$id)
{
	$name = trim($name);
	$id = intval($id);
	
	if( $id )
		$search = Search::Select()->eq('id',$id)->current();
	else
		$search = Search::Select()->orderBy('searched DESC')->current();
	
	if( !$search )
		throw new Exception("Search not found");
	
	$sub = Subscription::Select()->eq('name',$name)->current();
	if( !$sub )
		$sub = Subscription::Make();
	
	$sub->set('name',$name)
		->set('folder',$folder)
		->set('pattern',$search->pattern)
		->set('min_duration',$search->min_duration)
		->set('station',$search->station)
		->set('skip_title',$search->skip_title)
		->set('skip_channel',$search->skip_channel)
		->Save();
	
	write("Subscription added");	
}

function sub_list()
{	
	startTable(array('Name','Folder','Pattern','Min duration','Station','Skip title','Skip channel'));
	Subscription::Select()->oderBy('name')->each(function($sub)
	{
		$row = $sub->get('name','folder','pattern','min_duration','station','skip_title','skip_channel');
		addTableRow($row);
	});
	flushTable();
}

function down_list()
{	
	startTable(array('ID','Filename','Progress','Started','Message'));
	Download::Select()->isNull('finished')->oderBy('started DESC')->oderBy('filename')->each(function($dl)
	{
		$row = $dl->get('broadcast_id','filename','downloaded','started','message');
		$row['downloaded'] = $row['downloaded']?sprintf("%3d",$row['downloaded'])."%":'';
		addTableRow($row);
	});
	flushTable();
}

function unsubscribe($name)
{
	$sub = Subscription::Select()->eq('name',trim($name))->current();
	if( $sub )
	{
		$sub->Delete();
		write("Subscription removed");
	}
	else
		write("Subscription not found");
}

function sub_run($name)
{
	$name = trim($name);
	$sub = $name?Subscription::Select()->eq('name',$name):Subscription::Select();
	$sub->orderBy('name')->each(function($subscription)
	{
		write("Processing subscription {$subscription->name}...");
		$subscription->QueueDownloads();
	});

	write("Starting downloads...");
	foreach( Download::Select()->isNull('finished')->isNull('stopped')->isNull('pid')->results() as $dl )
		$dl->start();
}
