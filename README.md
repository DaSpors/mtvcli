MediathekView CLI
=================
`mtvcli` is a commandline replacement for the popular JAVA program [https://github.com/xaverW/MediathekView](Mediathekview).    
It uses the same moderated movie list but has much lesser requirements regarding
soft- and hardware.     
Current state is early alpha, so there are multiple experimental features that are simply not ready.    
To be sure you don't loose data it is recommend that you create Backups periodically.

Features
========
- Search in title,theme,sender or one of theme
- Limit results to minimum duration
- List/Replay recent searches
- Download queue
- Subscriptions
- Continue stopped downloads
- Automatic qualitiy selection and fallback

Experimental/Planned
====================
- Perform donwloads in background
- Webinterface

Installation
============
You will need php and some of it's modules. Installation on debian looks like this:    
```bash
sudo apt-get install php5 php5-curl php5-sqlite
wget -O - https://github.com/daspors/mtvcli/raw/master/install.sh | sudo bash
```    
If you want to use mtvcli on Windows please contact me. In fact it should run smooth but
it will need some extra binaries and configuration.    

Usage
=====
You can get help quite simple: `mtvcli` shows a list of commands, `mtvcli --help <command>` shows more detailed instructions.    
Basically you will have to perform an update of the database every now and then like this:    
`mtvcli update`    
Once you have a fresh DB you can search for movies in it like this:    
`mtvcli search "Terra X"`    
Listings contain movie IDs that are used to reference the movie from CLI for example
to trigger a download manually:    
`mtvcli get <id>`    
The real power of mtvcli is the subscriptions. You can add a search (most likely the recent one) to
the known subscriptions. That subscriptions can be run and will download all matches movies automatically.    
A subscription will always we created from a search and needs a name and a target folder additionally:    
`mtvcli subscribe TerraX "~/media/Terra X"`    
`mtvcli subscriptions` shows you all your current subscriptions, you can remove items with from 
that list using `mtvcli unsubscribe <id>`.
The magic happens when you execute `mtvcli run`. Just try it.
