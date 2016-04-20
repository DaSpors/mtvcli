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

function search2($pattern,$min_duration,$station,$skip_title,$skip_channel)
{
	if( $skip_title && $skip_channel )
		throw new Exception("Cannot search nowhere");
	
	$s = Search::Ensure($pattern,$min_duration,$station,$skip_title,$skip_channel);
	
	startTable(array('ID','Sent','Duration','Station','Channel','Title'));
	foreach( $s->Perform()->results() as $bc )
	{
		$row = array();
		$p = Program::Select()->eq('id',$bc->program_id)->current();
		if( $p->duration < $min_duration )
			continue;
		$row[] = $bc->id;
		$row[] = $bc->sent;
		$row[] = formatDuration($p->duration);
		$row[] = Station::Select()->eq('id',$bc->station_id)->scalar('name');
		$row[] = Channel::Select()->eq('id',$bc->channel_id)->scalar('name');
		$row[] = $p->name;
		addTableRow($row);
	}
	flushTable();
}

/*
function details($id)
{
	Movie::Select()->in('id',explode(",",$id))->each(function($movie)
	{
		write("Movie {$movie}: ",$row);
		write("Selected URL",$movie->selectUrl());
	});
}
*/

function recent($id,$delete,$clear)
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
		search2($search['pattern'],$search['min_duration'],$search['station'],$search['skip_title'],$search['skip_channel']);
		return;
	}
	
	startTable(array('ID','DateTime','Pattern','Min duration','Station','Skip title','Skip channel'));
	Search::Select()->orderBy('searched DESC')->each(function($search)
	{
		$row = $search->get('id','searched','pattern','min_duration','station','skip_title','skip_channel');
		addTableRow($row);
	});
	flushTable();
}
