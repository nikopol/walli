<?php
/*
WALLi v0.2 (c) NiKo 2012-2013
standalone image wall
https://github.com/nikopol/walli

just put this single file into an http served directory 
containing [sub-dir of] media files.
*/

/*PHP CONFIG*/

//uncomment these settings if your experienced problem when uploading large files
//ini_set("upload_max_filesize", "64M");
//ini_set("post_max_size", "64M");

/*PARAMETERS*/

//walli cache dir
//(used to store generated icon files and comments)
//set to false to disable cache & comments
//otherwise set it writable for your http user (usually www-data or http)
$SYS_DIR = '.walli/';

//page title
$TITLE = 'WALLi';

//intro file, to display as startup
//(can be html, well be displayed in a dedicated div#intro)
//ignored if file doesn't exists
//set to false to disable
$INTRO_FILE = 'intro';

//timezone
//(used to date comments)
$TIMEZONE = 'Europe/Paris';

//Google Analytics accexitount key
//empty to disable
$GA_KEY = '';

//setup the root media directory to browse
//default = where this file is located
$ROOT_DIR = dirname($_SERVER['SCRIPT_FILENAME']).'/';

//delay in seconds for the client auto refresh
//0=disabled
$REFRESH_DELAY = 0;

//set to false to disable comments
//require $SYS_DIR correctly set to work
$WITH_COMMENTS = true;

//set to true to enable zip download
//require $SYS_DIR correctly set to work
//require zip support ( see http://php.net/manual/en/zip.installation.php )
$WITH_ZIPDL = false;

//set to false to disable admin options
$ADMIN_LOGIN = false;
$ADMIN_PWD   = false;

//you can setup all previous parameters in an external file
//ignored if the file is not found
@include('config.inc.php');

/*CONSTANTS*/

define('MINI_SIZE',150);
define('COOKIE_UID','wallid');
define('COOKIE_GOD','wallia');
define('VERSION','0.2');
define('FILEMATCH','\.(png|jpe?g|gif)$');

/* GLOBALS */

if(!function_exists('imagecopyresampled')) die("GD extension is required");

$uid = empty($_COOKIE[COOKIE_UID])
	? sha1($_SERVER['REMOTE_ADDR'].'-'.time())
	: $_COOKIE[COOKIE_UID];

$godmode = empty($_COOKIE[COOKIE_GOD]) ? false : $_COOKIE[COOKIE_GOD];
$godsha = sha1($uid.$ADMIN_LOGIN.$ADMIN_PWD);
if($godmode && $godmode!=$godsha) $godmode = false;

$withcom   = $WITH_COMMENTS && $SYS_DIR && file_exists($SYS_DIR);
$withadm   = $ADMIN_LOGIN && $ADMIN_PWD;
$withintro = $INTRO_FILE && file_exists($ROOT_DIR.$INTRO_FILE);
$withzip   = $WITH_ZIPDL && class_exists('ZipArchive');

/*TOOLS*/

function cache($nbd){
	header('Cache-Control: public');
	header('Expires: '.gmdate('D, d M Y H:i:s', time()+(60*60*24*$nbd)).' GMT');
}

function nocache(){
	header("Cache-Control: no-cache, must-revalidate");
	header('Expires: 0');
}

function error($c,$e) {
	header("HTTP/1.1 $c $e");
	header("Status: $c $e");
	print($e);
	exit;
}

function notfound($w){
	error(404,"$w not found");
}

function check_path($f){
	$f=preg_replace('/\/$/','',$f);
	$f=preg_replace('/^\/+/','',$f);    //avoid root dir
	$f=preg_replace('/\.\.+\//','',$f); //avoid parent dirs
	return $f;
}

function get_file_path($f){
	global $ROOT_DIR;
	return $ROOT_DIR.check_path($f);
}

function writable($path) {
	if($path && !preg_match('/\/$/',$path)) $path.='/';
	$f=get_file_path($path.'.walli.test');
	$b=@file_put_contents($f,'42');
	@unlink($f);
	return $b==2;
}

function check_sys_dir(){
	global $SYS_DIR;
	if(!$SYS_DIR) return false;
	$d=preg_replace('/\/$/','',$SYS_DIR);
	if(!file_exists($d)) @mkdir($d);
	return file_exists($d);
}

function get_sys_file($f,$throwerror=1){
	global $SYS_DIR;
	if(!check_sys_dir()) {
		if($throwerror) error(400,'SYS_DIR not defined');
		return '';
	}
	$f=preg_replace('/[\?\*\/\\\!\>\<]/','_',$f);
	return $SYS_DIR.$f;
}

function send_file($f){
	header('Content-Description: File Transfer');
	header('Content-Type: application/octet-stream');
	header('Content-Disposition: attachment; filename='.basename($f));
	header('Content-Transfer-Encoding: binary');
	header('Content-Length: '.filesize($f));
	nocache();
	readfile($f);
}

function send_json($o){
	$out=gettype($o)=='string'?$o:json_encode($o);
	header('Content-Type:application/json; charset=utf-8');
	header('Content-Length: '.strlen($out));
	print($out);
}

function ls($path='',$pattern='',$recurse=0){
	global $ROOT_DIR;
	$files=array();
	$subs=array();
	if($path && !preg_match('/\/$/',$path)) $path.='/';
	foreach (new DirectoryIterator($ROOT_DIR.$path) as $file) {
		$fn=$file->getFilename();
		if($fn[0]!='.') {
			if($file->isDir())
				$subs[]=$path.$fn.'/';
			else  if(!$pattern || preg_match('/'.$pattern.'/i',$fn)) {
				$files[]=$path.$fn;
				$size+=$file->getSize();
			}
		}
	}
	$dirs=array();
	foreach($subs as $d){
		$sub=ls($d,$pattern,1);
		if(count($sub['files'])){
			$dirs[]=$d;
			if($recurse) {
				$files=array_merge($files,$sub['files']);
				$dirs=array_merge($dirs,$sub['dirs']);
				$size+=$sub['size'];
			}
		};
	}
	return array('path'=>$path,'files'=>$files,'dirs'=>$dirs,'size'=>$size);
}

function iconify($file,$size){
	list($srcw,$srch)=getimagesize($file);
	$srcx=$srcy=0;
	if($srcw>$srch){
		$srcx=floor(($srcw-$srch)/2);
		$srcs=$srch;
	}else{
		$srcy=floor(($srch-$srcw)/2);
		$srcs=$srcw;
	}
	if(preg_match('/\.png$/i',$file))      $src=@imagecreatefrompng($file);
	else if(preg_match('/\.gif$/i',$file)) $src=@imagecreatefromgif($file);
	else                                   $src=@imagecreatefromjpeg($file);
	$dst = imagecreatetruecolor($size,$size);
	if($src) {
		imagecopyresampled($dst, $src, 0, 0, $srcx, $srcy, $size, $size, $srcs, $srcs);
		imagedestroy($src);
	} //todo else
	return $dst;
}

function load_coms($path,$file=false){
	global $uid;
	$comfile = get_sys_file($path.'.comments.json',0);
	$coms = $comfile && file_exists($comfile)
		? json_decode(file_get_contents($comfile),true)
		: array();
	foreach($coms as $k=>&$l)
		foreach($l as &$com){
			if($com['uid']==$uid) $com['own']=1;
			unset($com['uid']);
		}
	if($file) return array_key_exists($file,$coms) ? $coms[$file] : array();
	return $coms;
}

function godcheck(){
	global $godmode;
	if(!$godmode) error(401,'unauthorized');
}

/*CLIENT API*/

function GET_ls(){
	$path=check_path($_GET['path']);
	$data=ls($path,FILEMATCH);
	sort($data['files']);
	sort($data['dirs']);
	$data['coms']=load_coms($path);
	nocache();
	send_json($data);
}

function GET_count(){
	$path=check_path($_GET['path']);
	$data=ls($path,FILEMATCH);
	send_json(array('files'=>count($data['files']),'dirs'=>count($data['dirs'])));
}

function GET_img(){
	$path=check_path($_GET['path']);
	if(!file_exists($file)) notfound($file);
	header('Content-Type: image/'.pathinfo($file,PATHINFO_EXTENSION));
	header('Content-Length: '.filesize($file));
	cache(60);
	@readfile($file);
}

function GET_mini(){
	$file=get_file_path($_GET['file']);
	if(!file_exists($file)) notfound($file);
	$cachefile=get_sys_file($_GET['file'].'.mini.png',0);
	header('Content-Type: image/png');
	cache(60);
	if($cachefile && file_exists($cachefile)){
		@readfile($cachefile);
		exit;
	}
	if(is_dir($file)){
		$mini=imagecreatetruecolor(MINI_SIZE,MINI_SIZE);
		$bgc=imagecolorallocate($mini,255,255,255);
		imagefill($mini,0,0,$bgc);
		$list=ls($_GET['file'],FILEMATCH,1);
		$size=floor((MINI_SIZE-2)/3);
		$n=0;
		shuffle($list['files']);
		foreach($list['files'] as $f){
			$img=iconify(get_file_path($f),$size-2);
			$x=($n % 3) * $size;
			$y=floor($n / 3) * $size;
			imagecopyresampled($mini, $img, $x+2, $y+2, 0, 0, $size-2, $size-2, $size-2, $size-2);
			imagedestroy($img);
			$n++;
			if($n>8) break;
		}
	} else
		$mini=iconify($file,MINI_SIZE);
	if($cachefile) @imagepng($mini,$cachefile);
	imagepng($mini);
	imagedestroy($mini);
}

function POST_comment(){
	global $uid, $TIMEZONE;
	$file=$_POST['file'];
	$comfile=get_sys_file(dirname($file).'.comments.json');
	$what=$_POST['what'];
	if(empty($what)) error(400,'empty comment');
	$who=$_POST['who'];
	if(empty($who)) $who='anonymous';
	$coms=file_exists($comfile)?json_decode(file_get_contents($comfile),true):array();
	if(empty($coms[$file])) $coms[$file]=array();
	$nb=count($coms[$file]);
	$id=$nb ? $coms[$file][$nb-1]['id'] : 0;
	date_default_timezone_set($TIMEZONE);
	$coms[$file][]=array(
		'what'=>$what,
		'who' =>$who,
		'when'=>date('c'),
		'uid' =>$uid,
		'id'  =>++$id
	);
	@file_put_contents($comfile,json_encode($coms));
	if(!file_exists($comfile)) error(401,'cannot write comment file');
	send_json(array(
		'file'=>$file,
		'coms'=>load_coms(dirname($file),$file),
	));
}

function POST_uncomment(){
	global $uid;
	$file=$_POST['file'];
	$comfile=get_sys_file(dirname($file).'.comments.json');
	$id=$_POST['id'];
	$coms=file_exists($comfile)?json_decode(file_get_contents($comfile),true):array();
	if(empty($coms) || !array_key_exists($file,$coms)) error(404,'comment not found');
	$c;
	$l=array();
	foreach($coms[$file] as $com){
		if($com['id']==$id) $c=$com;
		else $l[]=$com;
	}
	if(empty($c)) error(404,'comment not found');
	if($c['uid']!=$uid) error(403,'forbidden');
	$coms[$file]=$l;
	@file_put_contents($comfile,json_encode($coms));
	send_json(array(
		'file'=>$file,
		'coms'=>load_coms(dirname($file),$file),
	));
}

