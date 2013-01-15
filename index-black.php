<?php
/*
WALLi v0.1 (c) NiKo 2012-2013
quick image wall
https://github.com/nikopol/walli

just put this single file into an http served directory 
containing [sub-dir of] media files.
*/

/*PARAMETERS*/

//walli cache dir
//(used to store generated icon files and comments)
//set to false to disable cache & comments
//otherwise set it writable for your http user (usually www-data or http)
$SYS_DIR = '.cache/';

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

//you can setup all previous parameters in an external file
//ignored if the file is not found
@include('config.inc.php');

/*CONSTANTS*/

define('MINI_SIZE',150);
define('COOKIE_NAME','walluid');
define('VERSION','0.1');
define('FILEMATCH','\.(png|jpe?g|gif)$');

/*TOOLS*/

if(!function_exists('imagecopyresampled')) die("GD extension is required");

$uid = empty($_COOKIE[COOKIE_NAME]) ? '' : $_COOKIE[COOKIE_NAME];
if(empty($uid)){
	$uid=sha1($_SERVER['REMOTE_ADDR'].'-'.time());
	setcookie(COOKIE_NAME,$uid);
}

$intro = false;
if($INTRO_FILE && file_exists($ROOT_DIR.$INTRO_FILE))
	$intro = @file_get_contents($ROOT_DIR.$INTRO_FILE);

function clean_rel_path($f){
	$f=preg_replace('/^\/+/','',$f);    //avoid root dir
	$f=preg_replace('/\.\.+\//','',$f); //avoid parent dirs
	return $f;	
}

function get_file_path($f){
	global $ROOT_DIR;
	return $ROOT_DIR.clean_rel_path($f);
}

function get_sys_file($f){
	global $SYS_DIR;
	$f=preg_replace('/[\?\*\/\\\!\>\<]/','_',$f);
	return $SYS_DIR ? $SYS_DIR.$f : '';
}

function send_file($f){
	ob_clean();
	flush();
	header('Content-Description: File Transfer');
	header('Content-Type: application/octet-stream');
	header('Content-Disposition: attachment; filename='.basename($f));
	header('Content-Transfer-Encoding: binary');
	header('Expires: 0');
	header('Cache-Control: must-revalidate');
	header('Pragma: public');
	header('Content-Length: '.filesize($f));
	readfile($f);
}

function send_json($o){
	$out=gettype($o)=='string'?$o:json_encode($o);
	header('Content-Type:application/json; charset=utf-8');
	header('Content-Length: '.strlen($out));
	print($out);
}

function error($e){
	send_json(array('error'=>$e));
}

