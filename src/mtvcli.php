<?php

require_once(__dir__.'/shellphp/shellphp.php');
require_once(__DIR__.'/functions.php');
require_once(__DIR__.'/model.php');
require_once(__dir__.'/update.php');

date_default_timezone_set("Europe/Berlin");

\ShellPHP\Storage\Storage::Make(SETTINGS_FOLDER.'/mtvcli.db');
Download::Cleanup();

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

write("DB: ".SETTINGS_FOLDER.'/mtvcli.db');
$cli = \ShellPHP\CmdLine\CmdLine::Make("MediathekView CLI","Version 0.0.0.1")
	->setName('mtvcli')
	->command('daemon')
		->text("Runs forever and provides a WebInterface at ".$webinterface->getAddress())
		->flag('-b',false)->alias('--background')->map('fork')->text('Switch to background')
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
		->flag('-f',false)->alias('--full')->map('full')->text('Ignore date of last update and reimport all movies')
		->opt('-a',365)->alias('--age')->map('age')->text('Maximum age of movies in days, older ones will be removed. Use 0 as forever.')
		->handler(function($args)
		{
			update_movies(true,!$args['full'],$args['age']);
		})
		->end()
	->command('search')
		->text("Searches the database for movies")
		->opt('-d',0)->alias('--duration')->map('min_duration')->text('Minimum duration in seconds')
		->opt('-f','sender,theme,title')->alias('--fields')->map('fields')->text('Fields to search in. Possible values are: sender,theme,title')
		->arg('pattern')->text("Substring to search for. Just substring, no pattern supported yet")
		->handler(function($args)
		{
			update_movies(false);
			extract($args);
			require_once(__dir__.'/search.php');
			$start = microtime(true);
			search($pattern,$min_duration,$fields);
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
		->flag('-d',false)->alias('--delete')->map('delete')->text('Remove the recent search(es) given by ID')
		->flag('-c',false)->alias('--clear')->map('clear')->text('Removes all recent searches')
		->flag('-r',true)->alias('--replay')->map('search')->text('Replay the search given by ID')
		->arg('id','')->text('ID as found in the recents table, can be comma-separated list of IDs')
		->handler(function($args)
		{
			extract($args);
			require_once(__dir__.'/search.php');
			recent($id,$delete,$search,$clear);
		})
		->end()
	->command('subscribe')
		->text("Add a search to the subscriptions")
		->opt('-q','mhl')->oneOf('hml','hlm','mhl','mlh','lhm','lmh')->alias('--quality')->map('quality')->text("Which quality to prefer. Any combination of the chars 'h', 'm' and 'l' where they stand for (h)igh, (m)edium, (l)ow")
		->arg('name')->text('Subscription name')
		->arg('folder')->folder()->text('Folder to download files to')
		->arg('id','')->text('ID as found in the recents table, can be comma-separated list of IDs. If not specified last search will ne used.')
		->handler(function($args)
		{
			extract($args);
			require_once(__dir__.'/download.php');
			subscribe($name,$folder,$id,$quality);
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
		->opt('-q','mhl')->alias('--quality')->map('quality')->text("Which quality to prefer. Any combination of the chars 'h', 'm' and 'l' where they stand for (h)igh, (m)edium, (l)ow")
		->flag('-b',false)->alias('--background')->map('fork')->text('Switch to background')
		->arg('id')->text("Movie ID as returned by a search (or a comma-separated list of IDs)")
		->handler(function($args)
		{
			extract($args);
			if( $fork )
			{
				\ShellPHP\Process\Process::Run($GLOBALS['argv'][0],array("get","-q $quality","$id"));
				die("Went to Background");
			}
			
			require_once(__dir__.'/download.php');
			get($id,$quality);
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
		->opt('-q','mhl')->alias('--quality')->map('quality')->text("Which quality to prefer. Any combination of the chars 'h', 'm' and 'l' where they stand for (h)igh, (m)edium, (l)ow")
		->arg('id')->text("Movie ID as returned by a search (or a comma-separated list of IDs)")
		->handler(function($args)
		{
			extract($args);
			require_once(__dir__.'/download.php');
			add($id,$quality);
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