function POST_zip(){
	$lst=explode('*',$_POST['files']);
	$fn ='pack-'.time().'.zip';
	$fz = get_sys_file($fn);
	$zip=new ZipArchive;
	$r=$zip->open($fz,ZIPARCHIVE::CREATE);
	if($r!==true) error(400,"error#$r opening zip");
	$nb=0;
	foreach($lst as $f){
		$f = get_file_path($f);
		if(file_exists($f)){
			$zip->addFile($f,basename($f));
			$nb++;
		}
	}
	$zip->close();
	if(!$nb) error(400,'empty zip');
	send_json(array(
		'zip'=>$fn,
		'nb' =>$nb,
	));
}

function GET_zip(){
	$f = $_GET['zip'];
	send_file(get_sys_file($f));
	unlink($f);
}

/*ADMIN API*/

function GET_flush(){
	global $SYS_DIR;
	godcheck();
	$s=array('count'=>0,'flushed'=>0);
	if($SYS_DIR){
		$ls=ls($SYS_DIR,'\.(png|zip)$');
		$s['count']=count($ls['files']);
		foreach($ls['files'] as $f)
			if(@unlink($f)) $s['flushed']++;
	}
	nocache();
	send_json($s);
}

function GET_info(){
	godcheck();
	phpinfo();
}

function GET_diag(){
	global $withcom,$withadm,$withintro,$withzip,$REFRESH_DELAY;
	godcheck();
	$path=check_path($_GET['path']);
	$ls=ls($path,FILEMATCH,1);
	//calc max subdirs depth
	function depth($p){
		$d=preg_replace('/\/$/','',$p);
		return count(split('/',$d));
	}
	$mindepth=depth($path);
	$maxdepth=0;
	foreach($ls['dirs'] as $s) {
		$d = depth($s);
		if($d>$maxdepth) $maxdepth=$d;
	}
	$maxdepth=$maxdepth > $mindepth ? $maxdepth-$mindepth : 0;
	send_json(array(
		'path'   => $path,
		'stats'  => array(
			'images'        => count($ls['files']),
			'size'          => $ls['size'],
			'subdirs'       => count($ls['dirs']),
			'max subdirs depth' => $maxdepth,
		),
		'checks' => array(
			'admin'         => $withadm,
			'upload'        => writable($path),
			'zip download'  => $withzip,
			'comments'      => $withcom,
			'cache'         => check_sys_dir(),
			'intro'         => $withintro,
			'auto refresh'  => $REFRESH_DELAY
		)
	));
}

function POST_img() {
	godcheck();
	$path=check_path($_GET['path']).'/';
	if(!is_dir($path)) error(404,'path '.$path.' not found');
	$nb=$sz=0;
	foreach($_FILES as $k => $f)
		if(@move_uploaded_file($f['tmp_name'], $ROOT_DIR.$path.$f['name'])) {
			$nb++;
			$sz+=$f['size'];
		}
	send_json(array(
		'added'=>$nb,
		'size' =>$sz,
		'path' =>$path
	));
}

function POST_del(){
	godcheck();
	$lst=explode('*',$_POST['files']);
	$del=$com=0;
	foreach($lst as $file){
		$f = get_file_path($file);
		if(file_exists($f) && @unlink($f)) {
			$del++;
			$comfile=get_sys_file(dirname($file).'.comments.json',0);
			$coms=$comfile && file_exists($comfile)?json_decode(file_get_contents($comfile),true):array();
			if(empty($coms) || array_key_exists($file,$coms)) {
				$com+=count($coms[$file]);
				@file_put_contents($comfile,json_encode($coms));
			}
		}
	}
	send_json(array(
		'deleted'=>$del,
		'coms'   =>$com
	));
}

/*MAIN*/

if(!empty($_REQUEST['!'])){
	$do = $_SERVER['REQUEST_METHOD'].'_'.$_REQUEST['!'];
	if(!function_exists($do)) die("$do is not a function");
	call_user_func($do);
	exit;
}

//admin login
if($_SERVER["QUERY_STRING"]=="login" && $ADMIN_LOGIN && $ADMIN_PWD){
	if(!isset($_SERVER['PHP_AUTH_USER'])){
		if($ADMIN_LOGIN) header('WWW-Authenticate: Basic realm="'.$TITLE.' admin"');
		header('HTTP/1.0 401 Unauthorized');
	} else
		$godmode = $_SERVER['PHP_AUTH_USER']==$ADMIN_LOGIN && $_SERVER['PHP_AUTH_PW']==$ADMIN_PWD;
}else if($_SERVER["QUERY_STRING"]=="logout"){
	$godmode = false;
	setcookie(COOKIE_GOD,false);
}

/*COOKIES*/

if(empty($_COOKIE[COOKIE_UID]))             setcookie(COOKIE_UID,$uid);
if($godmode && empty($_COOKIE[COOKIE_GOD])) setcookie(COOKIE_GOD,$godsha);
if(!$godmode && $_COOKIE[COOKIE_GOD])       setcookie(COOKIE_GOD,false);

$intro = $withintro
	? @file_get_contents($ROOT_DIR.$INTRO_FILE)
	: false;

