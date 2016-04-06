<?php

function get($id,$quality)
{
	foreach( Movie::Select()->in('id',explode(",",$id))->results() as $movie ) 
	{
		$dl = $movie->queue();
		if( $dl ) $dl->start();
	}
}

function skip($id)
{
	Movie::Select()->in('id',explode(",",$id))->each(function($movie)
	{
		$dl = $movie->queue();
		if( $dl ) $dl->skip();
	});
}

function add($id,$quality)
{
	Movie::Select()->in('id',explode(",",$id))->each(function($movie)use($quality)
	{
		$dl = $movie->queue('.',$quality);
	});
}

function remove($id)
{
	Download::Select()->in('movie_id',explode(",",$id))->each(function($dl){ $dl->Delete(); });
}

function clear()
{
	Download::Select()->isNull('finished')->each(function($dl){ $dl->Delete(); });
}

function subscribe($name,$folder,$id,$quality)
{
	$name = trim($name);
	$id = intval($id);
	$quality = sanitizeQuality($quality);
	
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
		->set('quality',$quality)
		->set('pattern',$search->pattern)
		->set('min_duration',$search->min_duration)
		->set('fields',$search->fields)
		->Save();
	
	write("Subscription added");	
}

function sub_list()
{	
	startTable(array('Name','Folder','Quality','Pattern','Min duration','Fields'));
	Subscription::Select()->oderBy('name')->each(function($sub)
	{
		$row = $sub->get('name','folder','quality','pattern','min_duration','fields');
		addTableRow($row);
	});
	flushTable();
}

function down_list()
{	
	startTable(array('ID','Filename','Progress','Started','Message'));
	Download::Select()->isNull('finished')->oderBy('started DESC')->oderBy('filename')->each(function($dl)
	{
		$row = $dl->get('movie_id','filename','downloaded','started','message');
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