function ls($path='',$pattern='',$recurse=0){
	global $ROOT_DIR;
	$files=array();
	$subs=array();
	$path=clean_rel_path($path);
	if($path && !preg_match('/\/$/',$path)) $path.='/';
	foreach (new DirectoryIterator($ROOT_DIR.$path) as $file) {
		$fn=$file->getFilename();
		if($fn[0]!='.') {
			if($file->isDir())
				$subs[]=$path.$fn.'/';
			else  if(!$pattern || preg_match('/'.$pattern.'/i',$fn))
				$files[]=$path.$fn;
		}
	}
	$dirs=array();
	foreach($subs as $d){
		$sub=ls($d,$pattern,1);
		if(count($sub['files'])){
			if($recurse) $files=array_merge($files,$sub['files']);
			$dirs[]=$d;
		};
	}
	return array('path'=>$path,'files'=>$files,'dirs'=>$dirs);
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
	$comfile = get_sys_file($path).'.comments.json';
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

function cache($nbd){
	header('Cache-Control: public');
	header('Expires: '.gmdate('D, d M Y H:i:s', time()+(60*60*24*$nbd)).' GMT');
}

function nocache(){
	header("Cache-Control: no-cache, must-revalidate");
	header('Expires: 0');
}

function notfound($w){
	header("Status: 404 Not Found");
	print "$w not found";
	exit;
}

/*CLIENT API*/

function GET_info(){
	phpinfo();
}

function GET_flush(){
	global $SYS_DIR;
	$ls=ls($SYS_DIR,'\.(png|zip)$');
	$s=array('count'=>count($ls['files']),'flushed'=>0);
	foreach($ls['files'] as $f)
		if(@unlink($f)) $s['flushed']++;
	$s['files']=$ls;
	nocache();
	send_json($s);
}

function GET_ls(){
	global $REFRESH_DELAY;
	$path=preg_replace('/\/$/','',$_GET['path']);
	$data=ls($path,FILEMATCH);
	sort($data['files']);
	sort($data['dirs']);
	$data['coms']=load_coms($path);
		$data['refresh']=$REFRESH_DELAY;
	nocache();
	send_json($data);
}

function GET_count(){
	$path=preg_replace('/\/$/','',$_GET['path']);
	$data=ls($path,FILEMATCH);
	send_json(array('files'=>count($data['files']),'dirs'=>count($data['dirs'])));
}

function GET_img(){
	$file = get_file_path($_GET['file']);
	if(!file_exists($file)) notfound($file);
	header('Content-Type: image/'.pathinfo($file,PATHINFO_EXTENSION));
	header('Content-Length: '.filesize($file));
	cache(60);
	@readfile($file);
}

function GET_mini(){
	$file = get_file_path($_GET['file']);
	if(!file_exists($file)) notfound($file);
	$cachefile = get_sys_file($_GET['file']).'.mini.png';
	header('Content-Type: image/png');
	cache(60);
	if(empty($_GET['force']) && $cachefile && file_exists($cachefile)){
		@readfile($cachefile);
		exit;
	}
	if(is_dir($file)){
		$mini = imagecreatetruecolor(MINI_SIZE,MINI_SIZE);
		$bgc = imagecolorallocate($mini,255,255,255);
		imagefill($mini,0,0,$bgc);
		$list = ls($_GET['file'],FILEMATCH,1);
		$size = floor((MINI_SIZE-2)/3);
		$n = 0;
		shuffle($list['files']);
		foreach($list['files'] as $f){
			$img = iconify(get_file_path($f),$size-2);
			$x = ($n % 3) * $size;
			$y = floor($n / 3) * $size;
			imagecopyresampled($mini, $img, $x+2, $y+2, 0, 0, $size-2, $size-2, $size-2, $size-2);
			imagedestroy($img);
			$n++;
			if($n>8) break;
		}
	} else
		$mini=iconify($file,MINI_SIZE);
	if($cachefile){
		if(!file_exists(dirname($cachefile))) @mkdir(dirname($cachefile));
		if(file_exists(dirname($cachefile)))  @imagepng($mini,$cachefile);
	}
	imagepng($mini);
	imagedestroy($mini);
}

function POST_comment(){
	global $uid, $TIMEZONE;
	$file=$_POST['file'];
	$comfile=get_sys_file(dirname($file)).'.comments.json';
	$what=$_POST['what'];
	if(empty($what)) return error('empty comment');
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
	if(!file_exists(dirname($comfile))) @mkdir(dirname($comfile));
	if(!file_exists(dirname($comfile))) return error('cannot make comment dir');
	@file_put_contents($comfile,json_encode($coms));
	if(!file_exists($comfile)) return error('cannot write comment file');
	send_json(array(
		'file'=>$file,
		'coms'=>load_coms(dirname($file),$file),
	));
}

function POST_uncomment(){
	global $uid;
	$file=$_POST['file'];
	$comfile=get_sys_file(dirname($file)).'.comments.json';
	$id=$_POST['id'];
	$coms=file_exists($comfile)?json_decode(file_get_contents($comfile),true):array();
	if(empty($coms) || !array_key_exists($file,$coms)) return error('comment not found');
	$c;
	$l=array();
	foreach($coms[$file] as $com){
		if($com['id']==$id) $c=$com;
		else $l[]=$com;
	}
	if(empty($c)) return error('comment not found');
	if($c['uid']!=$uid) return error('forbidden');
	$coms[$file]=$l;
	@file_put_contents($comfile,json_encode($coms));
	send_json(array(
		'file'=>$file,
		'coms'=>load_coms(dirname($file),$file),
	));
}

function POST_zip(){
	$lst = explode('*',$_POST['files']);
	$fn  = 'pack-'.time().'.zip';
	$zip = new ZipArchive;
	$r = $zip->open(get_sys_file($fn),ZIPARCHIVE::CREATE);
	if($r!==true) return error("error#$r opening zip");
	$nb = 0;
	foreach($lst as $f){
		$f = get_file_path($f);
		if(file_exists($f)){
			$zip->addFile($f,basename($f));
			$nb++;
		}
	}
    $zip->close();
	if(!$nb) return error('empty zip');
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

/*MAIN*/

if(!empty($_REQUEST['!'])){
	$do = $_SERVER['REQUEST_METHOD'].'_'.$_REQUEST['!'];
	if(!function_exists($do)) die("$do is not a function"); 
	call_user_func($do);
	exit;
}

$withcom = file_exists($SYS_DIR);
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
	<!--<meta id="viewport" name="viewport" content="height=device-height,width=device-width,initial-scale=1.0,maximum-scale=1.0"/>-->
	<meta name="apple-mobile-web-app-capable" content="yes"/>
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<link rel="shorcut icon" type="image/png" href="data:image/png;base64,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" />
	<style>
		@import url(http://fonts.googleapis.com/css?family=Baumans);*{margin:0;padding:0;}
		body{background:#000;overflow:hidden;font:normal 16px "Baumans";color:#ccc;}
		a,a:visited{text-decoration:none;}
		input[type="text"],textarea,select{font:normal 16px "Baumans";border:none;background-color:#222;width:380px;color:#fff;padding:0;-webkit-border-radius:3px;-moz-border-radius:3px;border-radius:3px;}
		button{margin:0;padding:0;border:none;background-color:transparent;}
		ul{list-style:none;}
		::-webkit-scrollbar{background:transparent;width:6px;height:6px;border:none;}
		::-webkit-scrollbar:hover{background:#222;}
		::-webkit-scrollbar:vertical{margin-left:5px;}
		::-webkit-scrollbar-thumb{background:#999;border:none;}
		::-webkit-scrollbar-button{display:none;}
		#copyright{position:absolute;font:normal 10px Arial,Helvetica;z-index:99;color:#000;bottom:22px;right:-11px;-webkit-transform:rotate(-90deg);-moz-transform:rotate(-90deg);}
		#copyright a,#copyright a:visited{color:#aaa;}
		#osd{display:hidden;position:absolute;top:10px;right:5px;height:40px;z-index:0;color:#fff;font:bold 30px Baumans;text-shadow:0 4px 3px rgba(0, 0, 0, 0.4), 0 8px 10px rgba(0, 0, 0, 0.1), 0 8px 9px rgba(0, 0, 0, 0.1);opacity:0;text-align:right;padding:0 10px;-webkit-transition:all 0.5s ease;-moz-transition:all 0.5s ease;transition:all 0.5s ease;}
		#osd.active{display:block;position:absolute;z-index:50;opacity:1;-webkit-transition:opacity 0.2s ease-in-out;-moz-transition:opacity 0.2s ease-in-out;transition:opacity 0.2s ease-in-out;}
		#thumb{position:absolute;top:0;bottom:0;left:0;right:0;opacity:0;z-index:0;overflow:auto;padding:5px 10px 10px 10px;-webkit-transition:all 0.5s linear;-moz-transition:all 0.5s linear;transition:all 0.5s linear;}
		#thumb.active{opacity:1;z-index:1;-webkit-transition:all 0.5s linear;-moz-transition:all 0.5s linear;transition:all 0.5s linear;}
		#thumbbar{position:absolute;left:0;right:0;top:0;height:30px;padding:5px;background:rgba(0,0,0,0.1);z-index:20;font-size:0;}
		#thumbbar h1{font:bold 24px "Baumans";color:#EEE;display:inline;margin:0 4px 0 0;background:#333;padding:0 12px;cursor:default;}
		#thumbbar h1:hover{background:#666;text-shadow:0 0 10px #fff;}
		#thumbbar button{position:relative;display:inline;height:28px;font:normal 18px Baumans;color:#ccc;padding:0 30px 0 14px;margin:0 -9px 0 0;background:#444;vertical-align:top;}
		#thumbbar button:before{content:"";position:absolute;display:block;left:-14px;top:0;height:0;width:0;border-color:#444 #444 #444 transparent;border-style:solid;border-width:14px;}
		#thumbbar button:after{content:"";position:absolute;display:block;right:0;top:0;height:0;width:0;border-color:#000 #000 #000 #444;border-style:solid;border-width:14px;}
		#thumbbar button:hover{background:#666;color:#eee;text-shadow:0 0 10px #fff;}
		#thumbbar button:hover:before{border-color:#666 #666 #666 transparent;}
		#thumbbar button:hover:after{border-color:#000 #000 #000 #666;}
		#thumbbar button:active{}
		#thumbbar button#bzip{background:#666;}
		#thumbbar button#bzip.empty{display:none;}
		#thumbbar button#bzip:hover{background:#888;color:#fff;}
		#thumbbar button#bzip:before{border-color:#666 #666 #666 transparent;}
		#thumbbar button#bzip:hover:before{border-color:#888 #888 #888 transparent;}
		#thumbbar button#bzip:after{border-color:#000 #000 #000 #666;}
		#thumbbar button#bzip:hover:after{border-color:#000 #000 #000 #888;}
		#thumbbar button#bzip.hide{display:none;}
		#thumbbar button#bzip.all{display:none;}
		#thumbbar button:first-child{padding-left:10px;}
		#thumbbar button:first-child:before{border-width:0;}
		#path{display:inline;}
		#thumbbar #path button{}
		#thumbbar #path button:after{}
		#diapos{position:absolute;top:38px;padding:15px 5px 0 15px;left:0;right:0;bottom:0;overflow:auto;}
		li.diapo{position:relative;display:inline;float:left;width:150px;height:168px;overflow:hidden;text-align:center;vertical-align:bottom;margin:5px;padding:0;background-color:#000;}
		li.diapo img{display:block;}
		li.diapo:after{content:attr(title);}
		li.diapo.up{display:none;}
		li.diapo.loaded{color:#999;-webkit-transition:all 0.4s linear;-moz-transition:all 0.4s linear;transition:all 0.4s linear;}
		li.diapo.loaded img{-webkit-transition:all 0.4s linear;-moz-transition:all 0.4s linear;transition:all 0.4s linear;}
		li.diapo.loaded span.minicom{position:absolute;top:5px;right:5px;width:24px;height:21px;font-size:10px;line-height:17px;text-align:center;color:#fff;background:transparent url(data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAABgAAAAVCAYAAABc6S4mAAAABHNCSVQICAgIfAhkiAAAAAlwSFlzAAAD0AAAA9ABSs1rUAAAABl0RVh0U29mdHdhcmUAd3d3Lmlua3NjYXBlLm9yZ5vuPBoAAAEDSURBVDiN7ZUxTsNAEEXfj30JTkGXiipF7kCLRI+EtIWF5M644AzcgYqKDgpOkBuEghIJu2AzFLsOjuI1RqL0l1Zj7cy+L89Ks6rr+hVYSjIAsxDiohf3+wO1+1zcI9a85MAyFqsH6n8fqYMMSTo4ul6Mgf5Ds8FsMBvMBhOUx7jjcGr2lY0BJPnE8BOwyM3sEjhLHD4fMfgidODBzD4SNc9Kjd6qqk6yLNsOpHw0fZR07ZzbJODAT4uOE3m+Ijwg3YA3QJI2wJVz7mkM3Cl5yWa2MrMdgeqBd0kXTdOcToWP/gGwJrSiNbPbtm3vyrL8nAr+zUCES7z33t8URfH2V3Cnb5qdYd58KZMsAAAAAElFTkSuQmCC) no-repeat center center;}
		li.diapo.loaded:hover{z-index:2;color:#eee;-webkit-transform:scale(1.2);-moz-transform:scale(1.2);-webkit-box-shadow:0 0 20px #fff;-moz-box-shadow:0 0 20px rgba(0,0,0,.9);box-shadow:0 0 20px rgba(0,0,0,.9);-webkit-transition:all 0.2s linear;-moz-transition:all 0.2s linear;transition:all 0.2s linear;text-shadow:0 0 10px #fff;}
		li.diapo.loaded.up:hover{-webkit-box-shadow:none;-moz-box-shadow:none;box-shadow:none;}
		li.diapo input[type="checkbox"]{position:absolute;top:5px;left:5px;}
		#view{position:absolute;overflow:hidden;top:0;bottom:0;left:0;right:0;opacity:0;z-index:0;background:#000;-webkit-transition:all 0.5s linear;-moz-transition:all 0.5s linear;transition:all 0.5s linear;}
		#view.active{opacity:1;z-index:1;-webkit-transition:all 0.5s linear;-moz-transition:all 0.5s linear;transition:all 0.5s linear;}
		#view img{position:absolute;display:block;opacity:0;left:0;top:0;width:1px;height:1px;z-index:0;}
		#view img.touch{opacity:1;z-index:4;}
		#view img.animated{opacity:1;z-index:4;-webkit-transition:all 1s ease-out;-moz-transition:all 1s ease-out;transition:all 1s ease-out;}
		#view #slide{position:absolute;left:60px;right:0;top:0;bottom:0;overflow:hidden;-webkit-transition:all 0.5s ease;-moz-transition:all 0.5s ease;transition:all 0.5s ease;}
		#view.com #slide{left:360px;-webkit-transition:all 0.5s ease;-moz-transition:all 0.5s ease;transition:all 0.5s ease;}
		#view.comfix #slide{left:360px;}
		#view #comments{position:absolute;top:0;left:-300px;bottom:0;width:300px;z-index:7;overflow:hidden;background-color:#000;color:#fff;-webkit-transition:all 0.5s ease;-moz-transition:all 0.5s ease;transition:all 0.5s ease;}
		#view.com #comments{left:60px;-webkit-transition:all 0.5s ease;-moz-transition:all 0.5s ease;transition:all 0.5s ease;}
		#view.comfix #comments{left:60px;}
		#coms{overflow:auto;position:absolute;top:3px;bottom:160px;width:285px;}
		#coms li{position:relative;padding:3px;margin:5px 5px 10px 5px;}
		#coms li content{}
		#coms li header{color:#888;font-size:12px;}
		#coms li button.del{position:absolute;right:2px;font-size:12px;}
		#newcom{position:absolute;bottom:5px;width:280px;height:140px;padding:3px;}
		#who, #what{width:280px;}
		#newcom .unused{color:#999;}
		#what{margin-top:5px;height:88px;resize:none;}
		#bsend{color:#eee;background:#666;width:280px;font-size:30px;line-height:20px;-webkit-border-radius:3px;-moz-border-radius:3px;border-radius:3px;}
		#bsend:hover{text-shadow:0 0 10px #fff;}
		#bsend:active{margin:1px 0 0 1px;}
		button.del{color:#eee;}
		#viewbar{position:absolute;top:0;left:0;width:60px;bottom:0;z-index:8;background-color:#000;}
		#viewbar button{position:absolute;border:none;background-color:transparent;opacity:0.5;color:#eee;-webkit-transition:all 0.3s linear;-moz-transition:all 0.3s linear;transition:all 0.3s linear;}
		#viewbar button:hover{opacity:1;-webkit-transition:all 0.3s linear;-moz-transition:all 0.3s linear;transition:all 0.3s linear;}
		#viewbar button.active{opacity:1;}
		#bprev{left:4px;top:2px;width:24px;height:48px;background:url(data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAABUAAAAgCAYAAAD9oDOIAAAABHNCSVQICAgIfAhkiAAAAAlwSFlzAAAJ2AAACdgBx6C5rQAAABl0RVh0U29mdHdhcmUAd3d3Lmlua3NjYXBlLm9yZ5vuPBoAAAKnSURBVEiJtdZPSBRRHAfw7++nqzuHDCO8VAfB6BBE0EGCIKSk6BIECxFS2qFjBcvOzkgpI+S+ndlNYotIkiAhD0YEEtRJiyAIunQsiaCgDmFU5Jrsvl8H1xqHmc3dtXf8/fnw5g+/90hE0OhSSnUxc0FE2rTWV6hRVCl1mIjuANhcCb1rCM1msxcAOADYH2+uBxsbGzOWl5evA0iE5WtGXdfdrrW+R0R7o2o4KhG2MpnMfhGZC4ACYL4u1HXdAWaeAdDhC/8E0EdE0zWh4+PjsWw2e1VErgFo8aXea60PpdPpmWBP1Xeaz+e3lkqluwAO+ONENKu17rdt+2tYXyQ6Ojq6h5mniGhHIHWjs7PzUiKRKEf1hqKu655oamq6CcDw7e4XEZ1PpVJTUVgo6jgOG4ZxWUSSgbpPWutTlmW9+he4BnVdd5NhGBMicjRQ85KZ+1Kp1Of1gEDl6yulukRkNgiKyOTS0tKxWkAAaM5kMt3MfB9/BwIAlADYlmXdqgX7gzJzxg+KyAIRnU6n08/qAYGVx4/8NepGtdZDAL6tBohoC4CHnuedqxu1bfuFiPQAeOOLN2utc0qpguM4LVHNkSgAWJY1T0Q9RPTYnySiM/F4/FEul+sIb6+CAoBpmj+KxeJJIsoHarrL5fJTz/Mi52ckCgDDw8PaNE2HiPoBFH2pbSLyxPO80ElfFfXt+kG5XO4VkQ+rMRExtNYTSqkRx3GqjszI5ODg4OtYLHYQwHN/nIguxuPx6UKh0FYzCgDJZPJLe3v7cQC3A6nexcXFOc/zdob1rfuIdl13QEQ8+Ka/iHwHcJaZ94mIXTMKrBx8zDyJteeUBvAWwK660MqON/aIBgDTND+2trYeATAdVfNfrj0bfkETkYWG0QrcxcwjWuvdANK/AXuBG01UJ2ZnAAAAAElFTkSuQmCC) no-repeat center center;}
		#bnext{left:32px;top:2px;width:24px;height:48px;background:url(data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAABUAAAAgCAYAAAD9oDOIAAAABHNCSVQICAgIfAhkiAAAAAlwSFlzAAAJ2AAACdgBx6C5rQAAABl0RVh0U29mdHdhcmUAd3d3Lmlua3NjYXBlLm9yZ5vuPBoAAAIRSURBVEiJrdU7aBRRGMXxf2IiWEjEV6WNSMBGLRUEWRCiBFQ04gMURRGEoIWPgxCJlcwpIqSxkOALCzEiRBuDogaEYKWkjNFGbYIIa4pIkGixN3IdZpe9u37d/c7Mj8vcx7RkWVYCMuADcFXSFE1WK3AL2ADsBl7Z3vE/0BXRuAN4ZPtcM2hLlmU/qmTDQK+k2VS0tUZ2ABi1vaZZ9CMwH403A69tb20GfQIcBGai3mrgqe0TjaJIGgVKQLy1FgODtq/bbk9GAzwZ4Be56BQwYntlMhrgMtADDOaibcCY7Y3JaIDnJV0JM/wZRWuB57b3JaMR/hDoAr5G7SXAHdv9tv9x6kID/A7YDrzNReeBB7aXJqMBnga6gXu5aCeVe2N9MhrgOUm9wAXgVxR1BnhLMhrhN4G9wPeo3QFcaxgN9bug19Ywavs0MAIsj9pl4FJbA1g7MAAcz0WTwCFJU0mo7VXAfSB/az0DTkqagYTVt70JGCsAB8IM/95sdc3U9n7gBpVTtFCzwBlJj/PP10TD8eujsifj+gwcljRR9F5VNBy7IWBXLnoDHJP0rdq7hd/U9jrgZQE4BOypBRbO1HYJuAssi9pzwEVJt2th1dBu4CywKOpNA0cljdcDFqGdufF74IikL/WCUHufDgNdqSAUr/480C8p/2+qu1qBT9G4DPQ0Ay6gl4EJYBwoScr/lpPrD6BJpvVDUJEtAAAAAElFTkSuQmCC) no-repeat center center;}
		#bcom{left:6px;top:148px;width:48px;height:48px;background:url(data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAADAAAAAqCAYAAAD1T9h6AAAABHNCSVQICAgIfAhkiAAAAAlwSFlzAAAHoAAAB6ABnZaTqAAAABl0RVh0U29mdHdhcmUAd3d3Lmlua3NjYXBlLm9yZ5vuPBoAAAKzSURBVFiF7ZlPa9RAGMafdxJ2k1IoPVmopz0rRf0AWi+9eCiC3nqw36DsJtkVcdnq1tlspYKg4BfwUFAvRTxUCgU9VAq96MWFouBFUPAPmyLm9bDZ7dimNcl2SYX8IMxM3jczz7ObSeANSSmvA7gBwATAwQGlv3d8UH8QsV6fiBgAOICIdpj5rg7gJoB8kEzBcexg5l6fiLqtq2NX/HcAb7s52DUS1obGOj9MZ/Zuy8yRr48RGwNgABjRFYeb5XL5wn7vxw8p5RoRnQcAkbaYfskMpE1mIG0yA2mTGUibzEDaZAbSJjOQNpmBtMkMpE1mIG0yA2nz3xvoFbaEEAXXdWXcCZh53XGclSSLNxqNCSKaRqcuGxkhRKFbaiQppY8+66FCiHOWZW1Gza/X6+OaptWJaAb93QWsA3gK4HIfk4CZR6Lk1Wq14Xw+b+u6XgQw1M+aAEBEL/RCoXB1e3t7gplzcS5m5mcATgDYabfbrw/LXV5e1lqt1jXDMG6hU5jt8hXAbSJ6FVc8M7dt294itWwdFdd1TzLzx2C45jjO5EG5jUZjCkATwGnl9C8AD3K53Pzc3NyX2AIU9H+nhNITTEQvwxIWFhZOaZq2CGBqT+gJMzvlcvl9wrX/IpEBZr6o9FfVWLPZHPN9f17TtFkAmhLaEEIULctaTyY1nKQGJoPvFz9GR0c3AKBWqw2ZpllkZhvAsJL+AUDFcZzH2P1sdGTE3gNSygIRtYLhc8/zLpmmOcPMdQDjSuo3AHc8z7tXrVa9I9K7j9j/gBBiUjG9YxjGG2Y+o6T8BvDI9/1qpVL5fBQiDyO2AfX+BzC9J7xCRJZt2+/6kxWdJHsg7JG5RURF27ZXQ2IDJdZrfGlpyUTn5dXlExHNep53Ng3xQLJNfJ+IrhDRQyHEYqlU+jkgbZH4A1OS9uj03IqAAAAAAElFTkSuQmCC) no-repeat center center;}
		#bcom span{position:absolute;left:0;top:4px;width:48px;text-align:center;font:bold 24px Baumans;}
		#bcom:hover span{text-shadow:0 0 5px #fff;}
		#bplay{left:6px;top:102px;width:48px;height:42px;background:url(data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAADAAAAAdCAYAAADsMO9vAAAABHNCSVQICAgIfAhkiAAAAAlwSFlzAAAILgAACC4B4ThkEQAAABl0RVh0U29mdHdhcmUAd3d3Lmlua3NjYXBlLm9yZ5vuPBoAAAJUSURBVFiF7Zg9aBRRFIW/OxkzE5VYKoqlin/YBH8KOwWFtGKlCBYahGWRZN9a6LLIxvc2jSESFAWx0pgyCFpqYSMWNpEIaQQLBVFEwyzqXgt3yRhm40ZI9gk5MPDuvefCOe8+5k+cczlVvQr04gfeAEeNMW/bIYeqOgysW15NS8J24CxQyipOTk52zc7O5kTkGHAj5E/xtRUQ2ApdQNhYb8giOOf2AXdEpK+R2hOm6jVjTLyMAheFtfaMiNzNqo2NjUVzc3NXgALzJgE2h1kNPmFkZORwvV6/DezIqntroFwu9/b09DhVPQdIMy8ij1X1CA3tQacELgYR2R/H8bSqnmde/EcROV0oFI4DP5tcLyegqofSsYhMBEGQGxwc/LCQ66WBFN4BA4VCYaoVwZsjJCLvU6ECt5Ik2WWMaSkePJpAkiRPoigaF5FtQRBUhoaGnrbT542BUqlUBy4stc+bI/SvWDXQaawa6DT+ewPe3EYBnHMDQHcURffy+fzndnq8mUC1Wj0FjAPXa7Xaa2vtiXb6vDGgqltS4SYReWitnapUKlsX6/PGQBZEpD8Mw+lqtZorl8uZWn01MAP8aKzXq+poHMfPnXN7FxK9NKCqD4A+4EUqfQB4aa0dJvWF5qUBAGPMqyRJDgJ54GsjvUZELgHdTZ63BuD3G6oxZhTYDTzK4qSfA5FzLlkRZdnoalVo/KXrt9aeFJFRYGOj9D0AvqS4UQev9GZ+yzJSLBYnVHWniNwEZkTkYigil1X1GrD2Lzu0UngWBMH9VsVisfgJGGjGvwD4Z6eH9jMo/QAAAABJRU5ErkJggg==) no-repeat center center;}
		#bthumb{left:6px;top:50px;width:48px;height:48px;background:url(data:image/png;base64,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) no-repeat center center;}
		#mask{display:none;z-index:50;position:absolute;top:0;bottom:0;left:0;right:0;}
		#mask.active{display:block;}
		#mask #loading{position:absolute;bottom:2px;right:5px;font:normal 32px Baumans;color:#888;}
		#mask.active #loading{-webkit-animation:roty 4s linear 0s infinite;-moz-animation:roty 4s linear 0s infinite;animation:roty 4s linear 0s infinite;}
		@-webkit-keyframes roty{0%{-webkit-transform:perspective(0px) rotateY(0deg)}
		50%{-webkit-transform:perspective(0px) rotateY(180deg)}
		100%{-webkit-transform:perspective(0px) rotateY(360deg)}
		}
		@-moz-keyframes roty{0%{-moz-transform:perspective(0px) rotateY(0deg)}
		50%{-moz-transform:perspective(0px) rotateY(180deg)}
		100%{-moz-transform:perspective(0px) rotateY(360deg)}
		}
		@keyframes roty{0%{transform:perspective(0px) rotateY(0deg)}
		50%{transform:perspective(0px) rotateY(180deg)}
		100%{transform:perspective(0px) rotateY(360deg)}
		}
		#intro{z-index:90;position:absolute;top:0;bottom:0;left:0;right:0;background-color:rgba(50,50,50,0.6);}
		#intro.hide{z-index:0;opacity:0;}
		#intro h1{font-size:60px;color:#fff;}
		#intro h2{font-size:30px;margin-bottom:10px;}
	</style>
<?php if( preg_match('/android|ipad|mobile/mi',$_SERVER['HTTP_USER_AGENT']) ){ ?>
	<style>
		#thumb,#thumb.active,li.diapo.loaded,li.diapo.loaded img,li.diapo.loaded:hover{-webkit-transition:none;-moz-transition:none;transition:none;}
		li.diapo.loaded:hover{-webkit-transform:none;-moz-transform:none;-webkit-box-shadow:none;-moz-box-shadow:none;box-shadow:none;text-shadow:none;}
	</style>
<?php } ?>
	<title><?php print($TITLE);?></title>
</head>
<body>
	<div id="mask"><div id="loading"></div></div>
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
		var locales={en:{title:{bnext:"next",bprev:"previous",bcom:"comments",bthumb:"thumbnail",bplay:"slideshow"},holder:{who:"enter your name\u2026",what:"enter your comment\u2026"},text:{loading:"loading\u2026"},date:{now:"now",min:"%d minute%s ago",hour:"%d hour%s ago",yesterday:"yesterday",day:"%d day%s ago",week:"%d week%s ago",month:"%d month%s ago"},bdel:"&#10006;",nocom:"be the first to comment",emptywho:"what's your name ?",emptywhat:"say something\u2026",play:"&#9654; PLAY",stop:"&#9632; STOP",dlall:"download all",dlsel:"download selected",zip:"compressing\u2026",nozip:"nothing to download",updir:""},fr:{title:{bnext:"suivante",bprev:"pr\u00e9c\u00e8dente",bcom:"commentaires",bthumb:"miniatures",bplay:"diaporama"},holder:{who:"entrez votre nom\u2026",what:"entrez votre commentaire\u2026"},text:{loading:"chargement\u2026"},date:{now:"a l'instant",min:"il y a %d minute%s",hour:"il y a %d heure%s",yesterday:"hier",day:"il y a %d jour%s",week:"il y a %d semaine%s",month:"il y a %d mois"},bdel:"&#10006;",
		nocom:"soyez le premier \u00e0 laisser un commentaire",emptywho:"de la part de ?",emptywhat:"dites quelque chose\u2026",play:"&#9654; LECTURE",stop:"&#9632; STOP",dlall:"tout t\u00e9l\u00e9charger",dlsel:"t\u00e9l\u00e9charger la s\u00e9lection",zip:"compression\u2026",nozip:"rien \u00e0 t\u00e9l\u00e9charger",updir:""}},loc,setlocale=function(d){var e,m;loc=locales[d]?locales[d]:locales.en;loc.reldate=function(c){var b=(new Date).getTime();c=(new Date(c)).getTime();b=(b-c)/1E3;c=function(b,c){c=
		Math.round(c);return b.replace("%d",c).replace("%s",1<c?"s":"")};return 60>b?loc.date.now:3600>b?c(loc.date.min,b/60):86400>b?c(loc.date.hour,b/3600):172800>b?loc.date.yesterday:604800>b?c(loc.date.day,b/86400):2592E3>b?c(loc.date.week,b/604800):c(loc.date.month,b/2592E3)};if(loc.title)for(e in loc.title)(m=_("#"+e))&&m.setAttribute("title",loc.title[e]);if(loc.holder)for(e in loc.holder)(m=_("#"+e))&&m.setAttribute("placeholder",loc.holder[e]);if(loc.text)for(e in loc.text)_("#"+e,loc.text[e])};
		ready(function(){setlocale(navigator.language)});
		var log=function(){var d,e,m=(new Date).getTime(),c={debug:1,info:2,warn:3,error:4},b=hash.get("log"),n=b&&c[b]?c[b]:0;if(!n)return{debug:function(){},info:function(){},warn:function(){},error:function(){}};console.log?d=function(b,e){if(c[b]>=n){var d,m=("     "+b).substr(-5)+"|";for(d in e)console.log(m+e[d])}}:(ready(function(){append(document.body,'<div id="log"></div>');e=_("#log")}),d=function(b,d){if(e&&c[b]>=n){var B,C=("000000"+((new Date).getTime()-m)).substr(-6);for(B in d)append(e,'<div class="'+
		b+'"><span class="timer">'+C+"</span>"+d[B].replace(" ","&nbsp;")+"</div>");e.scrollTop=e.scrollHeight}});return{debug:function(){d("debug",arguments)},info:function(){d("info",arguments)},warn:function(){d("warn",arguments)},error:function(){d("error",arguments)}}}(),osd=function(){var d,e,m,c,b,n=!1;ready(function(){d=_("#osd")});return{hide:function(){n=!1;css(d,"-active")},show:function(){css(d,"+active")},loading:function(c,d,n){e=n||"LOADING";m=c;b=d;this.show();this.set(0)},set:function(n){c=
		n;_(d,e+" "+n+"/"+m);n>=m&&(this.hide(),b&&b())},inc:function(){this.set(++c)},error:function(b){this.info(b,"error",3E3)},info:function(b,c,e){_(d,b).className=c||"";this.show();n&&clearTimeout(n);n=setTimeout(this.hide,e||1500)}}}(),walli;
		walli=function(){function d(a,b){var x=j[a],c,l=Date.now();c=new Image;K++;c.onload=function(c){c||(c=window.event);log.info("image #"+a+" "+x+" loaded in "+(Date.now()-l)/1E3+"s");b(a,c.target||c.srcElement);K--};c.onerror=function(){log.error("error loading "+("image #"+a+" "+x));b(a,null);K--};c.src=encodeURIComponent(x)}function e(a){a=/([^\/]+)\/$/.test(a)?RegExp.$1:a.replace(/^.*\//g,"");return a.replace(/\.[^\.]*$/,"").replace(/[\._\|]/g," ")}function m(){L&&!u&&(u=setInterval(function(){log.debug("refresh required");
		ajax("?!=count&path="+q,function(a){(P.length!=a.dirs||j.length!=a.files)&&c(q)})},1E3*L))}function c(a,b){u&&(clearInterval(u),u=!1);log.debug("loading path "+(a||"/"));var x=_("#diapos",""),f=_("#path","");_("#bzip",loc.dlall).className="hide";t=[];ajax("?!=ls&path="+a,function(a){q=a.path;log.info((q||"/")+"loaded with "+a.dirs.length+" subdirs and "+a.files.length+" files found");if(q.length){var d=q.replace(/[^\/]+\/$/,"/"),g=document.createElement("li");css(g,"diapo up loaded");g.setAttribute("title",
		loc.updir);g.onclick=function(){c(d)};x.appendChild(g)}var h=function(a,b,c,l){var D=document.createElement("img");D.onload=function(){log.debug(a+" loaded");css(this.parentNode,"+loaded")};D.onclick=b;b=document.createElement("li");css(b,"diapo "+c);b.appendChild(D);b.setAttribute("title",e(a));void 0!=l&&(D.id="diapo"+l,append(b,'<input type="checkbox" id="chk'+l+'" n="'+l+'" onchange="walli.zwap('+l+')"/><label for="chk'+l+'"></label>'));if((v[a]||[]).length)append(b,'<span class="minicom">'+(999<
		v[a].length?Math.floor(v[a].length/1E3)+"K+":v[a].length)+"</span>");x.appendChild(b);D.src="?!=mini&file="+encodeURIComponent(a)};j=a.files;P=a.dirs;v=a.coms;L=a.refresh;a.dirs.forEach(function(a){h(a,function(){c(a)},"dir")});a.files.forEach(function(a,b){h(j[b],function(){walli.show(b,0)},"",b)});a.files.length&&(_("#bzip").className="all");M();if(q){var k="";q.split("/").forEach(function(a){a&&(k+=a+"/",append(f,"<button onclick=\"walli.cd('"+k+"')\">"+a+"</button>"))})}b&&b();m()})}function b(a,
		b){if(N[a]){var c=p.clientWidth,f=p.clientHeight,l=N[a],d=l.h,e=l.w;e>c&&(e=c,d=Math.floor(e*(l.h/l.w)));d>f&&(d=f,e=Math.floor(d*(l.w/l.h)));css(g[a],{width:e+"px",height:d+"px",left:Math.floor((c-e)/2+c*b)+"px",top:Math.floor((f-d)/2)+"px"});log.debug("calcpos("+a+","+b+")="+g[a].style.left)}}function n(){H&&(y&&clearTimeout(y),y=setTimeout(walli.next,1E3*S))}function J(a){H!==a&&((H=a)?(n(),css("#bplay","active"),osd.info(loc.play)):(y&&clearTimeout(y),y=!1,css("#bplay",""),osd.info(loc.stop)))}
		function s(a){E!==a&&(E=a,log.debug("switch to "+a+" mode"),k=!0,"tof"==E?(u&&(clearInterval(u),u=!1),css(z,"+active"),css("#thumb","-active")):"zik"!=E&&"movie"!=E&&(k=!1,J(!1),css(g[0],""),css(g[1],""),css(z,"-active"),css("#thumb","+active"),m()),M())}function B(a){var b=_("#diapo"+h).parentNode,c=_("#minicom"+h);c&&b.removeChild(c);0<a&&append(b,'<span id="minicom'+h+'" class="minicom">'+a+"</span>")}function C(a,c){if(c||k&&I!==a)I=a,k&&(css(g[1-f],""),b(1-f,2)),a?(css("#bcom","+active"),css(z,
		"+com"+(c?"fix":"")),hash.set("com",1)):(css("#bcom","-active"),css(z,"-com"),css(z,"-comfix"),hash.del("com")),k&&setTimeout(function(){b(f,0)},550)}function O(a){var b="",c=v[a];c&&c.length?(c.forEach(function(c){b+="<li><header>"+c.who+' <span title="'+c.when.replace("T"," ")+'">'+loc.reldate(c.when)+"</span></header><content>"+c.what.replace("\n","<br/>")+"</content>"+(c.own?'<button class="del" onclick="walli.rmcom(\''+a.replace("'","\\'")+"',"+c.id+')">'+loc.bdel+"</button>":"")+"</li>"}),_(F,
		b),F.scrollTop=F.scrollHeight,_("#comcount",999<c.length?Math.floor(c.length/1E3)+"K+":c.length)):(_(F,loc.nocom),_("#comcount","0"))}function w(a){a&&a.stopPropagation&&a.stopPropagation()}function M(){k?hash.set("f",j[h]):q?hash.set("f",q):hash.del("f")}function Q(){var a=hash.get("f"),b=hash.get("com"),d=/^(.+\/)([^\/]*)$/.test(a)?RegExp.$1:"/",e=RegExp.$2;b?C(!0,!0):I&&C(!1,!0);return a.length?(b=function(){var b=j.indexOf(a),c=0;k&&b!=h&&(c=b<h?-1:1);-1!=b?walli.show(b,c):s("thumb")},d!=q?c(d,
		b):e?b():s("thumb"),!0):!1}var S=5,y=!1,u=!1,L,q,j=[],P=[],t=[],h=!1,f=0,g=[],N=[],z,G,A,K=0,H=!1,k,p,r={},v=[],I,E,F,R;return{setup:function(a){R=a.comments;z=_("#view");F=_("#coms");p=_("#slide");p.onmousewheel=function(a){k&&(0>(a.wheelDelta||a.detail/3)?walli.prev():walli.next(),a.preventDefault())};var d=function(){g[f].className="animated";g[f].style.left=r.l+"px";r={}};p.onmousedown=p.ontouchstart=function(a){a.preventDefault();a.touches&&(a=a.touches[0]);g[f].className="touch";r={d:!0,x:a.pageX,
		l:parseInt(g[f].style.left,10),h:setTimeout(d,1E3)}};p.onmousemove=p.ontouchmove=function(a){r.d&&(a.preventDefault(),a.touches&&(a=a.touches[0]),a=a.pageX-r.x,g[f].style.left=r.l+a+"px",80<Math.abs(a)&&(clearTimeout(r.h),r={},g[f].className="animated",80<a?walli.prev():walli.next()))};p.onmouseup=p.onmouseout=p.ontouchend=p.ontouchcancel=function(a){a.preventDefault();r.d&&(clearTimeout(r.h),d())};g=[_("#img0"),_("#img1")];p.ondragstart=g[0].ondragstart=g[1].ondragstart=function(a){a.preventDefault();
		return!1};window.onresize=function(){k&&b(f,0)};window.onorientationchange=function(){var a=_("#viewport"),c=window.orientation||0;a.setAttribute("content",90==c||-90==c||270==c?"height=device-width,width=device-height,initial-scale=1.0,maximum-scale=1.0":"height=device-height,width=device-width,initial-scale=1.0,maximum-scale=1.0");k&&b(f,0)};hotkeys.add("CTRL+D",function(){css("#log","*active")},!0).add("SPACE",walli.toggleplay).add("C",walli.togglecom).add("HOME",walli.first).add("LEFT",walli.prev).add("RIGHT",
		walli.next).add("END",walli.last).add(["ESC","UP"],walli.back).add("DOWN",function(){!k&&j.length&&walli.show(0)});_("#bprev").onclick=walli.prev;_("#bnext").onclick=walli.next;_("#bplay").onclick=walli.toggleplay;_("#bthumb").onclick=walli.thumb;_("#bzip").onclick=walli.dlzip;R&&(G=_("#who"),A=_("#what"),A.onfocus=G.onfocus=function(){J(!1)},_("#comments").onclick=w,_("#bcom").onclick=walli.togglecom,_("#bsend").onclick=walli.sendcom);log.info("show on!");var e=_("#intro");if(e){var h=setTimeout(function(){css(e,
		"hide")},5E3);e.onclick=function(){clearTimeout(h);css(e,"hide")}}Q()||(s("thumb"),c("/"));hash.onchange(Q)},dlzip:function(){var a=t.length?j.filter(function(a,b){return-1!=t.indexOf(b)}):j;a.length?(_("#bzip",loc.zip),ajax({url:"?!=zip",type:"POST",data:{files:a.join("*")},ok:function(a){a.error?osd.error(a.error):document.location="?!=zip&zip="+a.zip;walli.zwap()},error:function(a){osd.error(a);walli.zwap()}})):osd.info(loc.nozip)},zwap:function(a){void 0!=a&&(-1==t.indexOf(a)?t.push(a):t=t.filter(function(b){return b!=
		a}));t.length?_("#bzip",loc.dlsel.replace("%d",t.length)).className="selected":_("#bzip",loc.dlall).className="all"},thumb:function(){s("thumb")},show:function(a,c){j.length&&(h=0>a?j.length+a:a>=j.length?a%j.length:a,css("#mask","+active"),d(h,function(a,m){css("#mask","-active");k?f=1-f:(s("tof"),c=0);N[f]={w:m.width,h:m.height};p.removeChild(g[f]);g[f].src=m.src;if(c)css(g[f],""),b(f,c),p.appendChild(g[f]),css(g[f],"animated"),b(f,0),b(1-f,-c);else{css(g[f],"");var l=position("#diapo"+h);css(g[f],
		{width:l.width+"px",height:l.height+"px",left:l.left+"px",top:l.top+"px"});p.appendChild(g[f]);css(g[f],"animated");b(f,0)}n();M();setTimeout(function(){osd.info(e(j[h])+" ("+(h+1)+"/"+j.length+")")},1E3);1<j.length&&d((h+1)%j.length,function(){})}),O(j[h]))},next:function(a){w(a);k&&walli.show(++h,1)},prev:function(a){w(a);k&&walli.show(--h,-1)},first:function(a){w(a);k&&walli.show(0,-1)},last:function(a){w(a);k&&walli.show(-1,1)},play:function(a){w(a);j.length&&(k||walli.show(h,0),s("tof"))},stop:function(a){w(a);
		s("thumb")},toggleplay:function(a){w(a);J(!H)},togglecom:function(a){a&&a.stopPropagation();C(!I)},sendcom:function(){1>G.value.length?(osd.info(loc.emptywho),G.focus()):1>A.value.length?(osd.info(loc.emptywhat),A.focus()):ajax({type:"POST",url:"?!=comment",data:{file:j[h],who:G.value,what:A.value},ok:function(a){a.error?osd.error(a.error):(v[a.file]=a.coms,O(j[h]),B(a.coms.length),A.value="")},error:function(a){osd.error(a.statusText)}})},rmcom:function(a,b){ajax({type:"POST",url:"?!=uncomment",
		data:{file:a,id:b},ok:function(a){a.error?osd.error(a.error):(v[a.file]=a.coms,O(j[h]),B(a.coms.length))},error:function(a){osd.error(a.statusText)}})},back:function(){if(k)return s("thumb");var a=q.split("/");1<a.length&&c(a.slice(0,a.length-2).join("/"))},cd:function(a){s("thumb");c(a)}}}();
	</script>
	<script>
		ready(function(){
			walli.setup({
				comments: <?php print($withcom?'true':'false')?>
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