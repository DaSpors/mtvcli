<?php

function search($pattern,$min_duration,$fields)
{
	startTable(array('ID','Sent','Duration','Sender','Theme','Title'));
	Movie::Search($pattern,$min_duration,$fields)->each(function($movie)
	{
		$row = $movie->get('id','sent','duration','sender','theme','title');
		$row['duration'] = formatDuration($row['duration']);
		addTableRow($row);
	});
	flushTable();
}

function details($id)
{
	Movie::Select()->in('id',explode(",",$id))->each(function($movie)
	{
		write("Movie {$movie}: ",$row);
		write("Selected URL",$movie->selectUrl());
	});
}

function recent($id,$delete,$search,$clear)
{
	if( $delete )
	{
		if( !$id )
			throw new Exception("Missing argument: id");
		
		Search::Select()->in('id',explode(",",$id))->each(function($s){ $s->Delete(); });
		write("Search(es) removed");
		return;
	}
	
	if( $clear )
	{
		Search::Truncate();
		write("All searches removed");
		return;
	}
	
	$id = intval($id);
	if( $id )
	{
		$search = Search::Select()->eq('id',$id)->current();
		if( !$search )
			throw new Exception("Recent search not found");
		search($search['pattern'],$search['min_duration'],$search['fields']);
		return;
	}
	
	startTable(array('ID','DateTime','Pattern','Min duration','Fields'));
	Search::Select()->orderBy('searched DESC')->each(function($search)
	{
		$row = $search->get('id','searched','pattern','min_duration','fields');
		addTableRow($row);
	});
	flushTable();
}