?>
<!doctype html>
<!--
WALLi v0.1 (c) NiKo 2012-2013
Stand-Alone Image Wall
https://github.com/nikopol/walli
-->
<html>
<head>
	<meta charset="utf-8"/>
	<meta http-equiv="X-UA-Compatible" content="IE=Edge"/>
	<!--<meta id="viewport" name="viewport" content="height=device-height,width=device-width,initial-scale=1.0,maximum-scale=1.0"/>-->
	<meta name="apple-mobile-web-app-capable" content="yes"/>
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<link rel="shorcut icon" type="image/png" href="data:image/png;base64,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" />
	<style>
		@import url(http://fonts.googleapis.com/css?family=Satisfy);
		*{margin:0;padding:0}
		body{overflow:hidden}
		a,a:visited{text-decoration:none}
		input[type="text"],textarea,select{width:380px}
		ul{list-style:none}
		::-webkit-scrollbar{background:transparent;width:6px;height:6px;border:none}
		::-webkit-scrollbar:hover{background:#666}
		::-webkit-scrollbar:vertical{margin-left:5px}
		::-webkit-scrollbar-thumb{background:#aaa;border:none;border-radius:6px}
		::-webkit-scrollbar-button{display:none}
		#copyright{color:#000;position:absolute;font:normal 10px Arial,Helvetica;z-index:99;bottom:2px;right:20px}
		#copyright a,#copyright a:visited{color:#aaa}
		#log{position:absolute;top:0;right:-501px;bottom:0;width:500px;z-index:32000;font:normal 12px Monaco,"DejaVu Sans Mono","Lucida Console","Andale Mono",monospace;background-color:#000;padding-left:2px;color:#fff;opacity:0.8;overflow:scroll;-webkit-transition:right 0.5s ease;-moz-transition:right 0.5s ease;transition:right 0.5s ease}
		#log.active{right:0;-webkit-transition:right 0.5s ease;-moz-transition:right 0.5s ease;transition:right 0.5s ease}
		#log .debug{color:#777}
		#log .info{color:#ddd}
		#log .warn{color:#fc0}
		#log .error{color:#f88}
		#log .timer{color:#aaa;border-right:1px solid #aaa;margin-right:3px;padding-right:3px}
		#osd{display:hidden;position:absolute;top:10px;left:60px;right:20px;height:40px;z-index:0;color:#fff;font:bold 30px Arial;opacity:0;text-align:right;padding:0 10px;-webkit-transition:all 0.5s ease;-moz-transition:all 0.5s ease;transition:all 0.5s ease}
		#osd.active{display:block;position:absolute;z-index:5;opacity:1;-webkit-transition:opacity 0.2s ease-in-out;-moz-transition:opacity 0.2s ease-in-out;transition:opacity 0.2s ease-in-out}
		#osd.error{color:#f77}
		#progress{display:none;position:absolute;left:0;bottom:0;height:25px;right:0}
		#progress.active{display:block;z-index:50;background:rgba(50,50,50,.6)}
		#progressbar{position:absolute;height:25px;background:#666;z-index:51}
		#progresstext{position:absolute;width:100%;font-size:22px;text-align:center;color:#eee;z-index:52}
		#thumb{position:absolute;top:0;bottom:0;left:0;right:0;opacity:0;z-index:0;overflow:auto;padding:5px 10px 10px 10px;-webkit-transition:all 0.5s linear;-moz-transition:all 0.5s linear;transition:all 0.5s linear}
		#thumb.active{opacity:1;z-index:1;-webkit-transition:all 0.5s linear;-moz-transition:all 0.5s linear;transition:all 0.5s linear}
		#thumbbar{position:absolute;left:0;right:0;top:0;padding:5px 5px 0 20px;font-size:0;clear-after:both}
		#thumbbar h1{font:bold 24px Arial;display:inline;margin:0 4px 0 -14px;padding:0 12px;cursor:default}
		#thumbbar button{position:relative;display:inline;height:28px;font:normal 18px Arial;vertical-align:top;padding:0 5px}
		#bzip.empty,#bzip.hide,#bzip.all{display:none}
		#path{display:inline}
		#diapos{position:absolute;top:40px;padding:5px 5px 0 5px;left:0;right:0;bottom:0;overflow:auto}
		li.diapo{position:relative;display:inline;float:left;width:150px;height:168px;overflow:hidden;text-align:center;vertical-align:bottom;margin:5px;padding:0}
		li.diapo img{display:block}
		li.diapo:after{content:attr(title)}
		li.diapo.up{display:none}
		li.diapo.loaded{-webkit-transition:all 0.4s linear;-moz-transition:all 0.4s linear;transition:all 0.4s linear}
		li.diapo.loaded span.minicom{position:absolute;top:5px;right:5px;width:24px;height:21px;font-size:10px;line-height:17px;text-align:center;color:#fff;background:transparent url(data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAABgAAAAVCAYAAABc6S4mAAAABHNCSVQICAgIfAhkiAAAAAlwSFlzAAAD0AAAA9ABSs1rUAAAABl0RVh0U29mdHdhcmUAd3d3Lmlua3NjYXBlLm9yZ5vuPBoAAAEDSURBVDiN7ZUxTsNAEEXfj30JTkGXiipF7kCLRI+EtIWF5M644AzcgYqKDgpOkBuEghIJu2AzFLsOjuI1RqL0l1Zj7cy+L89Ks6rr+hVYSjIAsxDiohf3+wO1+1zcI9a85MAyFqsH6n8fqYMMSTo4ul6Mgf5Ds8FsMBvMBhOUx7jjcGr2lY0BJPnE8BOwyM3sEjhLHD4fMfgidODBzD4SNc9Kjd6qqk6yLNsOpHw0fZR07ZzbJODAT4uOE3m+Ijwg3YA3QJI2wJVz7mkM3Cl5yWa2MrMdgeqBd0kXTdOcToWP/gGwJrSiNbPbtm3vyrL8nAr+zUCES7z33t8URfH2V3Cnb5qdYd58KZMsAAAAAElFTkSuQmCC) no-repeat center center}
		li.diapo.loaded.up:hover{-webkit-box-shadow:none;-moz-box-shadow:none;box-shadow:none}
		li.diapo input[type="checkbox"]{position:absolute;top:5px;left:5px}
		#view{position:absolute;overflow:hidden;top:0;bottom:0;left:0;right:0;opacity:0;z-index:0;-webkit-transition:all 0.5s linear;-moz-transition:all 0.5s linear;transition:all 0.5s linear}
		#view.active{opacity:1;z-index:1;-webkit-transition:all 0.5s linear;-moz-transition:all 0.5s linear;transition:all 0.5s linear}
		#view img{position:absolute;display:block;opacity:0;left:0;top:0;width:1px;height:1px;z-index:0}
		#view img.touch{opacity:1;z-index:4}
		#view img.animated{opacity:1;z-index:4;-webkit-transition:all 1s ease-out;-moz-transition:all 1s ease-out;transition:all 1s ease-out}
		#view #slide{position:absolute;left:60px;right:0;top:0;bottom:0;overflow:hidden;-webkit-transition:all 0.5s ease;-moz-transition:all 0.5s ease;transition:all 0.5s ease}
		#view.com #slide{left:360px;-webkit-transition:all 0.5s ease;-moz-transition:all 0.5s ease;transition:all 0.5s ease}
		#view.comfix #slide{left:360px}
		#view #comments{position:absolute;top:0;left:-300px;bottom:0;width:300px;z-index:7;overflow:hidden;-webkit-transition:all 0.5s ease;-moz-transition:all 0.5s ease;transition:all 0.5s ease}
		#view.com #comments{left:60px;-webkit-transition:all 0.5s ease;-moz-transition:all 0.5s ease;transition:all 0.5s ease}
		#view.comfix #comments{left:60px}
		#coms{overflow:auto;position:absolute;top:3px;bottom:160px;width:285px}
		#coms li{position:relative;padding:3px;margin:5px 5px 10px 5px}
		#coms li button.del{position:absolute;top:1px;right:2px;font-size:12px}
		#newcom{position:absolute;bottom:5px;width:280px;height:140px;padding:3px}
		#who, #what{width:280px}
		#what{height:88px;resize:none}
		#viewbar{position:absolute;top:0;left:0;width:60px;bottom:0;z-index:8}
		#viewbar button{position:absolute;border:none;background-color:transparent;opacity:0.5;-webkit-transition:all 0.3s linear;-moz-transition:all 0.3s linear;transition:all 0.3s linear}
		#viewbar button:hover{opacity:1;-webkit-transition:all 0.3s linear;-moz-transition:all 0.3s linear;transition:all 0.3s linear}
		#viewbar button.active{opacity:1}
		#bprev{left:4px;top:2px;width:24px;height:48px;background:url(data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAABUAAAAgCAYAAAD9oDOIAAAABHNCSVQICAgIfAhkiAAAAAlwSFlzAAAJ2AAACdgBx6C5rQAAABl0RVh0U29mdHdhcmUAd3d3Lmlua3NjYXBlLm9yZ5vuPBoAAAKnSURBVEiJtdZPSBRRHAfw7++nqzuHDCO8VAfB6BBE0EGCIKSk6BIECxFS2qFjBcvOzkgpI+S+ndlNYotIkiAhD0YEEtRJiyAIunQsiaCgDmFU5Jrsvl8H1xqHmc3dtXf8/fnw5g+/90hE0OhSSnUxc0FE2rTWV6hRVCl1mIjuANhcCb1rCM1msxcAOADYH2+uBxsbGzOWl5evA0iE5WtGXdfdrrW+R0R7o2o4KhG2MpnMfhGZC4ACYL4u1HXdAWaeAdDhC/8E0EdE0zWh4+PjsWw2e1VErgFo8aXea60PpdPpmWBP1Xeaz+e3lkqluwAO+ONENKu17rdt+2tYXyQ6Ojq6h5mniGhHIHWjs7PzUiKRKEf1hqKu655oamq6CcDw7e4XEZ1PpVJTUVgo6jgOG4ZxWUSSgbpPWutTlmW9+he4BnVdd5NhGBMicjRQ85KZ+1Kp1Of1gEDl6yulukRkNgiKyOTS0tKxWkAAaM5kMt3MfB9/BwIAlADYlmXdqgX7gzJzxg+KyAIRnU6n08/qAYGVx4/8NepGtdZDAL6tBohoC4CHnuedqxu1bfuFiPQAeOOLN2utc0qpguM4LVHNkSgAWJY1T0Q9RPTYnySiM/F4/FEul+sIb6+CAoBpmj+KxeJJIsoHarrL5fJTz/Mi52ckCgDDw8PaNE2HiPoBFH2pbSLyxPO80ElfFfXt+kG5XO4VkQ+rMRExtNYTSqkRx3GqjszI5ODg4OtYLHYQwHN/nIguxuPx6UKh0FYzCgDJZPJLe3v7cQC3A6nexcXFOc/zdob1rfuIdl13QEQ8+Ka/iHwHcJaZ94mIXTMKrBx8zDyJteeUBvAWwK660MqON/aIBgDTND+2trYeATAdVfNfrj0bfkETkYWG0QrcxcwjWuvdANK/AXuBG01UJ2ZnAAAAAElFTkSuQmCC) no-repeat center center}
		#bnext{left:32px;top:2px;width:24px;height:48px;background:url(data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAABUAAAAgCAYAAAD9oDOIAAAABHNCSVQICAgIfAhkiAAAAAlwSFlzAAAJ2AAACdgBx6C5rQAAABl0RVh0U29mdHdhcmUAd3d3Lmlua3NjYXBlLm9yZ5vuPBoAAAIRSURBVEiJrdU7aBRRGMXxf2IiWEjEV6WNSMBGLRUEWRCiBFQ04gMURRGEoIWPgxCJlcwpIqSxkOALCzEiRBuDogaEYKWkjNFGbYIIa4pIkGixN3IdZpe9u37d/c7Mj8vcx7RkWVYCMuADcFXSFE1WK3AL2ADsBl7Z3vE/0BXRuAN4ZPtcM2hLlmU/qmTDQK+k2VS0tUZ2ABi1vaZZ9CMwH403A69tb20GfQIcBGai3mrgqe0TjaJIGgVKQLy1FgODtq/bbk9GAzwZ4Be56BQwYntlMhrgMtADDOaibcCY7Y3JaIDnJV0JM/wZRWuB57b3JaMR/hDoAr5G7SXAHdv9tv9x6kID/A7YDrzNReeBB7aXJqMBnga6gXu5aCeVe2N9MhrgOUm9wAXgVxR1BnhLMhrhN4G9wPeo3QFcaxgN9bug19Ywavs0MAIsj9pl4FJbA1g7MAAcz0WTwCFJU0mo7VXAfSB/az0DTkqagYTVt70JGCsAB8IM/95sdc3U9n7gBpVTtFCzwBlJj/PP10TD8eujsifj+gwcljRR9F5VNBy7IWBXLnoDHJP0rdq7hd/U9jrgZQE4BOypBRbO1HYJuAssi9pzwEVJt2th1dBu4CywKOpNA0cljdcDFqGdufF74IikL/WCUHufDgNdqSAUr/480C8p/2+qu1qBT9G4DPQ0Ay6gl4EJYBwoScr/lpPrD6BJpvVDUJEtAAAAAElFTkSuQmCC) no-repeat center center}
		#bcom{left:6px;top:148px;width:48px;height:48px;background:url(data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAADAAAAAqCAYAAAD1T9h6AAAABHNCSVQICAgIfAhkiAAAAAlwSFlzAAAHoAAAB6ABnZaTqAAAABl0RVh0U29mdHdhcmUAd3d3Lmlua3NjYXBlLm9yZ5vuPBoAAAKzSURBVFiF7ZlPa9RAGMafdxJ2k1IoPVmopz0rRf0AWi+9eCiC3nqw36DsJtkVcdnq1tlspYKg4BfwUFAvRTxUCgU9VAq96MWFouBFUPAPmyLm9bDZ7dimNcl2SYX8IMxM3jczz7ObSeANSSmvA7gBwATAwQGlv3d8UH8QsV6fiBgAOICIdpj5rg7gJoB8kEzBcexg5l6fiLqtq2NX/HcAb7s52DUS1obGOj9MZ/Zuy8yRr48RGwNgABjRFYeb5XL5wn7vxw8p5RoRnQcAkbaYfskMpE1mIG0yA2mTGUibzEDaZAbSJjOQNpmBtMkMpE1mIG0yA2nz3xvoFbaEEAXXdWXcCZh53XGclSSLNxqNCSKaRqcuGxkhRKFbaiQppY8+66FCiHOWZW1Gza/X6+OaptWJaAb93QWsA3gK4HIfk4CZR6Lk1Wq14Xw+b+u6XgQw1M+aAEBEL/RCoXB1e3t7gplzcS5m5mcATgDYabfbrw/LXV5e1lqt1jXDMG6hU5jt8hXAbSJ6FVc8M7dt294itWwdFdd1TzLzx2C45jjO5EG5jUZjCkATwGnl9C8AD3K53Pzc3NyX2AIU9H+nhNITTEQvwxIWFhZOaZq2CGBqT+gJMzvlcvl9wrX/IpEBZr6o9FfVWLPZHPN9f17TtFkAmhLaEEIULctaTyY1nKQGJoPvFz9GR0c3AKBWqw2ZpllkZhvAsJL+AUDFcZzH2P1sdGTE3gNSygIRtYLhc8/zLpmmOcPMdQDjSuo3AHc8z7tXrVa9I9K7j9j/gBBiUjG9YxjGG2Y+o6T8BvDI9/1qpVL5fBQiDyO2AfX+BzC9J7xCRJZt2+/6kxWdJHsg7JG5RURF27ZXQ2IDJdZrfGlpyUTn5dXlExHNep53Ng3xQLJNfJ+IrhDRQyHEYqlU+jkgbZH4A1OS9uj03IqAAAAAAElFTkSuQmCC) no-repeat center center}
		#bcom span{position:absolute;left:0;top:4px;width:48px;text-align:center;font:bold 24px Arial}
		#bplay{left:6px;top:102px;width:48px;height:42px;background:url(data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAADAAAAAdCAYAAADsMO9vAAAABHNCSVQICAgIfAhkiAAAAAlwSFlzAAAILgAACC4B4ThkEQAAABl0RVh0U29mdHdhcmUAd3d3Lmlua3NjYXBlLm9yZ5vuPBoAAAJUSURBVFiF7Zg9aBRRFIW/OxkzE5VYKoqlin/YBH8KOwWFtGKlCBYahGWRZN9a6LLIxvc2jSESFAWx0pgyCFpqYSMWNpEIaQQLBVFEwyzqXgt3yRhm40ZI9gk5MPDuvefCOe8+5k+cczlVvQr04gfeAEeNMW/bIYeqOgysW15NS8J24CxQyipOTk52zc7O5kTkGHAj5E/xtRUQ2ApdQNhYb8giOOf2AXdEpK+R2hOm6jVjTLyMAheFtfaMiNzNqo2NjUVzc3NXgALzJgE2h1kNPmFkZORwvV6/DezIqntroFwu9/b09DhVPQdIMy8ij1X1CA3tQacELgYR2R/H8bSqnmde/EcROV0oFI4DP5tcLyegqofSsYhMBEGQGxwc/LCQ66WBFN4BA4VCYaoVwZsjJCLvU6ECt5Ik2WWMaSkePJpAkiRPoigaF5FtQRBUhoaGnrbT542BUqlUBy4stc+bI/SvWDXQaawa6DT+ewPe3EYBnHMDQHcURffy+fzndnq8mUC1Wj0FjAPXa7Xaa2vtiXb6vDGgqltS4SYReWitnapUKlsX6/PGQBZEpD8Mw+lqtZorl8uZWn01MAP8aKzXq+poHMfPnXN7FxK9NKCqD4A+4EUqfQB4aa0dJvWF5qUBAGPMqyRJDgJ54GsjvUZELgHdTZ63BuD3G6oxZhTYDTzK4qSfA5FzLlkRZdnoalVo/KXrt9aeFJFRYGOj9D0AvqS4UQev9GZ+yzJSLBYnVHWniNwEZkTkYigil1X1GrD2Lzu0UngWBMH9VsVisfgJGGjGvwD4Z6eH9jMo/QAAAABJRU5ErkJggg==) no-repeat center center}
		#bthumb{left:6px;top:50px;width:48px;height:48px;background:url(data:image/png;base64,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) no-repeat center center}
		#mask{display:none;z-index:50;position:absolute;top:0;bottom:0;left:0;right:0}
		#mask.active{display:block}
		#mask #loading{position:absolute;bottom:28px;left:0;right:0;text-align:center;font:normal 32px Arial}
		#intro{z-index:90;position:absolute;top:0;bottom:0;left:0;right:0}
		#intro.hide{z-index:0;opacity:0}
		#intro h1{font-size:60px}
		#intro h2{font-size:30px;margin-bottom:10px}
		#godbar{position:absolute;right:20px;top:5px;z-index:30}
		#godbar button{float:right;height:16px;width:16px;margin:0 0 3px 0;opacity:0.3;border:none;margin-left:5px}
		#godbar button:hover{opacity:1;-webkit-transition:all 0.3s linear;-moz-transition:all 0.3s linear;transition:all 0.3s linear}
		#godbar button.hide{display:none}
		#blogin{background:url(data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAABAAAAAQCAYAAAAf8/9hAAAABmJLR0QA/wD/AP+gvaeTAAAACXBIWXMAAAsTAAALEwEAmpwYAAAAB3RJTUUH3QEQDRoCWfbT2gAAANxJREFUOMulk7EOQUEQRQ9P3g9o/IRKITqlT5DMF9AoVHqJTlQ0lPcbtFQqlY+g0ko0T7OE8d7LiptM9mb27t2ZnWwlyzI8zKwJzIBWSB2BiaST11a8gZl1gB2QOu0d6Eo6vCerfGMZDq+AeohVyC29OM+gGdaxpKukKzB2e6UGCYCk2zPxxpMYg5/wt8FrCma2AEYRZ+7AUNLGVzCIvDQFpnkt+LnvgXaIvdtrPEmt5Ka+pEtorw+cf33EpIBHG6wLeLRBr4B/oObGk7qP1SsZ5VcF8xzhNoTHS/sAZQw6nLbFjCkAAAAASUVORK5CYII=) repeat center center}
		#blogout{background:url(data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAABAAAAAQCAYAAAAf8/9hAAAABmJLR0QA/wD/AP+gvaeTAAAACXBIWXMAAAsTAAALEwEAmpwYAAAAB3RJTUUH3QEQDRo14Ut21QAAAOFJREFUOMulkq1uQkEQhT9+wgv0OVCIBldZZN1N5glQkKD6BLimNWBqj0WTYEChUDwEKGyTa27NQJrpbrOkJ5ns5OyZs7Oz22qahggz6wNzYODUAXiVdIzaVjQwsyGwBXpBWwNPkvY/yTa/sfDiJfDgsXRuEcUpg76vM0kXSRdgFvb+NOgASPq6Ep6vgU0UdymEpFGKb/NP3F7BzN6BSWHdh6Rp7GB8x8Hj1BXiu++AR49d2OuVDLGSdPbrVcDp3iF2MnmxwWcmLzZ4zuTZj1THQZrZS6auTnXwlhCuPCJu2m/z6z0KUUzH3gAAAABJRU5ErkJggg==) repeat center center}
		#bupload{background:url(data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAABAAAAAQCAYAAAAf8/9hAAAAM0lEQVQ4y2NgoBAw4pKIior6j8xftmwZVrVMlLpgGBjAiC3AiAXLli1jHC5hMJqQBhgAABzZDBpqRcGNAAAAAElFTkSuQmCC) repeat center center}
		#bflush{background:url(data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAABAAAAAQCAYAAAAf8/9hAAABKElEQVQ4y8WTT0eEURTGf1MjhohW0QeIuLs2Q6uI2bQrw8msWrSPodVQtCl9g1nFPGrVNiIiZhuXaBsRrYaYTdHmvLre7jvVqrM59z7nPufc8w/+S8ysDVCvMDaAdWDBoRfgWtLY7T1gE7ioZ8h7QA+YK5lGZnYIzAAHQPz2AzM7Azp+fQDu/LwKLAOn5YD1hNx18huwK0kl5+dAO+vAc953bEvSVYm8kyMDTLleA+aBYYbcAfpV3ShSWHI9zLyJwEoGH1e2MRVJ95PshYNH181fDJABs8ClpNeiBjfACGiaWWsCuQUMgGPvFtMAMcb3EMKHT99GCOEpxhgzkftAA+hJugWolR4NAEuKlw5S+CqLtgtO7S+jDBxJOknB2g/LtOjQc7pMqXwCiPZdkmM4XEAAAAAASUVORK5CYII=) repeat center center}
		#bdel{background:url(data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAABAAAAAQCAYAAAAf8/9hAAABD0lEQVQ4y6WTMWoDMRBFn9dpFnyAXEJN6oUFFwGDcWFwswfIDQypA7nKNAFDIBBwYVjYG+gEuYNBkCpFvoysVeyABxa00vw/X39GcGNM0p+u61pgC2zMLGRnNbADXs1sGBEI/AnUwAFYRhKB90ADBOAxkkyUMAc+BI5xAJZaR3CMACzMrL/TxjYDA8ylaJqBUe4z0FfaWAN9waO2AEa569yDWhXbK8b3kh9KXbhGcgYGqApJ0wvVR2dVUn1WcDuPBthLKWkbZ5Le/HMAB81CiAp2F9we/lDynl7hRcMxMkxfThKE+SXQWC4SkpPbZnbMSM5G+eSq9/7LOTcA98AqbZX3/ts59wY8AE/pY7o5fgDPymHx+73mMwAAAABJRU5ErkJggg==) repeat center center}
		#bdiag{background:url(data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAABAAAAAQCAYAAAAf8/9hAAABAklEQVQ4y9WTMUtDQRCEv0sgP0CwSiUIgnitrYGAIFhYWDiFFtr6A/wHVla26bdMJVhGlKQKCLmAnSBYBYRXWT6be3KeL4FgGqfcm9nZub1zZVnyfyCpKWk3rTWW7NEDhpKOq0JzCfcecB5Nj7z30xDCSyMfcY74FrhISi3g9EcESVvANM8o6Qa4zHreAycALpI2gQHQBgqga2ZjSdfAVSZ+AA7M7DNtMAD2EtIH0M/GBngC9itxGuEMeEuIazXiUepcwSVZN4DHGCPHOMYq8oPvSzSzV6ADvGec5zh2UbchV7Oy7Xih68AkOs/mvY9few8hzLz3d8AOcLhIvBK4v37nL1uKUZsLZ6+eAAAAAElFTkSuQmCC) repeat center center}
		#iupload{position:absolute;top:-1000px}
		#diag{position:absolute;color:#fff;top:40px;right:40px;overflow:auto;border-radius:4px;background-color:#333;z-index:90;padding:6px;-webkit-box-shadow:3px 3px 5px #000;-moz-box-shadow:3px 3px 5px #000;box-shadow:3px 3px 5px #000}
		#diag li{list-style-type:none;background-repeat:no-repeat;background-position:0 center;padding-left:20px}
		#diag li.ok{background-image:url(data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAABAAAAAQCAYAAAAf8/9hAAAA/klEQVQ4y9XTsUqDQRAE4O834AMINrESBLk8gK2Ch2ApKNgogq0P4BtYWQlWtta2FikiilYBQSSlIHiNIKSyjM0fOc9ECNo45d7M3M7uXTUYDPwfxBQaMYWlvDY1occZbmMKmxMbxBTOsIcGzmMKG98MYgqNMeIT7Gelaex8MYgpLOKxzBhTOMZB4XmJbahq0gI6mEMfq+1mrxtTOMJhIb7CervZe88NOljOSG+4KNqGG6wNxXmEXTxnxJkR4rv85iGqLOs8rusYJbp1rH558DnEdrP3hBW8FJz7uu3+qA1VI1bWqgc6i4f65tdx76Mas/cWTrH1k/hPUP32O38A6YhNlVyEa38AAAAASUVORK5CYII=)}
		#diag li.bad{background-image:url(data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAABAAAAAQCAYAAAAf8/9hAAAA8ElEQVQ4y62TQQrCMBBFX1s3Bc8wvUih4EIQXHkXwbUHEgSh0IVQ6EWSMwjZunAiaZpWQT8EwmT+n8lnBv4JI9IYkZsRKRNvpRFpjUgdxrOQDLRACdyBfWWt82SgA2rAAdvK2uEtYEQ2wE3JHndgr3dP9nDArrK2X2ngGJEBNtpREZHR3BPQ5xo4AH3CliZBRnMPsQelVmw+eN1r+24k8KXIiAyQJ5KKheqTtzyovk64HaMGunBOsoDcfiCHGHQWnO/gsuD2MNPJNfzCWYdjYpieWMQp5yWgY7kLRN5uV9Y+IpHRKKeWqZ1ZpnVqmX7GE05oWTqPdEKdAAAAAElFTkSuQmCC)}
		body{background:#fff url(data:image/jpg;base64,/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAAUEBAQEBAUEBAUIBQQFCAkHBQUHCQsJCQkJCQsOCwwMDAwLDgwMDQ4NDAwQEBEREBAXFxcXFxoaGhoaGhoaGhr/2wBDAQYGBgsKCxQODhQXEg8SFxoaGhoaGhoaGhoaGhoaGhoaGhoaGhoaGhoaGhoaGhoaGhoaGhoaGhoaGhoaGhoaGhr/wgARCACIAIQDAREAAhEBAxEB/8QAFwABAQEBAAAAAAAAAAAAAAAAAAECB//EABYBAQEBAAAAAAAAAAAAAAAAAAABA//aAAwDAQACEAMQAAAB6tjQAAFItSAAqRQAAAKQBCgAhQAABQQBagEAAKpIAAAAAhaQFUkBQQALQkAAAAAAABSIUCkBSFIUEKAAQoAUhSFBIFoAZigACkBSAAFBFqCBagBQSKKhQSAALUgC0iAFoCQAApCkWpAAVSQAAAFItSAAqkgAAAAWhIhQABVBBFqARakBSAAAAAAAAAAoBFqQLUgWpAVSQP/EABQQAQAAAAAAAAAAAAAAAAAAAID/2gAIAQEAAQUCEP8A/8QAFBEBAAAAAAAAAAAAAAAAAAAAgP/aAAgBAwEBPwEQ/wD/xAAUEQEAAAAAAAAAAAAAAAAAAACA/9oACAECAQE/ARD/AP/EABQQAQAAAAAAAAAAAAAAAAAAAID/2gAIAQEABj8CEP8A/8QAIBAAAAUEAwEAAAAAAAAAAAAAASAhMEAQETFRUOHw0f/aAAgBAQABPyGnsN+wx1A1UL1WJ2deCvDXkhiBBCCBUb0QGUf+G05//9oADAMBAAIAAwAAABBNvNVjPKd5dpebKv8AX2zycm+Fn6TATzf3+2n0Dcm3wHbbaSbTSVVemU99vskmkkEEA+AA7SWjUbS0vE2EtA6EBb/52Yb2AG2+mjGf0G+ye0Y2Xg73+27Ie728ImFuOV27bf8Att8n25ZhuNxtR9//xAAUEQEAAAAAAAAAAAAAAAAAAACA/9oACAEDAQE/EBD/AP/EABsRAAEEAwAAAAAAAAAAAAAAAAERMEBgECBQ/9oACAECAQE/ELMuFop1V8QRBDh6X//EACQQAAEEAwACAwEBAQEAAAAAAAEAESFBMVFhcYGRobHw4dHB/9oACAEBAAE/EN8ekR94jiv3+EMg+K4mZm5SDQWDRSbn11ERH51NPvXlBmxqkBA9V1EADH11MC/uW6mEnToWn20cRFt9cXXDNpFm+WlbvLzxMQY9F+IZD8vi18JQwNxaBB189UR/3qBmni0G48Wo40X1ZBEfPUWnU2jeLl1Dk598RGMB2via2rcYQJxU1xS2Prifn1xS8NNCbDjVIVxpZQXz5UkDNv8AKDuCxrSBwl4pT3w3VOjct1F2PHoIktd1xDJiRmOJzERFKcTjS/8ACb4opuF+LWKeeIFmxV8Tm2q0CwAeItQNfe0wh2u0MjDRvayHirO1dP72iAAZhjZ2jpxpKrIt5OlD5E94g8GIaHTy9NtS7zetKaf0BpZAB61pAGM1pSWzUsFrNQwTmc+I2p606UvdWNqetERtcn62i5Bd3mPabObmFPeYmFL39aQctmmgKeu2GCj/AJnSDfzphnXCoBH+qIHjaYRitqOfBQbjeCogR8dUB8fB2mGI+9qGOHD7RacXtQBTB/xNpvvSDMNOKKYYtmedoGHcU8naev8A07WBMXaJz7tEte7Tz/vE8j0tTMX1E/Hnq9/a/D1P/OiXhZT/ANKdnn7NJ+BvalrfUbRft62pnN6RhxLSiMnN60p7PjSBMZq181PtFx5/1ayqHqVMM/yvlvI2nPXqVLZo2nM5uIXvrQg2x/FRsX+o3i0WkxaLbF/iDbH8FY9IDGGLfq8s9fK+PK+F8KGeF8KOKBEKOP8A4g7Q7etqZM3+ovOb0mMu7yi+ZadKTv60g0erUxmo9poGUPdWiDG4cOtO/wAr5+eqB4HerG7vqN5l5cKdHygXHPHUSP2uqJ90mOR5pPzdcQY1quIZw+KWoltdRM7WohxXFLD1SFeA7jqz/wBbqecRMMuXNKjoO8L39cTFmB++omD7vqLgGZm0b92v4TxB3Hq+Jkbi1A8Nvqzu7UQeh54gBDcl0+DYt+pxhx5fqJ0Zm0bENLyp/b4n/n4g4DeWjqOC53SIhvMsmz7riIqvHFgt4ricw9Uj/R1f7SDxnIpB+1SmA5fx1bZ7huo+5eWUl/dKf4cUviGfHFHBmyjkyHlpKJgu1w5UPkS9nSJG/viDEiRkNPEEDDRva+Hb/wBX5NoNDtTIEceLRJaq+qATh5sotlxD2UTPzfF4aGeeKHZwzbKnt6Rd7uhpbzf4vn4GkSYEv44pe0iuIuAHcs2lQd/pDTflp0QcjSD4mmMKRRf1tGzN0NouxM3QREy8vQ0p78DSl3nGgt4tjKMDAt86T5w3vSBByAPnScO5Z3G9KHGHjaYu2mqkMNHhuoSafwhE1DxrKB8NFI9IfbdRZiYvao4eWgokOcXvSLRj70qaHsxV8l5RJn3fEbydzxCmOmnifE6cOh1ENKj8vqaAZfz1MZd9Z6vD8nZVicNakXFT1F2d92ngsd2i7n/vFqfvi68Ntf/Z) repeat;font:normal 16px "Satisfy";color:#aaa}
		button{margin:0;padding:0;border:none;background-color:transparent}
		input[type="text"],textarea,select{font:normal 16px "Satisfy";border:none;background-color:transparent;color:#666;padding:0}
		#copyright{bottom:22px;right:-2px;-webkit-transform:rotate(-90deg) translateY(10px) translateX(6px);-moz-transform:rotate(-90deg);transform:rotate(-90deg)}
		#osd{font:bold 30px Satisfy;text-shadow:0 4px 3px rgba(0, 0, 0, 0.4), 0 8px 10px rgba(0, 0, 0, 0.1), 0 8px 9px rgba(0, 0, 0, 0.1)}
		#thumbbar{height:34px;box-shadow:0px 5px 3px rgba(0, 0, 0, 0.2);background:rgba(0,0,0,0.1);z-index:20}
		#thumbbar h1{font:bold 50px "Satisfy";height:20px;color:#fff;margin:0 15px 0 5px;line-height:60px;text-shadow:0 1px 0 #ccc, 0 2px 0 #c9c9c9,0 3px 0 #bbb, 0 4px 0 #b9b9b9,0 5px 0 #aaa, 0 6px 1px rgba(0,0,0,0.1),0 0 5px rgba(0, 0, 0, 0.1), 0 1px 3px rgba(0,0,0,0.3),0 3px 5px rgba(0, 0, 0, 0.2), 0 5px 10px rgba(0,0,0,0.25),0 10px 10px rgba(0, 0, 0, 0.2), 0 20px 20px rgba(0,0,0,0.15)}
		#thumbbar button{font:normal 18px Satisfy;color:#888;padding:1px 10px 0 5px;margin:0 4px 2px 2px;background:-webkit-gradient(linear, left top, left bottom, from(#fffc9f), to(#e0e080));background:-moz-linear-gradient(top, #fffc9f, #e0e080);background:linear-gradient(top, #fffc9f, #e0e080);-moz-box-shadow:-1px 2px 1px rgba(0, 0, 0, 0.2), -2px 1px 1px rgba(0, 0, 0, 0.1);-webkit-box-shadow:-1px 2px 1px rgba(0, 0, 0, 0.2), -2px 1px 1px rgba(0, 0, 0, 0.1);box-shadow:-1px 2px 1px rgba(0, 0, 0, 0.2), -2px 1px 1px rgba(0, 0, 0, 0.1)}
		#thumbbar button:after{content:"";position:absolute;right:0;top:0;border-color:#fff #d5d4c6 #d0d080 #fff;border-style:solid;border-width:0 8px 8px 0;height:0;width:0;display:block;-moz-box-shadow:-1px 2px 1px rgba(0, 0, 0, 0.2), -2px 1px 1px rgba(0, 0, 0, 0.1);-webkit-box-shadow:-1px 2px 1px rgba(0, 0, 0, 0.2), -2px 1px 1px rgba(0, 0, 0, 0.1);box-shadow:-1px 2px 1px rgba(0, 0, 0, 0.2), -2px 1px 1px rgba(0, 0, 0, 0.1)}
		#thumbbar button:hover:after{border-width:0 16px 16px 0;-webkit-transition:border-width 1s ease-out}
		#thumbbar button:active{margin:2px 6px 0 0;-moz-box-shadow:none;-webkit-box-shadow:none;box-shadow:none}
		#thumbbar button#bzip{}
		#bzip{margin-left:10px}
		#bzip.empty,#bzip.all{display:none}
		#thumbbar #path button{background:-webkit-gradient(linear, left top, left bottom, from(#d3f2d4), to(#b8c7b9));background:-moz-linear-gradient(top, #d3f2d4, #b8c7b9)}
		#thumbbar #path button:after{border-color:#fff #d5d4c6 #c3e2c4 #fff}
		#diapos{padding:10px 5px 0 5px}
		li.diapo{height:172px;margin:8px;background-color:#fff;padding:10px 10px 2px 10px;-webkit-box-shadow:5px 5px 5px 1px rgba(0,0,0,.5);-moz-box-shadow:5px 5px 5px 1px rgba(0,0,0,.5);box-shadow:5px 5px 5px 1px rgba(0,0,0,.5)}
		li.diapo.loaded{color:#666}
		li.diapo.loaded:nth-child(even){-webkit-transform:rotate(-2deg);-moz-transform:rotate(-2deg);transform:rotate(-2deg)}
		li.diapo.loaded:nth-child(5n){-webkit-transform:rotate(4deg);-moz-transform:rotate(4deg);transform:rotate(4deg)}
		li.diapo.loaded:nth-child(3n){-webkit-transform:rotate(1deg);-moz-transform:rotate(1deg);transform:rotate(1deg)}
		li.diapo span.minicom{top:16px;right:16px;font-size:10px;line-height:18px;font-weight:bold}
		li.diapo input[type="checkbox"]{display:none}
		li.diapo input[type="checkbox"] + label{width:16px;height:16px;position:absolute;top:16px;left:16px;width:16px;height:16px;background:transparent url(data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAABAAAAAQCAYAAAAf8/9hAAAABHNCSVQICAgIfAhkiAAAAAlwSFlzAAADZgAAA2YBNMGSBgAAABl0RVh0U29mdHdhcmUAd3d3Lmlua3NjYXBlLm9yZ5vuPBoAAAIBSURBVDiNlZK9axRRFMV/d0ZwxiSYaGEQG4VgNipYKLGKn/EDzGJpYWtjI8jmvSLEITa785r9AwQVC6sQWAURgoIWEiwUF0H8gFSKiCAoyMtmd66Fk2VjdhEPvOqec+69511RVbphbm4uiKJoEXhtrb3WlQQEvQpxHBeBE8BV51zhvw2AEpDlnNleJOm2QrlcHg+CYAm4B+wAjovImDHm3T8NqtXqtkajcQeYUtVDItIHPAVqYRhez7Js2Rjzs22Qpumkqk4B+4AxYDivPbPWHgVI0/Qxf/JYwzdgWVUfbVLVu7loVVXfisgTEXkThuHNdheRC6p6EdiTv5PAYRHpE+fc+SzL5oEVETljjFnqFRiAc66oqvPAdxGZEFUlTdNzwAKwCpy11j7vJq5UKqdF5D7wKwzDY6VSqd4O0Tk3qao1oNVsNkdnZmY+dYrTNN0KfAZaInLKGPMCOu7AGLMIVID+MAxH/u4eBEEMbAFqa+J1BjmGAVqt1od8qoFyubwdYHp6+gvwFdjfKVh3B/l3HfHeD8VxfEVVE6AfuC0iN1T1FjDhve9PkqTZbYJRYCWKorqqVoEG8BK4rKofgQKwOYqivRtWcM4NADuBIWA34Lz3I9bacaAIvAd25fQDGwwGBwe9qtaBBVUtWGttkiQ/AKy1D7z3B0Xkkog8FJFXa7rfY9XNLpAieW8AAAAASUVORK5CYII=) no-repeat center center}
		li.diapo input[type="checkbox"]:checked + label{background:transparent url(data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAABAAAAAQCAYAAAAf8/9hAAAABHNCSVQICAgIfAhkiAAAAAlwSFlzAAADZgAAA2YBNMGSBgAAABl0RVh0U29mdHdhcmUAd3d3Lmlua3NjYXBlLm9yZ5vuPBoAAAI5SURBVDiNlZJBSJNhGMd/z/dtc25MpjIzQU+BacVueRciL3oQukjgqXMQ8/tOJTsNd/EiHiU6iB5qRVCiZyHCCEsbiTR2N7dcY9u37X06lGZuEb3wXt73//897/N/H1FV2q1kMmkFg8EtYNd13QdtRYD1t4vOzs4pYBy4n06nR/4bACREzjQP/yaSdi2kUqkxy7LejI56lMtCPu83IjLqOM7nfwIWFxd7PM97DEzOzp5Qr8PqahfAC9u2Hxljco7jlM4ACwsLt1R1Erj2a18CGBxsMDNzAsDaWoR83v+7qvBVlS+quuFT1SdAv22jPT1NicWaxGJN4vHqmWF6+jvZbIBi0aZYtMjn/b2VivSKSNhnWdY9Y8xT21b/xESZgYFGSyaBgBKP1wA4PAxwcBBQEY5Apu3Nzc2D7e3td8bInWw2YA8NNaSry7RNPJfzk8lEVFW+WZY9Pjc398kCcF33Ncik50ltfT2ipVLr79ZqQiYT0WaTMnA7kUh8gHNz4DjOFpCq10UKBbsF0GgI9ToCPHcc5+3p+cVS/QDd3U0APE+oVH5OUzhsCIVURbh+3nARMOz3o6GQYWcnyPJyVJeWunVjI0ypZNHX1xBVRpPJpO/U4LsAGLFtlZWVqB4fWyLCkSq53d2Om3t7HRoKGQECwWBwGNj/4wXpdDoCXK5WhULBagDpSqV6xXXdMWDKGN0/F+6Nlhai0WgV+Ag8M0avuq7rzs/PnwC4rvuyUqnFReSuiLwSkfenvh+Qoukzv1fdlgAAAABJRU5ErkJggg==) no-repeat center center}
		li.diapo.loaded:hover{z-index:10;color:#999;-webkit-transform:scale(1.2) rotate(0);-moz-transform:scale(1.2) rotate(0);-webkit-box-shadow:8px 8px 6px 3px rgba(0,0,0,.9);-moz-box-shadow:8px 8px 6px 3px rgba(0,0,0,.9);box-shadow:8px 8px 6px 3px rgba(0,0,0,.5);-webkit-transition:all 0.2s linear;-moz-transition:all 0.2s linear;transition:all 0.2s linear}
		#view #comments{color:#666;background:#fff url(data:image/jpg;base64,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) repeat}
		#coms{background:#fff url(data:image/jpg;base64,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) repeat}
		#coms li{color:#aaa74a;background-color:#fffc9f;-webkit-box-shadow:1px 1px 2px 1px #666;-moz-box-shadow:1px 1px 2px 1px #666;box-shadow:1px 1px 2px 1px #666}
		#coms li content{color:#555}
		#coms li button.del{right:4px;top:3px;color:#aaa}
		#coms li:nth-child(odd){-webkit-transform:rotate(-2deg);-moz-transform:rotate(-2deg);color:#a080a0;background-color:#F2D3F1}
		#coms li:nth-child(5n){-webkit-transform:rotate(3deg);-moz-transform:rotate(3deg)}
		#coms li:nth-child(3n){-webkit-transform:rotate(1deg);-moz-transform:rotate(1deg);color:#80a080;background-color:#D3F2D4}
		#newcom{background-color:#fffc9f;-webkit-box-shadow:2px 2px 2px 1px #666;-moz-box-shadow:2px 2px 2px 1px #666;box-shadow:3px 3px 2px 1px #666}
		#who:focus, #what:focus{background-color:#eeeb8e}
		#newcom .unused{color:#999639}
		#bsend{font-size:32px;width:36px;color:#aaa74a;text-shadow:2px 2px 1px #000;text-align:right;line-height:20px;padding:0 2px 2px 0;margin-left:242px}
		#bsend:active{padding:2px 0 0 2px;text-shadow:none}
		#viewbar{background:#fff url(data:image/jpg;base64,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) repeat}
		#viewbar button{color:#444}
		#bcom span{font:bold 24px Satisfy}
		#mask #loading{color:#fff;font:bold 30px Satisfy;text-shadow:0 4px 3px rgba(0, 0, 0, 0.4), 0 8px 10px rgba(0, 0, 0, 0.1), 0 8px 9px rgba(0, 0, 0, 0.1)}
		#intro{background-color:rgba(200,150,200,0.4)}
		#intro.hide{-webkit-transition:all 1.4s linear;-moz-transition:all 1.4s linear;transition:all 1.4s linear}
		#intro > div{margin:100px auto 0 auto;width:300px;height:300px;vertical-align:middle;text-align:center;-moz-border-radius:150px;-webkit-border-radius:150px;border-radius:150px;border:3px solid #777;color:#fff;background-color:#c9c;font:normal 16px Satisfy;box-shadow:0 0 10px 10px rgba(255,255,255,0.5)}
		#intro h1{color:#fff}
		#godbar{top:10px}
	</style>
<?php if( preg_match('/android|ipad|mobile/mi',$_SERVER['HTTP_USER_AGENT']) ){ ?>
	<style>
		#thumb,#thumb.active,li.diapo.loaded,li.diapo.loaded img,li.diapo.loaded:hover{-webkit-transition:none;-moz-transition:none;transition:none}
		li.diapo.loaded:hover{-webkit-transform:none;-moz-transform:none;-webkit-box-shadow:none;-moz-box-shadow:none;box-shadow:none;text-shadow:none}
	</style>
<?php } ?>
	<title><?php print($TITLE);?></title>
</head>
<body>
<!--[if lte IE 8]>
<div class="warning">
	<p><strong>Warning:</strong>This site is not compatible with your old browser.</p>
	<p>try it with chrome or firefox for a shiny experience</p>
</div>
<![endif]-->
	<noscript><p><strong>Warning:</strong>You must enable Javascript to visit this site.</p></noscript>
	<div id="mask"><div id="loading"></div></div>
<?php if($withadm){ ?>
	<input type="file" id="iupload"  accept="image/*" multiple/>
	<div id="godbar">
	<?php if($godmode){ ?>
		<button id="blogout"></button>
		<button id="bupload"></button>
		<button id="bflush"></button>
		<button id="bdiag"></button>
		<button id="bdel"></button>
	<?php } else { ?>
		<button id="blogin"></button>
	<?php } ?>
	</div>
<?php } ?>
	<div id="thumb">
		<div id="thumbbar">
			<h1 onclick="walli.cd('')"><?php print($TITLE);?></h1>
			<div id="path"></div>
			<button id="bzip"></button>
		</div>
		<ul id="diapos"></ul>
	</div>
	<div id="view">
		<div id="viewbar">
			<button id="bnext"></button>
			<button id="bprev"></button>
			<button id="bthumb"></button>
			<button id="bplay"></button>
<?php if($withcom){ ?>
			<button id="bcom"><span id="comcount">0</span></button>
		</div>
		<div id="comments">
			<ul id="coms"></ul>
			<div id="newcom">
				<form>
				<div><input type="text" id="who"/></div>
				<div><textarea id="what"></textarea></div>
				</form>
				<div><button id="bsend">&#10149;</button></div>
			</div>
<?php } ?>
		</div>
		<div id="slide">
			<img id="img0"/>
			<img id="img1"/>
		</div>
	</div>
	<div id="osd"></div>
	<div id="progress">
		<div id="progressbar"></div>
		<div id="progresstext"></div>
	</div>
<?php if($intro){ ?>
	<div id="intro"><div><?php print($intro);?></div></div>
<?php } ?>
	<div id="copyright"><a href="https://github.com/nikopol/walli">WALLi <?php print(VERSION); ?></a></div>

	<!--[if IE]>
	<script>
		[].forEach||(Array.prototype.forEach=function(c){for(var b=0;b<this.length;++b)c(this[b],b,this)});[].filter||(Array.prototype.filter=function(c){for(var b=[],a=0;a<this.length;++a)c(this[a],a)&&b.push(this[a]);return b});[].indexOf||(Array.prototype.indexOf=function(c,b){for(var a=b||0;a<this.length;++a)if(this[a]==c)return a;return-1});
	</script>
	<![endif]-->

	<script>
		var __=function(a){return a instanceof Array?a:[a]},_=function(a,c){var b,e,f;if("object"==typeof a)b=a;else if(a.length)if("#"==a[0]&&!/[ \.\>\<]/.test(a))b=document.getElementById(a.substr(1));else{e=document.querySelectorAll(a);b=[];for(f=0;f<e.length;++f)b.push(e[f])}b&&void 0!=c&&__(b).forEach(function(a){a.innerHTML=c});return b},append=function(a,c){var b=_(a);b&&__(b).forEach(function(a){var b=document.createElement("div");for(b.innerHTML=c;b.childNodes.length;)a.appendChild(b.childNodes[0])});return b},css=function(a,c){var b=_(a),e,f;if(b){if(void 0==c)return b instanceof Array?b:b.className;if("object"==typeof c)for(e in c)a.style[e]=c[e];else/^([\+\-\*])(.+)$/.test(c)?(e=RegExp.$1,c=RegExp.$2,__(b).forEach(function(a){f=a.className.split(/\s+/).filter(function(a){return a});"-"!=e&&-1==f.indexOf(c)?f.push(c):"+"!=e&&(f=f.filter(function(a){return a!=c}));b.className=f.join(" ")})):__(b).forEach(function(a){a.className=c});return b}},ajax=function(a,c){"string"==typeof a&&(a={url:a,
		ok:c});var b=a.type||"GET",e=a.url||"",f=a.contenttype||"application/x-www-form-urlencoded",k=a.datatype||"application/json",d=new window.XMLHttpRequest,j,g,h;if(a.data){if("string"==typeof a.data)g=a.data;else if(/json/.test(f))g=JSON.stringify(a.data);else{g=[];for(h in a.data)g.push(encodeURIComponent(h)+"="+encodeURIComponent(a.data[h]));g=g.join("&")}/GET|DEL/i.test(b)&&(e+=/\?/.test(e)?"&"+g:"?"+g,g="")}a.error||(a.error=function(a,b){console.error(a,b)});a.ok||(a.ok=function(){});d.onreadystatechange=
		function(){if(4==d.readyState)if(j&&clearTimeout(j),/^2/.test(d.status)){g=d.responseText;if(/json/.test(k))try{g=JSON.parse(d.responseText)}catch(b){return a.error("json parse error: "+b.message,d)}a.ok(g,d)}else a.error(d.responseText,d)};d.open(b,e,!0);d.setRequestHeader("Content-Type",f);if(a.headers)for(h in a.headers)d.setRequestHeader(h,a.headers[h]);a.timeout&&(j=setTimeout(function(){d.onreadystatechange=function(){};d.abort();a.error&&a.error("timeout",d)},1E3*a.timeout));d.send(g);return d},
		position=function(a){return(a=_(a))?(a=a.getBoundingClientRect(),{left:a.left+window.pageXOffset,top:a.top+window.pageYOffset,width:a.width,height:a.height}):!1},ready=function(a){/complete|loaded|interactive/.test(document.readyState)?a():document.attachEvent?document.attachEvent("ondocumentready",a()):document.addEventListener("DOMContentLoaded",function(){a()},!1)};
	</script>
	<script>
		var hash=function(){var b,c,d=function(a){return a.replace(/%20/g," ").replace(/%23/,"#")},e=function(){var a=[],d;for(d in b)a.push(d+"="+b[d]);c=a.join("|");document.location.hash="#"+c.replace(/ /g,"%20").replace(/#/,"%23");return!0},h=function(){b={};c=d(document.location.hash.substr(1));c.length&&c.split("|").forEach(function(a){a=a.split("=");1<a.length&&(b[a.shift()]=a.join("="))})};h();return{del:function(a){return a in b?!1:e(delete b[a])},set:function(a,d){return e("object"==typeof a?b=a:b[a]=d)},get:function(a){return void 0==a?b:d(b[a]||"")},onchange:function(a){window.onhashchange=function(){d(document.location.hash.substr(1))!=c&&(h(),a&&a())}}}}(),hotkeys=function(){var b=!1,c={ESC:27,TAB:9,SPACE:32,RETURN:13,BACKSPACE:8,BS:8,SCROLL:145,CAPSLOCK:20,NUMLOCK:144,PAUSE:19,INSERT:45,DEL:46,HOME:36,END:35,PAGEUP:33,PAGEDOWN:34,LEFT:37,UP:38,RIGHT:39,DOWN:40,F1:112,F2:113,F3:114,F4:115,F5:116,F6:117,F7:118,F8:119,F9:120,F10:121,F11:122,F12:123},d={ALT:1,CONTROL:2,CTRL:2,SHIFT:4},
		e=[],h=function(a){a||(a=window.event);var b,c,f=String.fromCharCode(a.which||a.charCode).toUpperCase(),g=a.shiftKey*d.SHIFT|a.ctrlKey*d.CTRL|a.altKey*d.ALT;for(b in e)if(c=e[b],(a.which==c.key||f==c.key)&&g==c.mask)if(c.glob||!/INPUT|SELECT|TEXTAREA/.test(document.activeElement.tagName))return c.fn(a),a.stopPropagation(),a.preventDefault(),!1;return!0};return{clear:function(){document.onkeydown=null;b=!1;e=[];return this},add:function(a,j,k){var f=0,g=0;"string"==typeof a&&(a=[a]);a.forEach(function(a){"string"==
		typeof a&&a.toUpperCase().split("+").forEach(function(a){d[a]?f|=d[a]:g=c[a]?c[a]:a[0]});g?(e.push({key:g,fn:j,glob:k,mask:f||0}),b||(document.onkeydown=h,b=!0)):console.error("hotkey "+a+" unknown")});return this}}}(),browser=function(){var b={},c=navigator.userAgent;/MSIE\s([\d\.]+)/.test(c)&&(b.IE=parseFloat(RegExp.$1));c.replace(/\s\(.+\)/g,"").split(" ").forEach(function(c){/^(.+)\/(.+)$/.test(c)&&(b[RegExp.$1]=parseFloat(RegExp.$2))});return b}();
	</script>
	<script>
		var locales={en:{title:{bnext:"next",bprev:"previous",bcom:"comments",bthumb:"thumbnail",bplay:"slideshow",bupload:"upload images",bflush:"reset cache",bdiag:"diagnostic",bdel:"delete selected images"},holder:{who:"enter your name\u2026",what:"enter your comment\u2026"},text:{loading:"loading\u2026"},date:{now:"now",min:"%d minute%s ago",hour:"%d hour%s ago",yesterday:"yesterday",day:"%d day%s ago",week:"%d week%s ago",month:"%d month%s ago"},bdel:"&#10006;",nocom:"be the first to comment",emptywho:"what's your name ?",emptywhat:"say something\u2026",play:"&#9654; PLAY",stop:"&#9632; STOP",dlall:"download all",dlsel:"download selected",zip:"compressing\u2026",nozip:"nothing to download",updir:"",uploadfiles:"upload %nb image%s (%z bytes) ?",flushed:"%nb file%s flushed",uploaded:"%nb file%s uploaded",deleted:"%nb file%s deleted"},fr:{title:{bnext:"suivante",bprev:"pr\u00e9c\u00e8dente",bcom:"commentaires",bthumb:"miniatures",bplay:"diaporama",bupload:"ajoute des images",bflush:"vide le cache",bdiag:"diagnostique",
		bdel:"efface les images s\u00e9lectionn\u00e9es"},holder:{who:"entrez votre nom\u2026",what:"entrez votre commentaire\u2026"},text:{loading:"chargement\u2026"},date:{now:"a l'instant",min:"il y a %d minute%s",hour:"il y a %d heure%s",yesterday:"hier",day:"il y a %d jour%s",week:"il y a %d semaine%s",month:"il y a %d mois"},bdel:"&#10006;",nocom:"soyez le premier \u00e0 laisser un commentaire",emptywho:"de la part de ?",emptywhat:"dites quelque chose\u2026",play:"&#9654; LECTURE",stop:"&#9632; STOP",
		dlall:"tout t\u00e9l\u00e9charger",dlsel:"t\u00e9l\u00e9charger la s\u00e9lection",zip:"compression\u2026",nozip:"rien \u00e0 t\u00e9l\u00e9charger",updir:"",uploadfiles:"poster %nb image%s (%z octets) ?",flushed:"%nb fichier%s supprim\u00e9%s",uploaded:"%nb image%s ajout\u00e9e%s",deleted:"%nb image%s effac\u00e9e%s"}},loc,setlocale=function(g){var b,s;loc=locales[g]?locales[g]:locales.en;loc.reldate=function(d){var c=(new Date).getTime();d=(new Date(d)).getTime();c=(c-d)/1E3;d=function(d,e){e=Math.round(e);
		return d.replace("%d",e).replace("%s",1<e?"s":"")};return 60>c?loc.date.now:3600>c?d(loc.date.min,c/60):86400>c?d(loc.date.hour,c/3600):172800>c?loc.date.yesterday:604800>c?d(loc.date.day,c/86400):2592E3>c?d(loc.date.week,c/604800):d(loc.date.month,c/2592E3)};loc.size=function(d){return 2048>d?d+"b":1E6>d?Math.round(d/1024)+"kb":1E9>d?Math.round(d/1E6)+"M":Math.round(d/1E9)+"G"};loc.tpl=function(d,c){var b=loc[d],e;for(e in c)b=b.replace(RegExp("%"+e,"g"),c[e]),"nb"==e&&(b=b.replace(RegExp("%s","g"),
		1<c.nb?"s":""));return b};if(loc.title)for(b in loc.title)(s=_("#"+b))&&s.setAttribute("title",loc.title[b]);if(loc.holder)for(b in loc.holder)(s=_("#"+b))&&s.setAttribute("placeholder",loc.holder[b]);if(loc.text)for(b in loc.text)_("#"+b,loc.text[b])};ready(function(){setlocale(navigator.language)});
		var log=function(){var g,b,s=(new Date).getTime(),d={debug:1,info:2,warn:3,error:4},c=hash.get("log"),n=c&&d[c]?d[c]:0;if(!n)return{debug:function(){},info:function(){},warn:function(){},error:function(){}};console.log?g=function(b,c){if(d[b]>=n){var x,g=("     "+b).substr(-5)+"|";for(x in c)console.log(g+c[x])}}:(ready(function(){append(document.body,'<div id="log"></div>');b=_("#log")}),g=function(c,g){if(b&&d[c]>=n){var x,t=("000000"+((new Date).getTime()-s)).substr(-6);for(x in g)append(b,'<div class="'+
		c+'"><span class="timer">'+t+"</span>"+g[x].replace(" ","&nbsp;")+"</div>");b.scrollTop=b.scrollHeight}});return{debug:function(){g("debug",arguments)},info:function(){g("info",arguments)},warn:function(){g("warn",arguments)},error:function(){g("error",arguments)}}}(),osd;
		osd=function(){var g,b,s,d,c,n,e=!1;ready(function(){g=_("#osd");b=_("#progress")});return{hide:function(){e=!1;css(g,"-active")},show:function(){css(g,"+active")},error:function(d){log.error(d);osd.info(d,"error",3E3)},info:function(d,b,c){_(g,d).className=b||"";osd.show();e&&clearTimeout(e);e=setTimeout(osd.hide,c||1500)},loc:function(d,b){osd.info(loc.tpl(d,b))},start:function(c,e,g){s=g||"%v/%m";d=c;n=e;d&&(css(b,"+active"),osd.set(0))},set:function(e,g){g&&g!=d&&(d=g,css(b,"+active"));c=e;_("#progresstext",
		s.replace(/%v/,e).replace(/%m/,d));e>=d&&(css(b,"-active"),n&&n());_("#progressbar").style.width=d?Math.floor(position("#progress").width*e/d)+"px":0},inc:function(){osd.set(++c)}}}();var walli;
		walli=function(){function g(){var a=position("#thumbbar");_("#diapos").style.top=a.top+a.height+"px"}function b(a,f){B&&(_("#bzip",f).className=a,g())}function s(a,f){var d=k[a],b,q=Date.now();b=new Image;K++;b.onload=function(b){b||(b=window.event);log.info("image #"+a+" "+d+" loaded in "+(Date.now()-q)/1E3+"s");f(a,b.target||b.srcElement);K--};b.onerror=function(){log.error("error loading "+("image #"+a+" "+d));f(a,null);K--};b.src=encodeURIComponent(d)}function d(a){a=/([^\/]+)\/$/.test(a)?RegExp.$1:
		a.replace(/^.*\//g,"");return a.replace(/\.[^\.]*$/,"").replace(/[\._\|]/g," ")}function c(){L&&!y&&(y=setInterval(function(){log.debug("refresh required");ajax("?!=count&path="+r,function(a){(M.length!=a.dirs||k.length!=a.files)&&n(r)})},1E3*L))}function n(a,f){y&&(clearInterval(y),y=!1);log.debug("loading path "+(a||"/"));var S=_("#diapos",""),e=_("#path","");b("hide",loc.dlall);v&&(_("#bdel").className="hide");u=[];ajax("?!=ls&path="+a,function(a){r=a.path;log.info((r||"/")+"loaded with "+a.dirs.length+
		" subdirs and "+a.files.length+" files found");if(r.length){var h=r.replace(/[^\/]+\/$/,"/"),j=document.createElement("li");css(j,"diapo up loaded");j.setAttribute("title",loc.updir);j.onclick=function(){n(h)};S.appendChild(j);var l="";r.split("/").forEach(function(a){a&&(l+=a+"/",append(e,"<button onclick=\"walli.cd('"+l+"')\">"+a+"</button>"))});g()}var m=function(a,f,b,q){var c=document.createElement("img");c.onload=function(){osd.inc();log.debug(a+" loaded");css(this.parentNode,"+loaded")};c.onclick=
		f;f=document.createElement("li");css(f,"diapo "+b);f.appendChild(c);f.setAttribute("title",d(a));void 0!=q&&(c.id="diapo"+q,(B||v)&&append(f,'<input type="checkbox" id="chk'+q+'" n="'+q+'" onchange="walli.zwap('+q+')"/><label for="chk'+q+'"></label>'));if((z[a]||[]).length)append(f,'<span class="minicom">'+(999<z[a].length?Math.floor(z[a].length/1E3)+"K+":z[a].length)+"</span>");S.appendChild(f);c.src="?!=mini&file="+encodeURIComponent(a)};k=a.files;M=a.dirs;z=a.coms;f&&f();osd.start(k.length+M.length);
		a.dirs.forEach(function(a){m(a,function(){n(a)},"dir")});a.files.forEach(function(a,f){m(a,function(){walli.show(f,0)},"",f)});a.files.length&&B&&b("all");N();c();v&&_("#diag")&&walli.diag()})}function e(a,f){if(O[a]){var b=p.clientWidth,d=p.clientHeight,q=O[a],c=q.h,e=q.w;e>b&&(e=b,c=Math.floor(e*(q.h/q.w)));c>d&&(c=d,e=Math.floor(c*(q.w/q.h)));css(j[a],{width:e+"px",height:c+"px",left:Math.floor((b-e)/2+b*f)+"px",top:Math.floor((d-c)/2)+"px"})}}function R(){I&&(C&&clearTimeout(C),C=setTimeout(walli.next,
		1E3*W))}function x(a){I!==a&&((I=a)?(R(),css("#bplay","active"),osd.loc("play")):(C&&clearTimeout(C),C=!1,css("#bplay",""),osd.loc("stop")))}function t(a){F!==a&&(F=a,log.debug("switch to "+a+" mode"),l=!0,"tof"==F?(y&&(clearInterval(y),y=!1),css(D,"+active"),css("#thumb","-active")):"zik"!=F&&"movie"!=F&&(l=!1,x(!1),css(j[0],""),css(j[1],""),css(D,"-active"),css("#thumb","+active"),c()),N())}function T(a){var f=_("#diapo"+m).parentNode,b=_("#minicom"+m);b&&f.removeChild(b);0<a&&append(f,'<span id="minicom'+
		m+'" class="minicom">'+a+"</span>")}function P(a,f){if(f||l&&J!==a)J=a,l&&(css(j[1-h],""),e(1-h,2)),a?(css("#bcom","+active"),css(D,"+com"+(f?"fix":"")),hash.set("com",1)):(css("#bcom","-active"),css(D,"-com"),css(D,"-comfix"),hash.del("com")),l&&setTimeout(function(){e(h,0)},550)}function Q(a){var f="",b=z[a];b&&b.length?(b.forEach(function(b){f+="<li><header>"+b.who+' <span title="'+b.when.replace("T"," ")+'">'+loc.reldate(b.when)+"</span></header><content>"+b.what.replace("\n","<br/>")+"</content>"+
		(b.own?'<button class="del" onclick="walli.rmcom(\''+a.replace("'","\\'")+"',"+b.id+')">'+loc.bdel+"</button>":"")+"</li>"}),_(G,f),G.scrollTop=G.scrollHeight,_("#comcount",999<b.length?Math.floor(b.length/1E3)+"K+":b.length)):(_(G,loc.nocom),_("#comcount","0"))}function A(a){a&&a.stopPropagation&&a.stopPropagation()}function N(){l?hash.set("f",k[m]):r?hash.set("f",r):hash.del("f")}function U(){var a=hash.get("f"),b=hash.get("com"),d=/^(.+\/)([^\/]*)$/.test(a)?RegExp.$1:"/",c=RegExp.$2;b?P(!0,!0):
		J&&P(!1,!0);return a.length?(b=function(){var b=k.indexOf(a),f=0;l&&b!=m&&(f=b<m?-1:1);-1!=b?walli.show(b,f):t("thumb")},d!=r?n(d,b):c?b():t("thumb"),!0):!1}var W=5,C=!1,y=!1,L,r,k=[],M=[],u=[],m=!1,h=0,j=[],O=[],D,H,E,K=0,I=!1,l,p,w={},z=[],J,F,G,V,v=!1,B=!0;return{setup:function(a){V=a.comments;L=a.refresh;v=a.god;B=a.zip;D=_("#view");G=_("#coms");p=_("#slide");p.onmousewheel=function(a){l&&(0>(a.wheelDelta||a.detail/3)?walli.prev():walli.next(),a.preventDefault())};var b=function(){j[h].className=
		"animated";j[h].style.left=w.l+"px";w={}};p.onmousedown=p.ontouchstart=function(a){a.preventDefault();a.touches&&(a=a.touches[0]);j[h].className="touch";w={d:!0,x:a.pageX,l:parseInt(j[h].style.left,10),h:setTimeout(b,1E3)}};p.onmousemove=p.ontouchmove=function(a){w.d&&(a.preventDefault(),a.touches&&(a=a.touches[0]),a=a.pageX-w.x,j[h].style.left=w.l+a+"px",80<Math.abs(a)&&(clearTimeout(w.h),w={},j[h].className="animated",80<a?walli.prev():walli.next()))};p.onmouseup=p.onmouseout=p.ontouchend=p.ontouchcancel=
		function(a){a.preventDefault();w.d&&(clearTimeout(w.h),b())};j=[_("#img0"),_("#img1")];p.ondragstart=j[0].ondragstart=j[1].ondragstart=function(a){a.preventDefault();return!1};window.onresize=function(){l&&(j[1-h].className="",e(1-h,1),e(h,0));g()};window.onorientationchange=function(){var a=_("#viewport"),b=window.orientation||0;a.setAttribute("content",90==b||-90==b||270==b?"height=device-width,width=device-height,initial-scale=1.0,maximum-scale=1.0":"height=device-height,width=device-width,initial-scale=1.0,maximum-scale=1.0");
		l&&e(h,0)};hotkeys.add("CTRL+D",function(){css("#log","*active")},!0).add("SPACE",walli.toggleplay).add("C",walli.togglecom).add("HOME",walli.first).add("LEFT",walli.prev).add("RIGHT",walli.next).add("END",walli.last).add(["ESC","UP"],walli.back).add("DOWN",function(){!l&&k.length&&walli.show(0)});_("#bprev").onclick=walli.prev;_("#bnext").onclick=walli.next;_("#bplay").onclick=walli.toggleplay;_("#bthumb").onclick=walli.thumb;_("#bzip").onclick=walli.dlzip;B||(_("#bzip").className="hide");V&&(H=
		_("#who"),E=_("#what"),E.onfocus=H.onfocus=function(){x(!1)},_("#comments").onclick=A,_("#bcom").onclick=walli.togglecom,_("#bsend").onclick=walli.sendcom);v?(_("#blogout").onclick=walli.logout,FormData?(_("#iupload").onchange=function(){for(var a=new FormData,b=0,d=this.files,f,c=0;c<d.length;++c)f=d[c],b+=f.size,a.append("file"+c,f);if(confirm(loc.tpl("uploadfiles",{z:loc.size(b),nb:d.length}))){var e=new XMLHttpRequest;e.open("POST","walli.php?!=img&&path="+r);e.onload=function(){if(200==e.status){var a=
		JSON.parse(e.responseText);osd.loc("uploaded",{nb:a.added});a.added&&n(r)}else osd.error("error "+e.status)};e.upload.onprogress=function(a){event.lengthComputable&&osd.set(a.loaded,a.total)};e.send(a)}},_("#bupload").onclick=function(){_("#iupload").click()}):css("#bupload",{diplay:"none"}),_("#bdel").onclick=walli.del,_("#bdiag").onclick=walli.switchdiag,_("#bflush").onclick=walli.flush):a.admin&&(_("#blogin").onclick=walli.login);log.info("show on!");var d=_("#intro");if(d){var c=setTimeout(function(){css(d,
		"hide")},5E3);d.onclick=function(){clearTimeout(c);css(d,"hide")}}U()||(t("thumb"),n("/"));hash.onchange(U)},login:function(){document.location="?login"+document.location.hash},logout:function(){document.location="?logout"+document.location.hash},del:function(){if(v){var a=k.filter(function(a,b){return-1!=u.indexOf(b)});u.length?ajax({type:"POST",url:"?!=del",data:{files:a.join("*")},ok:function(a){osd.loc("deleted",{nb:a.deleted});a.deleted&&n(r)},error:osd.error}):osd.error(loc.noselection)}},switchdiag:function(){var a=
		_("#diag");a?document.body.removeChild(a):walli.diag()},diag:function(){v&&ajax({url:"?!=diag",data:{path:r},ok:function(a){var b=_("#diag"),d="<ul>",c;for(c in a.stats)d+='<li class="stat">'+("size"==c?loc.size(a.stats[c]):a.stats[c]+" "+c)+"</li>";for(c in a.checks)d+='<li class="'+(a.checks[c]?"ok":"bad")+'">'+c+(a.checks[c]?" enabled":" disabled")+"</li>";b||(b=document.createElement("div"),b.id="diag",b.onclick=function(){document.body.removeChild(b)},document.body.appendChild(b));b.innerHTML=
		d},error:osd.error})},flush:function(){v&&ajax({url:"?!=flush",ok:function(a){osd.loc("flushed",{nb:a.flushed})},error:osd.error})},dlzip:function(){var a=u.length?k.filter(function(a,b){return-1!=u.indexOf(b)}):k;B&&a.length?(_("#bzip",loc.zip),ajax({type:"POST",url:"?!=zip",data:{files:a.join("*")},ok:function(a){document.location="?!=zip&zip="+a.zip;walli.zwap()},error:osd.error})):osd.loc("nozip")},zwap:function(a){void 0!=a&&(-1==u.indexOf(a)?u.push(a):u=u.filter(function(b){return b!=a}));u.length?
		(b("selected",loc.tpl("dlsel",{nb:u.length})),v&&(_("#bdel").className="")):(b("all",loc.dlall),v&&(_("#bdel").className="hide"))},thumb:function(){t("thumb")},show:function(a,b){k.length&&(m=0>a?k.length+a:a>=k.length?a%k.length:a,l||t("tof"),css("#mask","+active"),s(m,function(a,c){css("#mask","-active");l?h=1-h:b=0;O[h]={w:c.width,h:c.height};p.removeChild(j[h]);j[h].src=c.src;if(b)css(j[h],""),e(h,b),p.appendChild(j[h]),css(j[h],"animated"),e(h,0),e(1-h,-b);else{css(j[h],"");var g=position("#diapo"+
		m);css(j[h],{width:g.width+"px",height:g.height+"px",left:g.left+"px",top:g.top+"px"});p.appendChild(j[h]);css(j[h],"animated");e(h,0)}R();N();setTimeout(function(){osd.info(d(k[m])+" ("+(m+1)+"/"+k.length+")")},1E3);1<k.length&&s((m+1)%k.length,function(){})}),Q(k[m]))},next:function(a){A(a);l&&walli.show(++m,1)},prev:function(a){A(a);l&&walli.show(--m,-1)},first:function(a){A(a);l&&walli.show(0,-1)},last:function(a){A(a);l&&walli.show(-1,1)},play:function(a){A(a);k.length&&(l||walli.show(m,0),t("tof"))},
		stop:function(a){A(a);t("thumb")},toggleplay:function(a){A(a);x(!I)},togglecom:function(a){a&&a.stopPropagation();P(!J)},sendcom:function(){1>H.value.length?(osd.loc("emptywho"),H.focus()):1>E.value.length?(osd.loc("emptywhat"),E.focus()):ajax({type:"POST",url:"?!=comment",data:{file:k[m],who:H.value,what:E.value},ok:function(a){z[a.file]=a.coms;Q(k[m]);T(a.coms.length);E.value=""},error:osd.error})},rmcom:function(a,b){ajax({type:"POST",url:"?!=uncomment",data:{file:a,id:b},ok:function(a){z[a.file]=
		a.coms;Q(k[m]);T(a.coms.length)},error:osd.error})},back:function(){if(l)return t("thumb");var a=r.split("/");1<a.length&&n(a.slice(0,a.length-2).join("/"))},cd:function(a){t("thumb");n(a)}}}();
	</script>
	<script>
		ready(function(){
			walli.setup({
				refresh: <?php print($REFRESH_DELAY) ?>,
				comments: <?php print($withcom?'true':'false')?>,
				admin: <?php print($withadm?'true':'false')?>,
				zip: <?php print($withzip?'true':'false')?>,
				god: <?php print($godmode?'true':'false')?>
			});
		});
	</script>

<?php if(!empty($GA_KEY)) { ?>
	<script type="text/javascript">
		var _gaq=_gaq||[];_gaq.push(['_setAccount', '<?php print($GA_KEY); ?>']);_gaq.push(['_trackPageview']);
		(function(){var ga=document.createElement('script');ga.type='text/javascript';ga.async=true;ga.src=('https:'==document.location.protocol?'https://ssl':'http://www')+'.google-analytics.com/ga.js';var s=document.getElementsByTagName('script')[0];s.parentNode.insertBefore(ga,s);})();
	</script>
<?php } ?>

</body>
</html>