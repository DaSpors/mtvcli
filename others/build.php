<?php
$src = realpath(__DIR__.'/../src');
$dst = realpath(__DIR__.'/..');

@unlink("$dst/mtvcli.phar");
@unlink("$dst/mtvcli.phar.gz");
$phar = new Phar("$dst/mtvcli.phar",0, "mtvcli.phar");

$objects = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src,FilesystemIterator::SKIP_DOTS));
$phar->buildFromIterator($objects,$src);

$phar->setStub($phar->createDefaultStub("index.php"));
//$phar->compress(Phar::GZ);

$phar = null;
//@unlink("$dst/mtvcli.phar");
