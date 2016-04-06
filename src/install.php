<?php

if( !function_exists('posix_getuid') )
	die("Can only install on *nix systems\n");
if( posix_getuid() != 0 )
	die("Please run this as root or using sudo\n");

$self = Phar::running(false);
copy($self,'/usr/local/mtvcli/'.basename($self));

$code = '#!/bin/bash
php -d phar.readonly=0 /usr/local/mtvcli/mtvcli.phar.gz "$@"
';
file_put_contents('/usr/bin/mtvcli',$code);

chmod(0755,'/usr/bin/mtvcli');