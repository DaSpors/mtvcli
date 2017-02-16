<?php

require_once(__dir__.'/shellphp/shellphp.php');
require_once(__DIR__.'/functions.php');
require_once(__DIR__.'/model.php');
require_once(__dir__.'/update.php');

date_default_timezone_set("Europe/Berlin");

$webinterface = \ShellPHP\WebInterface\WebInterface::Make('0.0.0.0')
	->index(__DIR__."/web")
	->timer(5,10,function($args){ write("Yo ",$args); },'hallo','welt')
	->timer(1,3,function(){ throw new Exception("asdasd"); })
	->handler('search',function($request)
	{
		require_once(__dir__.'/search.php');
		write("SEARCHREQUEST",$request);
		
		$res = array();
		Movie::Search($request->arg('pattern',''),$request->arg('min_duration',0),$request->arg('fields','sender,theme,title'))
			->each(function($movie)use(&$res)
		{
			$movie->duration = formatDuration($movie->duration);
			$res[] = $movie;
			if( count($res) > 100 )
			{
				$res = array('err'=>'Too many results');
				return false;
			}
		});
		
		return \ShellPHP\WebInterface\WebResponse::Json($res);
	});

$cli = \ShellPHP\CmdLine\CmdLine::Make("MediathekView CLI","Version 0.0.0.1")
	->setName('mtvcli')
	->command('daemon')
		->text("Runs forever and provides a WebInterface at ".$webinterface->getAddress())
		->flag('-b')->alias('--background')->map('fork')->text('Switch to background')
		->handler(function($args)use(&$webinterface)
		{
			extract($args);
			if( $fork )
			{
				\ShellPHP\Process\Process::Run($GLOBALS['argv'][0],array('daemon'));
				die("Went to Background");
			}
			$webinterface->go();
		})
		->end()
	->command('update')
		->text("Updates the movie database")
		->handler(function($args)
		{
			update_movies(true);
		})
		->end()
	->command('search')
		->text("Searches the database for movies")
		->opt('-d',0)->alias('--duration')->map('min_duration')->text('Minimum duration in seconds')
		->opt('-s','')->alias('--station')->map('station')->text('Limit search to station')
		->flag('-t')->alias('--title')->map('skip_title')->text('Do not search in program names')
		->flag('-c')->alias('--channel')->map('skip_channel')->text('Do not search in channel names')
		->flag('-b')->alias('--desc')->map('skip_desc')->text('Do not search in descriptions')
		//->opt('-f','sender,theme,title')->alias('--fields')->map('fields')->text('Fields to search in. Possible values are: sender,theme,title')
		->arg('pattern')->text("Substring to search for. Just substring, no pattern supported yet")
		->handler(function($args)
		{
			update_movies(false);
			extract($args);
			require_once(__dir__.'/search.php');
			$start = microtime(true);
			search($pattern,$min_duration,$station,$skip_title,$skip_channel,$skip_desc);
			write("time needed: ".( round((microtime(true)-$start)*1000,0) )."ms");
		})
		->end()
	->command('details')
		->text("Shows details for a specific movie")
		->arg('id')->text("Movie ID as returned by a search (or a comma-separated list of IDs)")
		->handler(function($args)
		{
			extract($args);
			require_once(__dir__.'/search.php');
			details($id);
		})
		->end()
	->command('recent')
		->text("Shows a list of recent searches")
		->flag('-d')->alias('--delete')->map('delete')->text('Remove the recent search(es) given by ID')
		->flag('-c')->alias('--clear')->map('clear')->text('Removes all recent searches')
		->arg('id','')->text('ID as found in the recents table, can be comma-separated list of IDs')
		->handler(function($args)
		{
			extract($args);
			require_once(__dir__.'/search.php');
			recent($id,$delete,$clear);
		})
		->end()
	->command('subscribe')
		->text("Add a search to the subscriptions")
		->arg('name')->text('Subscription name')
		->arg('folder')->folder()->text('Folder to download files to')
		->arg('id','')->text('ID as found in the recents table, can be comma-separated list of IDs. If not specified last search will ne used.')
		->handler(function($args)
		{
			extract($args);
			require_once(__dir__.'/download.php');
			subscribe($name,$folder,$id);
		})
		->end()
	->command('subscriptions')
		->text("Displays a list of subscriptions")
		->handler(function($args)
		{
			extract($args);
			require_once(__dir__.'/download.php');
			sub_list();
		})
		->end()
	->command('unsubscribe')
		->text("Removes a subscription")
		->arg('name')->text('Subscription name')
		->handler(function($args)
		{
			extract($args);
			require_once(__dir__.'/download.php');
			unsubscribe($name);
		})
		->end()
	->command('run')
		->text("Downloads all movies of all or a specified subscription")
		->arg('name','')->text('Subscription name')
		->handler(function($args)
		{
			extract($args);
			require_once(__dir__.'/download.php');
			sub_run($name);
		})
		->end()
	->command('get')
		->text("Downloads a specific movie")
		->flag('-b')->alias('--background')->map('fork')->text('Switch to background')
		->arg('id')->text("Movie ID as returned by a search (or a comma-separated list of IDs)")
		->handler(function($args)
		{
			extract($args);
			if( $fork )
			{
				\ShellPHP\Process\Process::Run($GLOBALS['argv'][0],array("get","$id"));
				die("Went to Background");
			}
			
			require_once(__dir__.'/download.php');
			get($id);
		})
		->end()
	->command('skip')
		->text("Mark a movie as to be skipped (not downloaded)")
		->arg('id')->text("Movie ID as returned by a search (or a comma-separated list of IDs)")
		->handler(function($args)
		{
			extract($args);
			require_once(__dir__.'/download.php');
			skip($id);
		})
		->end()
	->command('list')
		->text("Displays a list queued downloads")
		->handler(function($args)
		{
			extract($args);
			require_once(__dir__.'/download.php');
			down_list();
		})
		->end()
	->command('add')
		->text("Add a movie to the download queue")
		->arg('id')->text("Movie ID as returned by a search (or a comma-separated list of IDs)")
		->handler(function($args)
		{
			extract($args);
			require_once(__dir__.'/download.php');
			add($id);
		})
		->end()
	->command('remove')
		->text("Removes a movie from the download queue")
		->arg('id')->text("Movie ID as returned by a search (or a comma-separated list of IDs)")
		->handler(function($args)
		{
			extract($args);
			require_once(__dir__.'/download.php');
			remove($id);
		})
		->end()
	->command('clear')
		->text("Clears the download queue")
		->handler(function($args)
		{
			extract($args);
			require_once(__dir__.'/download.php');
			clear();
		})
		->end()
	->go();
