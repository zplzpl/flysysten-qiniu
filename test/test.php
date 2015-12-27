<?php
/**
 * Created by PhpStorm.
 * User: frli
 * Date: 15-12-27
 * Time: 下午1:22
 */
require_once '../vendor/autoload.php';
$access = 'jm0l4O1tJsri3hb09uX6NVD7zPhOgmitWVRcwGnv';
$secret = 'lVNQYcR9-9D0sU3iAo5R5Gx6hKDBJL9avOb8Ndd2';
$bucket = 'foyal-weixin';
$domain = 'http://7xo7tm.com1.z0.glb.clouddn.com';
$q = new Skyling\Flysystem\Qiniu\QiniuAdapter($access, $secret, $bucket, $domain);
//var_dump($q);
//echo __FILE__;
//return;
$ret = $q->write('hello.php', file_get_contents('/home/frli/Downloads/PhpStorm-10.0.1.tar.gz'), new \League\Flysystem\Config());
$ret = $q->writeStream();
var_dump($ret);
