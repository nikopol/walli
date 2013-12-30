<?php
/*
WALLi (c) NiKo 2012-2013
standalone image wall
https://github.com/nikopol/walli

just put this single file into an http served directory 
containing [sub-dir of] media files.
*/

/*PHP CONFIG*/

//uncomment these settings if your experienced problem when uploading large files
//ini_set("upload_max_filesize", "32M");
//ini_set("post_max_size", "32M");

/*PARAMETERS*/

//all these parameters can be set in a config.inc.php 
//in the same dir as your main php file

//walli cache dir
//(used to store generated icon files and comments)
//set to false to disable cache & comments
//otherwise mkdir it and set it writable for
//your http user (usually www-data or http)
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

//delay in seconds for the client thumbnail auto refresh
//0=disabled
$REFRESH_DELAY = 0;

//set to false to disable comments
//require $SYS_DIR correctly set to work
//require also json support (php>=5.2)
$WITH_COMMENTS = true;

//set to true to enable zip download
//require $SYS_DIR correctly set to work
//require zip support ( see http://php.net/manual/en/zip.installation.php )
$WITH_ZIPDL = false;

//set to false to disable admin options
$ADMIN_LOGIN = false;
$ADMIN_PWD   = false;

//optionnal shell post process on uploaded files
//use %f for the file, set to false to disable
$UPLOAD_POSTPROCESS = false;
//sample with imagemagick, auto-rotate and resize to 1600 max
//$UPLOAD_POSTPROCESS = '/usr/bin/convert %f -auto-orient -resize 1600x1600\> %f';

//you can setup all previous parameters in an external file
//ignored if the file is not found
@include('config.inc.php');

/*CONSTANTS*/

define('VERSION','0.8');
define('COOKIE_UID','wallid');
define('COOKIE_GOD','wallia');
define('FILEMATCH','\.(png|jpe?g|gif)$');

/* GLOBALS */

if(!function_exists('imagecopyresampled')) die("GD extension is required");
if(!function_exists('json_encode')) die("JSON extension is required");

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

/* THEME GLOBALS */

$THUMB = array( 'engine' => 'default', 'size' => 150 );
$THUMB=array(
	'engine' => 'default',
	'size'   => 150
);

/*TOOLS*/

function redirect($uri='?'){
	header("Location: $uri");
	exit;
}

function cache($nbd=60){
	header('Cache-Control: public');
	header('Expires: '.gmdate('D, d M Y H:i:s', time()+(60*60*24*$nbd)).' GMT');
	header('ETag: '.(100*VERSION));
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
	$f=preg_replace('/^\/+/','',$f);  //avoid root dir
	$f=preg_replace('/(^|\/)\.\.+\//','',$f); //avoid parent dirs
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
	global $ROOT_DIR, $godmode;
	$files=array();
	$subs=array();
	$size=0;
	if($path && !preg_match('/\/$/',$path)) $path.='/';
	if(is_readable($ROOT_DIR.$path))
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
		if(count($sub['files']) || $godmode){
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

function iconify($file,$w,$h){
	list($imgw,$imgh)=getimagesize($file);
	if(preg_match('/\.png$/i',$file))      $src=@imagecreatefrompng($file);
	else if(preg_match('/\.gif$/i',$file)) $src=@imagecreatefromgif($file);
	else                                   $src=@imagecreatefromjpeg($file);
	$dst=imagecreatetruecolor($w,$h);
	if($src){
		if(function_exists('exif_read_data')){
			//autorotate if needed
			$e=@exif_read_data($file,null,true);
			$o=$e && isset($e['IFD0']['Orientation']) ? $e['IFD0']['Orientation'] : 0;
			if($o==6)      $rot=270;
			else if($o==3) $rot=180;
			else if($o==8) $rot=90;
			else $rot=0;
			if($rot){
				$tmp=imagerotate($src,$rot,0);
				imagedestroy($src);
				$src=$tmp;
				$imgw=imagesx($src);
				$imgh=imagesy($src);
			}
		}
		$srcx=$srcy=$srcw=$srch=0;
		$ri=$imgh/$imgw;
		$r=$h/$w;
		if($r==$ri){
			$srcw=$imgw;
			$srch=$imgh;
		} else if ($r>$ri) {
			$srcw=floor($imgh/$r);
			$srcx=floor(($imgw-$srcw)/2);
			$srch=$imgh;
		} else if ($r<$ri) {
			$srcw=$imgw;
			$srch=floor($imgw*$r);
			$srcy=floor(($imgh-$srch)/2);
		}
 		// die("img($imgw x $imgh) usr($w x $h) r=$r ri=$ri  srcx=$srcx srcy=$srcy srcw=$srcw srch=$srch");
		imagecopyresampled($dst, $src, 0, 0, $srcx, $srcy, $w, $h, $srcw, $srch);
		imagedestroy($src);
	}
	return $dst;
}

function load_coms($path,$file=false){
	global $uid;
	$comfile=get_sys_file($path.'.comments.json',0);
	$coms=$comfile && file_exists($comfile)
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

/*PUBLIC API*/

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

function GET_exif(){
	$fn=$_GET['file'];
	$file=get_file_path($fn);
	if(!file_exists($file)) notfound($file);
	if(!function_exists('exif_read_data')) error(409,'EXIF not supported');
	send_json(array('exif'=>@exif_read_data($file,null,true)));
}

function GET_mini(){
	$fn=$_GET['file'];
	$w=$_GET['w'];
	$h=$_GET['h'];
	$file=get_file_path($fn);
	if(!file_exists($file)) notfound($file);
	$isdir=is_dir($file);
	$cachefile=get_sys_file(($isdir?'dir-':'').$fn.'-'.$w.'x'.$h.'.png',0);
	header('Content-Type: image/png');
	cache();
	if($cachefile && file_exists($cachefile)){
		@readfile($cachefile);
		exit;
	}
	if($isdir){
		$list=ls($fn,FILEMATCH,1);
		$nb=count($list['files']);
		if($nb<9){
			$cachefile=get_sys_file('dir-'.$fn.'-'.$w.'x'.$h.'x'.$nb.'.png',0);
			if($cachefile && file_exists($cachefile)){
				@readfile($cachefile);
				exit;
			}
		}
		for($n=0; $n<$nb; $n++){
			$sf=get_sys_file('dir-'.$fn.'-'.$w.'x'.$h.'x'.$n.'.png',0);
			if(file_exists($sf)) @unlink($sf);
		}
		$mini=imagecreatetruecolor($w,$h);
		$bgc=imagecolorallocate($mini,255,255,255);
		imagefill($mini,0,0,$bgc);
		$sizew=floor(($w-2)/3);
		$sizeh=floor(($h-2)/3);
		$n=0;
		shuffle($list['files']);
		foreach($list['files'] as $f){
			$img=iconify(get_file_path($f),$sizew-2,$sizeh-2);
			$x=($n % 3) * $sizew;
			$y=floor($n / 3) * $sizeh;
			imagecopyresampled($mini, $img, $x+2, $y+2, 0, 0, $sizew-2, $sizeh-2, $sizew-2, $sizeh-2);
			imagedestroy($img);
			$n++;
			if($n>8) break;
		}
	} else
		$mini=iconify($file,$w,$h);
	if($cachefile) @imagepng($mini,$cachefile);
	imagepng($mini);
	imagedestroy($mini);
}

function POST_comment(){
	global $uid, $TIMEZONE;
	$file=$_POST['file'];
	$comfile=get_sys_file(dirname($file).'.comments.json');
	$what=htmlspecialchars($_POST['what'], ENT_QUOTES);
	if(empty($what)) error(400,'empty comment');
	$who=htmlspecialchars($_POST['who'], ENT_QUOTES);
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
	global $withzip;
	if(!$withzip) error(401,'zip not enabled');
	$lst=explode('*',$_POST['files']);
	$fn='pack-'.time().'.zip';
	$fz=get_sys_file($fn);
	$zip=new ZipArchive;
	$r=$zip->open($fz,ZIPARCHIVE::CREATE);
	if($r!==true) error(400,"error#$r opening zip");
	$nb=0;
	foreach($lst as $f){
		$f=get_file_path($f);
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
	$f=$_GET['zip'];
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

function POST_mkdir(){
	global $SYS_DIR;
	godcheck();
	$dir=preg_replace('/[\?\*\/\\\!\>\<]/','_',$_POST['dir']);
	$path=check_path($_POST['path']).'/'.$dir;
	@mkdir($path);
	nocache();
	send_json(array('ok'=>file_exists($path),path=>$path));
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
		'path'  => $path,
		'stats' => array(
			'images'  => count($ls['files']),
			'size'    => $ls['size'],
			'subdirs' => count($ls['dirs']),
			'max subdirs depth' => $maxdepth,
		),
		'checks' => array(
			'admin'        => $withadm,
			'upload'       => writable($path),
			'zip download' => $withzip,
			'comments'     => $withcom,
			'cache'        => check_sys_dir(),
			'intro'        => $withintro,
			'auto refresh' => $REFRESH_DELAY,
			'exif support' => function_exists('exif_read_data')
		)
	));
}

function POST_img() {
	global $ROOT_DIR, $UPLOAD_POSTPROCESS;
	godcheck();
	$path=check_path($_GET['path']).'/';
	if(!is_dir($path)) error(404,'path '.$path.' not found');
	$nb=$sz=$rs=0;
	$pp='?';
	foreach($_FILES as $k => $f) {
		$fn=$ROOT_DIR.$path.$f['name'];
		if(@move_uploaded_file($f['tmp_name'], $fn)) {
			$nb++;
			$sz+=$f['size'];
			if($UPLOAD_POSTPROCESS) {
				$ff="'".str_replace("'","\\'",$fn)."'";
				$pp=str_replace('%f',$ff,$UPLOAD_POSTPROCESS);
				@shell_exec($pp);
				$rs+=filesize($fn);
			}
		}
	}
	send_json(array(
		'pp'    =>$pp,
		'added' =>$nb,
		'size'  =>$sz,
		'path'  =>$path,
		'resize'=>$rs
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
			if(array_key_exists($file,$coms)) {
				$com+=count($coms[$file]);
				unset($com[$file]);
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
	$do=$_SERVER['REQUEST_METHOD'].'_'.$_REQUEST['!'];
	if(!function_exists($do)) notfound($do);
	call_user_func($do);
	exit;
}

if(empty($_COOKIE[COOKIE_UID])) setcookie(COOKIE_UID,$uid);

//admin login
if($_SERVER["QUERY_STRING"]=="login" && $ADMIN_LOGIN && $ADMIN_PWD){
	$godmode=isset($_SERVER['PHP_AUTH_USER'])
		&& isset($_SERVER['PHP_AUTH_PW'])
		&& $_SERVER['PHP_AUTH_USER']==$ADMIN_LOGIN
		&& $_SERVER['PHP_AUTH_PW']==$ADMIN_PWD;
	if($godmode){
		setcookie(COOKIE_GOD,$godsha);
		redirect();
	} else {
		header('WWW-Authenticate: Basic realm="'.$TITLE.' admin"');
		header('HTTP/1.0 401 Unauthorized');
		echo '<script>document.location="?"</script>';
		exit;
	}
}else if($_SERVER["QUERY_STRING"]=="logout"){
	setcookie(COOKIE_GOD,false);
	redirect();	
}

$intro=$withintro
	? @file_get_contents($ROOT_DIR.$INTRO_FILE)
	: false;

header('Content-Type: text/html; charset=utf-8');

?>
<!doctype html>
<!--
WALLi v<?php print(VERSION) ?> (c) NiKo 2012-2014
stand-alone image wall - https://github.com/nikopol/walli
-->
<html>
<head>
	<meta http-equiv="X-UA-Compatible" content="IE=Edge"/>
	<meta name="description" content="<?php print($TITLE);?> - Image Wall"/>
	<meta name="keywords" content="walli,picture,image,wall,thumbnail"/>
	<meta name="apple-mobile-web-app-capable" content="yes"/>
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<link rel="shorcut icon" type="image/png" href="data:image/png;base64,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" />
	<style>
		@import url(http://fonts.googleapis.com/css?family=Baumans);
		*{margin:0;padding:0;outline-style:none}
		body{overflow:hidden;-webkit-font-smoothing:subpixel-antialiased !important;background:#000;font:normal 16px "Baumans";color:#ccc}
		body.sleep{cursor:none}
		a,a:visited{text-decoration:none}
		input[type="text"],textarea,select{width:380px;font:normal 16px "Baumans";border:none;background-color:#222;color:#fff;padding:0;-webkit-border-radius:3px;-moz-border-radius:3px;border-radius:3px}
		ul{list-style:none}
		button::-moz-focus-inner{border:0}
		::-webkit-scrollbar{background:transparent;width:10px;height:10px;border:none}
		::-webkit-scrollbar:hover{background:#666}
		::-webkit-scrollbar:vertical{margin-left:5px}
		::-webkit-scrollbar-thumb{background:#aaa;border:none;border-radius:6px}
		::-webkit-scrollbar-button{display:none}
		#copyright{position:absolute;z-index:99;bottom:2px;right:20px;bottom:22px;right:-2px;-webkit-transform:rotate(-90deg) translateY(10px) translateX(6px);-moz-transform:rotate(-90deg);transform:rotate(-90deg)}
		#copyright a,#copyright a:visited{font:normal 10px Arial,Helvetica;color:#aaa}
		#log{position:absolute;top:0;right:-501px;bottom:0;width:500px;z-index:32000;font:normal 12px Monaco,"DejaVu Sans Mono","Lucida Console","Andale Mono",monospace;background-color:#000;padding-left:2px;color:#fff;opacity:0.8;overflow:scroll;-webkit-transition:right 0.5s ease;-moz-transition:right 0.5s ease;transition:right 0.5s ease}
		#log.active{right:0;-webkit-transition:right 0.5s ease;-moz-transition:right 0.5s ease;transition:right 0.5s ease}
		#log .debug{color:#777}
		#log .info{color:#ddd}
		#log .warn{color:#fc0}
		#log .error{color:#f88}
		#log .timer{color:#aaa;border-right:1px solid #aaa;margin-right:3px;padding-right:3px}
		#osd{display:none;position:absolute;top:0;left:0;right:0;height:40px;z-index:0;color:#fff;font:bold 30px Arial;opacity:0;text-align:center;padding:0 10px;background-color:rgba(0,0,0,0.2);-webkit-transition:all 0.5s ease;-moz-transition:all 0.5s ease;transition:all 0.5s ease;font:normal 30px Baumans;background-color:rgba(0,0,0,0.4);top:5px;left:70px;right:70px;-moz-border-radius:20px;-webkit-border-radius:20px;border-radius:20px}
		#osd sup{position:absolute;top:0;left:0;right:0;height:40px;z-index:1;color:#ccc;font:bold 20px Arial;text-align:right;margin-right:5px;font:normal 20px Arial;margin-top:7px}
		#osd.active{display:block;z-index:5;opacity:1;-webkit-transition:opacity 0.2s ease-in-out;-moz-transition:opacity 0.2s ease-in-out;transition:opacity 0.2s ease-in-out}
		#osd.error{color:#f77}
		#progress{display:none;position:absolute;left:0;bottom:0;height:25px;right:0}
		#progress.active{display:block;z-index:50;background-color:rgba(0,0,0,0.2)}
		#progressbar{position:absolute;height:25px;background-color:rgba(255,255,255,0.2);z-index:51}
		#progresstext{position:absolute;width:100%;font-size:22px;text-align:center;color:#eee;z-index:52}
		#thumb{position:absolute;top:0;bottom:0;left:0;right:0;opacity:0;z-index:0;overflow:auto;padding:5px 10px 10px 10px;-webkit-transition:all 0.5s linear;-moz-transition:all 0.5s linear;transition:all 0.5s linear}
		#thumb.active{opacity:1;z-index:1;-webkit-transition:all 0.5s linear;-moz-transition:all 0.5s linear;transition:all 0.5s linear}
		#thumbbar{position:absolute;left:0;right:0;top:0;padding:5px 5px 0 20px;font-size:0;clear-after:both}
		#thumbbar h1{font:bold 24px Arial;display:inline;margin:0 4px 0 -14px;padding:0 12px;cursor:default;font:bold 24px "Baumans";color:#eee;background:#333}
		#thumbbar button{position:relative;display:inline;height:28px;font:normal 18px Arial;vertical-align:top;padding:0 5px;font:normal 18px Baumans;color:#ccc;background:#444;padding:0 30px 0 14px;margin:0 -9px 4px 0}
		#bzip.empty,#bzip.hide,#bzip.all{display:none}
		#path{display:inline}
		#diapos{position:absolute;top:40px;padding:11px 11px 0 11px;left:0;right:0;bottom:0;overflow:auto}
		li.diapo{position:relative;display:inline;float:left;width:150px;height:168px;overflow:hidden;text-align:center;vertical-align:bottom;margin:5px;padding:0;background-color:#000}
		li.diapo img{display:block}
		li.diapo:after{content:attr(title)}
		li.diapo.up{display:none}
		li.diapo.loaded{-webkit-transition:all 0.4s linear;-moz-transition:all 0.4s linear;transition:all 0.4s linear;color:#999}
		li.diapo.loaded span.minicom{position:absolute;top:5px;right:5px;width:24px;height:21px;font-size:10px;line-height:17px;text-align:center;color:#fff;background:#666;background:transparent url(data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAABgAAAAVCAYAAABc6S4mAAAABHNCSVQICAgIfAhkiAAAAAlwSFlzAAAD0AAAA9ABSs1rUAAAABl0RVh0U29mdHdhcmUAd3d3Lmlua3NjYXBlLm9yZ5vuPBoAAAEDSURBVDiN7ZUxTsNAEEXfj30JTkGXiipF7kCLRI+EtIWF5M644AzcgYqKDgpOkBuEghIJu2AzFLsOjuI1RqL0l1Zj7cy+L89Ks6rr+hVYSjIAsxDiohf3+wO1+1zcI9a85MAyFqsH6n8fqYMMSTo4ul6Mgf5Ds8FsMBvMBhOUx7jjcGr2lY0BJPnE8BOwyM3sEjhLHD4fMfgidODBzD4SNc9Kjd6qqk6yLNsOpHw0fZR07ZzbJODAT4uOE3m+Ijwg3YA3QJI2wJVz7mkM3Cl5yWa2MrMdgeqBd0kXTdOcToWP/gGwJrSiNbPbtm3vyrL8nAr+zUCES7z33t8URfH2V3Cnb5qdYd58KZMsAAAAAElFTkSuQmCC) no-repeat center center}
		li.diapo.loaded.up:hover{-webkit-box-shadow:none;-moz-box-shadow:none;box-shadow:none}
		li.diapo input[type="checkbox"]{position:absolute;top:5px;left:5px}
		#view{position:absolute;overflow:hidden;top:0;bottom:0;left:0;right:0;opacity:0;z-index:0;-webkit-transition:all 0.5s linear;-moz-transition:all 0.5s linear;transition:all 0.5s linear;background:#000}
		#view.active{opacity:1;z-index:1;-webkit-transition:all 0.5s linear;-moz-transition:all 0.5s linear;transition:all 0.5s linear}
		#view img{position:absolute;display:block;opacity:0;left:0;top:0;width:1px;height:1px;z-index:0}
		#view img.touch{opacity:1;z-index:4}
		#view img.animated{opacity:1;z-index:4;-webkit-transition:all 0.7s ease-out;-moz-transition:all 0.7s ease-out;transition:all 0.7s ease-out}
		#view.play img{-webkit-animation:imgplay 8s linear 0s infinite alternate;animation:imgplay 8s linear 0s infinite alternate}
		@-webkit-keyframes imgplay{0%{-webkit-transform:scale(1)}
		100%{-webkit-transform:scale(1.2)}
		}
		@keyframes imgplay{0%{transform:scale(1)}
		100%{transform:scale(1.2)}
		}
		#view #slide{position:absolute;left:0;right:0;top:0;bottom:0;overflow:hidden;-webkit-transition:all 0.5s ease;-moz-transition:all 0.5s ease;transition:all 0.5s ease}
		#view.com #slide{left:360px;-webkit-transition:all 0.5s ease;-moz-transition:all 0.5s ease;transition:all 0.5s ease}
		#view.comfix #slide{left:360px}
		#view #slide.box{-webkit-perspective-origin:50% 50%;-moz-perspective-origin:50% 50%;-ms-perspective-origin:50% 50%;perspective-origin:50% 50%;-webkit-perspective:1300px;-moz-perspective:1300px;-ms-perspective:1300px;perspective:1300px}
		#view #slide.box img{-webkit-backface-visibility:hidden;box-sizing:border-box;-webkit-transition:all 800;-moz-transition:all 800;-ms-transition:all 800;transition:all 800;-webkit-transform-style:preserve-3d;-moz-transform-style:preserve-3d;-ms-transform-style:preserve-3d;transform-style:preserve-3d}
		#view #slide.box img.left{z-index:0;opacity:0;-webkit-transform:translate3d(100%, 0, 0) rotateY(90deg) translate3d(100%, 0, 0);-moz-transform:translate3d(100%, 0, 0) rotateY(90deg) translate3d(100%, 0, 0);-ms-transform:translate3d(100%, 0, 0) rotateY(90deg) translate3d(100%, 0, 0);transform:translate3d(100%, 0, 0) rotateY(90deg) translate3d(100%, 0, 0)}
		#view #slide.box img.right{z-index:0;opacity:0;-webkit-transform:translate3d(-100%, 0, 0) rotateY(-90deg) translate3d(-100%, 0, 0);-moz-transform:translate3d(-100%, 0, 0) rotateY(-90deg) translate3d(-100%, 0, 0);-ms-transform:translate3d(-100%, 0, 0) rotateY(-90deg) translate3d(-100%, 0, 0);transform:translate3d(-100%, 0, 0) rotateY(-90deg) translate3d(-100%, 0, 0)}
		#view #slide.box img.center{z-index:10;opacity:1}
		#view #comments{position:absolute;top:40px;left:-300px;bottom:0;width:300px;z-index:7;overflow:hidden;-webkit-transition:all 0.5s ease;-moz-transition:all 0.5s ease;transition:all 0.5s ease;color:#fff;background-color:#000}
		#view.com #comments{left:60px;-webkit-transition:all 0.5s ease;-moz-transition:all 0.5s ease;transition:all 0.5s ease}
		#view.comfix #comments{left:60px}
		#coms{overflow:auto;position:absolute;top:3px;bottom:180px;width:285px}
		#coms li{position:relative;padding:3px;margin:5px 5px 10px 5px;background:#222;border-radius:2px}
		#coms li button.del{position:absolute;top:1px;right:2px;font-size:12px;color:#eee}
		#newcom{position:absolute;bottom:25px;width:280px;height:140px;padding:3px}
		#who, #what{width:280px}
		#what{height:88px;resize:none;margin-top:5px}
		#viewbar{position:absolute;top:0;left:0;width:60px;bottom:0;z-index:8}
		#viewbar button{position:absolute;border:none;background-color:transparent;opacity:0.5;-webkit-transition:all 0.3s linear;-moz-transition:all 0.3s linear;transition:all 0.3s linear;color:#eee}
		#viewbar button:hover{opacity:1;-webkit-transition:all 0.3s linear;-moz-transition:all 0.3s linear;transition:all 0.3s linear}
		#viewbar button.active{opacity:1}
		body.sleep #viewbar{display:none}
		#mask{display:none;z-index:50;position:absolute;top:0;bottom:0;left:0;right:0}
		#mask.active{display:block}
		#mask #loading{position:absolute;bottom:28px;left:0;right:0;text-align:center;font:normal 32px Arial;color:#fff;font:normal 32px Baumans;text-shadow:0 0 10px #fff}
		#intro{z-index:90;position:absolute;top:0;bottom:0;left:0;right:0;background-color:rgba(50,50,50,0.6)}
		#intro.hide{z-index:0;opacity:0}
		#intro h1{font-size:60px;color:#fff}
		#intro h2{font-size:30px;margin-bottom:10px}
		#godbar{position:absolute;right:20px;top:15px;width:16px;z-index:30}
		#godbar button{float:left;height:16px;width:16px;margin:3px;opacity:0.3;border:none}
		#godbar button:hover{opacity:1;-webkit-transition:all 0.3s linear;-moz-transition:all 0.3s linear;transition:all 0.3s linear}
		#godbar button.hide{display:none}
		#iupload{position:absolute;top:-1000px}
		#diag{position:absolute;color:#fff;top:40px;right:40px;overflow:auto;border-radius:4px;background-color:#333;z-index:90;padding:6px;-webkit-box-shadow:3px 3px 5px #000;-moz-box-shadow:3px 3px 5px #000;box-shadow:3px 3px 5px #000}
		#diag li{list-style-type:none;background-repeat:no-repeat;background-position:0 center;padding-left:20px}
		#diag li.ok{background-color:#a44;background-color:transparent;background-image:url(data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAABAAAAAQCAYAAAAf8/9hAAAA/klEQVQ4y9XTsUqDQRAE4O834AMINrESBLk8gK2Ch2ApKNgogq0P4BtYWQlWtta2FikiilYBQSSlIHiNIKSyjM0fOc9ECNo45d7M3M7uXTUYDPwfxBQaMYWlvDY1occZbmMKmxMbxBTOsIcGzmMKG98MYgqNMeIT7Gelaex8MYgpLOKxzBhTOMZB4XmJbahq0gI6mEMfq+1mrxtTOMJhIb7CervZe88NOljOSG+4KNqGG6wNxXmEXTxnxJkR4rv85iGqLOs8rusYJbp1rH558DnEdrP3hBW8FJz7uu3+qA1VI1bWqgc6i4f65tdx76Mas/cWTrH1k/hPUP32O38A6YhNlVyEa38AAAAASUVORK5CYII=)}
		#diag li.bad{background-color:#4a4;background-color:transparent;background-image:url(data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAABAAAAAQCAYAAAAf8/9hAAAA8ElEQVQ4y62TQQrCMBBFX1s3Bc8wvUih4EIQXHkXwbUHEgSh0IVQ6EWSMwjZunAiaZpWQT8EwmT+n8lnBv4JI9IYkZsRKRNvpRFpjUgdxrOQDLRACdyBfWWt82SgA2rAAdvK2uEtYEQ2wE3JHndgr3dP9nDArrK2X2ngGJEBNtpREZHR3BPQ5xo4AH3CliZBRnMPsQelVmw+eN1r+24k8KXIiAyQJ5KKheqTtzyovk64HaMGunBOsoDcfiCHGHQWnO/gsuD2MNPJNfzCWYdjYpieWMQp5yWgY7kLRN5uV9Y+IpHRKKeWqZ1ZpnVqmX7GE05oWTqPdEKdAAAAAElFTkSuQmCC)}
		body.sleep #godbar{display:none}
		#help{display:none;position:fixed;top:50%;left:50%;width:720px;margin-left:-360px;margin-top:-200px;z-index:99;background-color:rgba(0,0,0,0.8);padding:20px;border-radius:30px;font-size:18px}
		#help.active{display:block}
		#help table{width:100%}
		#help td{padding:0 10px}
		#help th{text-transform:uppercase;border-bottom:1px #fff dotted;color:#fff}
		.warning{position:absolute;top:0;bottom:0;left:0;right:0;font-size:24px;color:#f66;z-index:9999;background:rgba(0,0,0,0.5);text-align:center;padding-top:40%}
		#exif{display:none;position:absolute;right:40px;top:50px;bottom:50px;font:normal 10px Arial,Helvetica;color:#fff;z-index:9;overflow:auto;padding-right:5px}
		#exif::-webkit-scrollbar{width:7px}
		#exif::-webkit-scrollbar-thumb{border-radius:0}
		#exif.active{display:block}
		#exif div{background-color:rgba(0,0,0,0.6);margin-bottom:5px;padding:3px}
		#exif h3{font:bold 14px Arial,Helvetica}
		#exif table{margin-left:10px}
		#exif table:after{content:"";display:block;clear:both}
		#exif th{padding-right:5px;color:#fd4;text-align:right;width:130px;vertical-align:top}
		button{margin:0;padding:0;border:none;background-color:transparent}
		::-webkit-scrollbar:hover{background:#222}
		::-webkit-scrollbar-thumb{background:#999}
		#thumbbar h1:hover{background:#666;text-shadow:0 0 10px #fff}
		#thumbbar button:before{content:"";position:absolute;display:block;left:-14px;top:0;height:0;width:0;border-color:#444 #444 #444 transparent;border-style:solid;border-width:14px}
		#thumbbar button:after{content:"";position:absolute;display:block;right:0;top:0;height:0;width:0;border-color:#000 #000 #000 #444;border-style:solid;border-width:14px}
		#thumbbar button:hover{background:#666;color:#eee;text-shadow:0 0 10px #fff}
		#thumbbar button:hover:before{border-color:#666 #666 #666 transparent}
		#thumbbar button:hover:after{border-color:#000 #000 #000 #666}
		#thumbbar button#bzip{background:#666}
		#thumbbar button#bzip:hover{background:#888;color:#fff}
		#thumbbar button#bzip:before{border-color:#666 #666 #666 transparent}
		#thumbbar button#bzip:hover:before{border-color:#888 #888 #888 transparent}
		#thumbbar button#bzip:after{border-color:#000 #000 #000 #666}
		#thumbbar button#bzip:hover:after{border-color:#000 #000 #000 #888}
		#thumbbar button:first-child{padding-left:10px}
		#thumbbar button:first-child:before{border-width:0}
		li.diapo.loaded:hover,li.diapo.loaded.cursor{z-index:2;color:#eee;-webkit-transform:scale(1.2);-moz-transform:scale(1.2);-webkit-box-shadow:0 0 20px rgba(0,0,0,.9);-moz-box-shadow:0 0 20px rgba(0,0,0,.9);box-shadow:0 0 20px rgba(0,0,0,.9);-webkit-transition:all 0.2s linear;-moz-transition:all 0.2s linear;transition:all 0.2s linear;text-shadow:0 0 10px #fff}
		#view #slide{}
		#view img.left{left:-200%}
		#view img.right{left:200%}
		#view img.center{}
		#coms li header{color:#888;font-size:12px}
		#newcom .unused{color:#999}
		#bsend{color:#eee;background:#666;width:280px;font-size:30px;line-height:20px;-webkit-border-radius:3px;-moz-border-radius:3px;border-radius:3px}
		#bsend:hover{text-shadow:0 0 10px #fff}
		#bsend:active{margin:1px 0 0 1px}
		#viewbar button#bprev{position:fixed;left:-100px;top:200px;width:160px;bottom:200px;opacity:0;background:url(data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAABUAAAAgCAYAAAD9oDOIAAAABHNCSVQICAgIfAhkiAAAAAlwSFlzAAAJ2AAACdgBx6C5rQAAABl0RVh0U29mdHdhcmUAd3d3Lmlua3NjYXBlLm9yZ5vuPBoAAAKnSURBVEiJtdZPSBRRHAfw7++nqzuHDCO8VAfB6BBE0EGCIKSk6BIECxFS2qFjBcvOzkgpI+S+ndlNYotIkiAhD0YEEtRJiyAIunQsiaCgDmFU5Jrsvl8H1xqHmc3dtXf8/fnw5g+/90hE0OhSSnUxc0FE2rTWV6hRVCl1mIjuANhcCb1rCM1msxcAOADYH2+uBxsbGzOWl5evA0iE5WtGXdfdrrW+R0R7o2o4KhG2MpnMfhGZC4ACYL4u1HXdAWaeAdDhC/8E0EdE0zWh4+PjsWw2e1VErgFo8aXea60PpdPpmWBP1Xeaz+e3lkqluwAO+ONENKu17rdt+2tYXyQ6Ojq6h5mniGhHIHWjs7PzUiKRKEf1hqKu655oamq6CcDw7e4XEZ1PpVJTUVgo6jgOG4ZxWUSSgbpPWutTlmW9+he4BnVdd5NhGBMicjRQ85KZ+1Kp1Of1gEDl6yulukRkNgiKyOTS0tKxWkAAaM5kMt3MfB9/BwIAlADYlmXdqgX7gzJzxg+KyAIRnU6n08/qAYGVx4/8NepGtdZDAL6tBohoC4CHnuedqxu1bfuFiPQAeOOLN2utc0qpguM4LVHNkSgAWJY1T0Q9RPTYnySiM/F4/FEul+sIb6+CAoBpmj+KxeJJIsoHarrL5fJTz/Mi52ckCgDDw8PaNE2HiPoBFH2pbSLyxPO80ElfFfXt+kG5XO4VkQ+rMRExtNYTSqkRx3GqjszI5ODg4OtYLHYQwHN/nIguxuPx6UKh0FYzCgDJZPJLe3v7cQC3A6nexcXFOc/zdob1rfuIdl13QEQ8+Ka/iHwHcJaZ94mIXTMKrBx8zDyJteeUBvAWwK660MqON/aIBgDTND+2trYeATAdVfNfrj0bfkETkYWG0QrcxcwjWuvdANK/AXuBG01UJ2ZnAAAAAElFTkSuQmCC) no-repeat 115px center rgba(0,0,0,0.4);-webkit-border-radius:0 190px 190px 0;-moz-border-radius:0 190px 190px 0;border-radius:0 190px 190px 0}
		#viewbar button#bprev:hover{opacity:1}
		#viewbar button#bnext{position:fixed;right:-100px;top:200px;width:160px;bottom:200px;opacity:0;background:url(data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAABUAAAAgCAYAAAD9oDOIAAAABHNCSVQICAgIfAhkiAAAAAlwSFlzAAAJ2AAACdgBx6C5rQAAABl0RVh0U29mdHdhcmUAd3d3Lmlua3NjYXBlLm9yZ5vuPBoAAAIRSURBVEiJrdU7aBRRGMXxf2IiWEjEV6WNSMBGLRUEWRCiBFQ04gMURRGEoIWPgxCJlcwpIqSxkOALCzEiRBuDogaEYKWkjNFGbYIIa4pIkGixN3IdZpe9u37d/c7Mj8vcx7RkWVYCMuADcFXSFE1WK3AL2ADsBl7Z3vE/0BXRuAN4ZPtcM2hLlmU/qmTDQK+k2VS0tUZ2ABi1vaZZ9CMwH403A69tb20GfQIcBGai3mrgqe0TjaJIGgVKQLy1FgODtq/bbk9GAzwZ4Be56BQwYntlMhrgMtADDOaibcCY7Y3JaIDnJV0JM/wZRWuB57b3JaMR/hDoAr5G7SXAHdv9tv9x6kID/A7YDrzNReeBB7aXJqMBnga6gXu5aCeVe2N9MhrgOUm9wAXgVxR1BnhLMhrhN4G9wPeo3QFcaxgN9bug19Ywavs0MAIsj9pl4FJbA1g7MAAcz0WTwCFJU0mo7VXAfSB/az0DTkqagYTVt70JGCsAB8IM/95sdc3U9n7gBpVTtFCzwBlJj/PP10TD8eujsifj+gwcljRR9F5VNBy7IWBXLnoDHJP0rdq7hd/U9jrgZQE4BOypBRbO1HYJuAssi9pzwEVJt2th1dBu4CywKOpNA0cljdcDFqGdufF74IikL/WCUHufDgNdqSAUr/480C8p/2+qu1qBT9G4DPQ0Ay6gl4EJYBwoScr/lpPrD6BJpvVDUJEtAAAAAElFTkSuQmCC) no-repeat 15px center rgba(0,0,0,0.4);-webkit-border-radius:190px 0 0 190px;-moz-border-radius:190px 0 0 190px;border-radius:190px 0 0 190px}
		#viewbar button#bnext:hover{opacity:1}
		#viewbar button#bcom{left:6px;top:104px;width:48px;height:48px;background:url(data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAADAAAAAqCAYAAAD1T9h6AAAABHNCSVQICAgIfAhkiAAAAAlwSFlzAAAHoAAAB6ABnZaTqAAAABl0RVh0U29mdHdhcmUAd3d3Lmlua3NjYXBlLm9yZ5vuPBoAAAKzSURBVFiF7ZlPa9RAGMafdxJ2k1IoPVmopz0rRf0AWi+9eCiC3nqw36DsJtkVcdnq1tlspYKg4BfwUFAvRTxUCgU9VAq96MWFouBFUPAPmyLm9bDZ7dimNcl2SYX8IMxM3jczz7ObSeANSSmvA7gBwATAwQGlv3d8UH8QsV6fiBgAOICIdpj5rg7gJoB8kEzBcexg5l6fiLqtq2NX/HcAb7s52DUS1obGOj9MZ/Zuy8yRr48RGwNgABjRFYeb5XL5wn7vxw8p5RoRnQcAkbaYfskMpE1mIG0yA2mTGUibzEDaZAbSJjOQNpmBtMkMpE1mIG0yA2nz3xvoFbaEEAXXdWXcCZh53XGclSSLNxqNCSKaRqcuGxkhRKFbaiQppY8+66FCiHOWZW1Gza/X6+OaptWJaAb93QWsA3gK4HIfk4CZR6Lk1Wq14Xw+b+u6XgQw1M+aAEBEL/RCoXB1e3t7gplzcS5m5mcATgDYabfbrw/LXV5e1lqt1jXDMG6hU5jt8hXAbSJ6FVc8M7dt294itWwdFdd1TzLzx2C45jjO5EG5jUZjCkATwGnl9C8AD3K53Pzc3NyX2AIU9H+nhNITTEQvwxIWFhZOaZq2CGBqT+gJMzvlcvl9wrX/IpEBZr6o9FfVWLPZHPN9f17TtFkAmhLaEEIULctaTyY1nKQGJoPvFz9GR0c3AKBWqw2ZpllkZhvAsJL+AUDFcZzH2P1sdGTE3gNSygIRtYLhc8/zLpmmOcPMdQDjSuo3AHc8z7tXrVa9I9K7j9j/gBBiUjG9YxjGG2Y+o6T8BvDI9/1qpVL5fBQiDyO2AfX+BzC9J7xCRJZt2+/6kxWdJHsg7JG5RURF27ZXQ2IDJdZrfGlpyUTn5dXlExHNep53Ng3xQLJNfJ+IrhDRQyHEYqlU+jkgbZH4A1OS9uj03IqAAAAAAElFTkSuQmCC) no-repeat center center}
		#viewbar button#bcom span{position:absolute;left:0;top:4px;width:48px;text-align:center;font:bold 24px Baumans}
		#viewbar button#bcom:hover span{text-shadow:0 0 5px #fff}
		#viewbar button#bplay{left:6px;top:60px;width:48px;height:42px;background:url(data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAADAAAAAdCAYAAADsMO9vAAAABHNCSVQICAgIfAhkiAAAAAlwSFlzAAAILgAACC4B4ThkEQAAABl0RVh0U29mdHdhcmUAd3d3Lmlua3NjYXBlLm9yZ5vuPBoAAAJUSURBVFiF7Zg9aBRRFIW/OxkzE5VYKoqlin/YBH8KOwWFtGKlCBYahGWRZN9a6LLIxvc2jSESFAWx0pgyCFpqYSMWNpEIaQQLBVFEwyzqXgt3yRhm40ZI9gk5MPDuvefCOe8+5k+cczlVvQr04gfeAEeNMW/bIYeqOgysW15NS8J24CxQyipOTk52zc7O5kTkGHAj5E/xtRUQ2ApdQNhYb8giOOf2AXdEpK+R2hOm6jVjTLyMAheFtfaMiNzNqo2NjUVzc3NXgALzJgE2h1kNPmFkZORwvV6/DezIqntroFwu9/b09DhVPQdIMy8ij1X1CA3tQacELgYR2R/H8bSqnmde/EcROV0oFI4DP5tcLyegqofSsYhMBEGQGxwc/LCQ66WBFN4BA4VCYaoVwZsjJCLvU6ECt5Ik2WWMaSkePJpAkiRPoigaF5FtQRBUhoaGnrbT542BUqlUBy4stc+bI/SvWDXQaawa6DT+ewPe3EYBnHMDQHcURffy+fzndnq8mUC1Wj0FjAPXa7Xaa2vtiXb6vDGgqltS4SYReWitnapUKlsX6/PGQBZEpD8Mw+lqtZorl8uZWn01MAP8aKzXq+poHMfPnXN7FxK9NKCqD4A+4EUqfQB4aa0dJvWF5qUBAGPMqyRJDgJ54GsjvUZELgHdTZ63BuD3G6oxZhTYDTzK4qSfA5FzLlkRZdnoalVo/KXrt9aeFJFRYGOj9D0AvqS4UQev9GZ+yzJSLBYnVHWniNwEZkTkYigil1X1GrD2Lzu0UngWBMH9VsVisfgJGGjGvwD4Z6eH9jMo/QAAAABJRU5ErkJggg==) no-repeat center center}
		#viewbar button#bthumb{left:6px;top:6px;width:48px;height:48px;background:url(data:image/png;base64,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) no-repeat center center}
		#mask.active #loading{-webkit-animation:roty 4s linear 0s infinite;animation:roty 4s linear 0s infinite}
		@-webkit-keyframes roty{0%{-webkit-transform:perspective(0px) rotateY(0deg)}
		50%{-webkit-transform:perspective(0px) rotateY(180deg)}
		100%{-webkit-transform:perspective(0px) rotateY(360deg)}
		}
		@keyframes roty{0%{transform:rotateY(0deg)}
		50%{transform:rotateY(180deg)}
		100%{transform:rotateY(360deg)}
		}
		#blogin{background:url(data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAABAAAAAQCAYAAAAf8/9hAAAAxElEQVQ4y62TzQnCQBCFvxgR9OjJFOFZ7MA+LCZXTzlZRhpIBSkjVqAkKM/LLFk2uySgAw+W9+aPmVkkEcFRUi2pM9TGTXxjwWdJvabWmzaboLWAStLeUBnXhv6ZJAJ7AzmwA17GbYEn8AHWvnMsgSOyJfyKH+2vCW5em65lH8564BqbQQ9sFhZ+AEXYQRjcACdDE2iHWAfhOgqr5AK6QM/mhpgn3ou3cE+8SR2SYvqc5p/lEBnkJVF4GJc9foxSy610cV85fwGraMAymgAAAABJRU5ErkJggg==) no-repeat center center}
		#blogout{background:url(data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAABAAAAAQCAYAAAAf8/9hAAAAyklEQVQ4y6WTPQ6CQBCFP4RgtLTSQ1gbj+GpaKmoPAaFLSfgGHgCCUbzbIZIhkVIfMkLkzc/OzvDIokAj5JKSY2xNG0UG0o+S+o0Rme+2QK1JRSSdsbCtNrHR5JweAExsAVa0zbAA3gDyTA4YYzYvu1Aa4FbIDbYQS9ELMCKPzEskA9O7zuZYh66QgekCw9+AmvfgU+ugJOxcr401IGf5gG4m70HGr+AuSHGE/biLVwn7Mn/QCH/nC9xk/WDvPzYgpX+PoxMy5H1eR+ZgAV+ebxfxgAAAABJRU5ErkJggg==) no-repeat center center}
		#bmkdir{background:url(data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAABAAAAAQCAYAAAAf8/9hAAAAbklEQVQ4y82SsQ2AMAwEP9nHrO4ZWMGR6GlZ4mlAshAB2aHgmlR+3ccGfgHJjR3eZuvxLg/hPVYf0BLi8yf1C0kDIMn5VgHogIAWkgLAkgHT+dPGOOa3kKmhfteSMJDrwVhU31eI1tC7k5W0/gg7LswSYhfRqGwAAAAASUVORK5CYII=) no-repeat center center}
		#bupload{background:url(data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAABAAAAAQCAYAAAAf8/9hAAAAMUlEQVQ4y2NgoBAw4pL4////fxSFjIxY1TJR6oJhYAAjtgAjWjMjI+NwCYPRhDTAAADHAQwaCez5sAAAAABJRU5ErkJggg==) no-repeat center center}
		#bflush{background:url(data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAABAAAAAQCAYAAAAf8/9hAAAA6UlEQVQ4y8VTMQrCQBCcBZuADxB8gJ2VjQ8QBFtBCFj5A0Gw8hmCrRCw8wH22spJCh8gCCIELCzHZoVF9xI7B44jM5m5ZHcP+BdIjgBAImICoAegodQVwE5EnqovAAxFpO2ZpyQLfqNQba7PwTOvjSEnudKVO4Hh0zxT4UEydcI30QCSCcm7Cn3HPCn9ApIDJfeOeUwfAQBq+l5L94PTlBOAjsM/bUAUInIs098BZ927PwxQCqAOYCsiN1vEIlZEY+6bmUhibSwibUxNp2axEzJbZZJLXcHwWdU/lo3y18lVl6mp1MVeJosXNXlhRj/slNwAAAAASUVORK5CYII=) no-repeat center center}
		#bdel{background:url(data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAABAAAAAQCAYAAAAf8/9hAAAAlElEQVQ4y62TQQqAMAwERU9Cn1QQfLNQEISAf/I6HqwQ2hiquNewA9lsuu5PAROwAKMxG4EERM98cGnTkGze8+yoIMCszGhIYUZBJg1I2BLDfCuV+wntkiqnFxCxQm6FVObe4AzOpQevA8EJTGu39m812xBg/XDGVQOiUSTJoYaHIkWvylJUObhVLiDp4ZmC+0xfdQIKxC/UtqjJOgAAAABJRU5ErkJggg==) no-repeat center center}
		#bdiag{background:url(data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAABAAAAAQCAYAAAAf8/9hAAAArElEQVQ4y9XTPweCURgF8F4RERGtERGt0feIvlNTU2v0DVpbI5oimlqjKaI14tdyU+77lv5o6Iz3nnPuc57nPgly/wPk0fnGYIwzep+IR244oZtZ4gPxUBqTmNTEJs6IQYZ4isI9qYFduDyiHc77GeIZivHrs4h0iDJfMU+Jg0ENW8+xQOlZl+t3MWIsUX5lVI0MkxUq78y7hX0Qr1H95NO0QmOrP9+P5Nt1vgDt8kPZZD2OnAAAAABJRU5ErkJggg==) no-repeat center center}
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
	<p>Warning! This site is not compatible with your old browser.</p>
	<p>try it with chrome or firefox for a shiny experience</p>
</div>
<![endif]-->
	<noscript class="warning">
		Warning! You must enable Javascript to visit this site.
	</noscript>
	<div id="mask"><div id="loading"></div></div>
<?php if($withadm){ ?>
	<input type="file" id="iupload" accept="image/*" multiple/>
	<div id="godbar">
	<?php if($godmode){ ?>
		<button id="blogout"></button>
		<button id="bmkdir"></button>
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
	<div id="exif"></div>
	<div id="copyright"><a href="https://github.com/nikopol/walli">WALLi <?php print(VERSION); ?></a></div>
	<div id="help"></div>

	<!--[if IE]>
	<script>
		[].forEach||(Array.prototype.forEach=function(d,b,a){a=this;for(b=0;b<a.length;d(a[b],b++,a));});[].filter||(Array.prototype.filter=function(d,b,a,c){a=this;c=[];for(b=0;b<a.length;++b)d(a[b],b)&&c.push(a[b]);return c});[].indexOf||(Array.prototype.indexOf=function(d,b,a,c){c=this;for(a=b||0;a<c.length;++a)if(c[a]==d)return a;return-1});
	</script>
	<![endif]-->

	<script>
		(function(k){var h=k.document,m=function(a){return a instanceof Array?a:[a]};k._=function(a,c){var b,e,f;if("object"==typeof a)b=a;else if(a.length)if("#"!=a[0]||/[ \.\>\<]/.test(a))for(e=h.querySelectorAll(a),b=[],f=0;f<e.length;++f)b.push(e[f]);else b=h.getElementById(a.substr(1));b&&void 0!=c&&m(b).forEach(function(a){a.innerHTML=c});return b};k.append=function(a,c){var b=_(a);b&&m(b).forEach(function(a){var b=h.createElement("div");for(b.innerHTML=c;b.childNodes.length;)a.appendChild(b.childNodes[0])});return b};k.css=function(a,c){var b=_(a),e,f;if(b){if(void 0==c)return b instanceof Array?b:b.className;"object"==typeof c?m(b).forEach(function(a){for(e in c)a.style[e]=c[e]}):/^([\+\-\*])(.+)$/.test(c)?(e=RegExp.$1,c=RegExp.$2,m(b).forEach(function(a){f=a.className.split(/\s+/).filter(function(a){return a});"-"!=e&&-1==f.indexOf(c)?f.push(c):"+"!=e&&(f=f.filter(function(a){return a!=c}));b.className=f.join(" ")})):m(b).forEach(function(a){a.className=c});return b}};k.ajax=function(a,c){"string"==
		typeof a&&(a={url:a,ok:c});var b=a.type||"GET",e=a.url||"",f=a.contenttype||"application/x-www-form-urlencoded",k=a.datatype||"application/json",d=new window.XMLHttpRequest,h,g,l;if(a.data){if("string"==typeof a.data)g=a.data;else if(/json/.test(f))g=JSON.stringify(a.data);else{g=[];for(l in a.data)g.push(encodeURIComponent(l)+"="+encodeURIComponent(a.data[l]));g=g.join("&")}/GET|DEL/i.test(b)&&(e+=/\?/.test(e)?"&"+g:"?"+g,g="")}a.error||(a.error=function(a,b){console.error(a,b)});a.ok||(a.ok=function(){});
		d.onreadystatechange=function(){if(4==d.readyState)if(h&&clearTimeout(h),/^2/.test(d.status)){g=d.responseText;if(/json/.test(k))try{g=JSON.parse(d.responseText)}catch(b){return a.error("json parse error: "+b.message,d)}a.ok(g,d)}else a.error(d.responseText,d)};d.open(b,e,!0);d.setRequestHeader("Content-Type",f);if(a.headers)for(l in a.headers)d.setRequestHeader(l,a.headers[l]);a.timeout&&(h=setTimeout(function(){d.onreadystatechange=function(){};d.abort();a.error&&a.error("timeout",d)},1E3*a.timeout));
		d.send(g);return d};k.position=function(a){return(a=_(a))?(a=a.getBoundingClientRect(),{left:a.left+window.pageXOffset,top:a.top+window.pageYOffset,width:a.width,height:a.height}):!1};k.ready=function(a){/complete|loaded|interactive/.test(h.readyState)?a():h.attachEvent?h.attachEvent("ondocumentready",a()):h.addEventListener("DOMContentLoaded",function(){a()},!1)}})(window);
	</script>
	<script>
		var hash=function(){var b,c,d=function(a){return a.replace(/%20/g," ").replace(/%23/,"#")},e=function(){var a=[],d;for(d in b)a.push(d+"="+b[d]);c=a.join("|");document.location.hash="#"+c.replace(/ /g,"%20").replace(/#/,"%23");return!0},h=function(){b={};c=d(document.location.hash.substr(1));c.length&&c.split("|").forEach(function(a){a=a.split("=");1<a.length&&(b[a.shift()]=a.join("="))})};h();return{del:function(a){return a in b?(delete b[a],e(b)):!1},set:function(a,d){return e("object"==typeof a?b=a:b[a]=d)},get:function(a){return void 0==a?b:d(b[a]||"")},onchange:function(a){window.onhashchange=function(){d(document.location.hash.substr(1))!=c&&(h(),a&&a())}}}}(),hotkeys=function(){var b=!1,c={ESC:27,TAB:9,SPACE:32,RETURN:13,ENTER:13,BACKSPACE:8,BS:8,SCROLL:145,CAPSLOCK:20,NUMLOCK:144,PAUSE:19,INSERT:45,DEL:46,HOME:36,END:35,PAGEUP:33,PAGEDOWN:34,LEFT:37,UP:38,RIGHT:39,DOWN:40,F1:112,F2:113,F3:114,F4:115,F5:116,F6:117,F7:118,F8:119,F9:120,F10:121,F11:122,F12:123,"*":106,"+":107,"-":109,
		".":110,"/":111,";":186,"=":187,",":188,"`":192,"[":219,"\\":220,"]":221,"'":222},d={ALT:1,CONTROL:2,CTRL:2,SHIFT:4},e=[],h=function(a){a||(a=window.event);var b,c,f=String.fromCharCode(a.which||a.charCode).toUpperCase(),g=a.shiftKey*d.SHIFT|a.ctrlKey*d.CTRL|a.altKey*d.ALT;for(b in e)if(c=e[b],!(a.which!=c.key&&f!=c.key||g!=c.mask||!c.glob&&/INPUT|SELECT|TEXTAREA/.test(document.activeElement.tagName)))return c.fn(a),a.stopPropagation(),a.preventDefault(),!1;return!0};return{clear:function(){document.onkeydown=
		null;b=!1;e=[];return this},add:function(a,k,l){var f=0,g=0;"string"==typeof a&&(a=[a]);a.forEach(function(a){"string"==typeof a&&("+"==a?["+"]:a.toUpperCase().split("+")).forEach(function(a){d[a]?f|=d[a]:g=c[a]?c[a]:a[0]});g?(e.push({key:g,fn:k,glob:l,mask:f||0}),b||(document.onkeydown=h,b=!0)):console.error("hotkey "+a+" unknown")});return this}}}(),browser=function(){var b={},c=navigator.userAgent;/MSIE\s([\d\.]+)/.test(c)&&(b.IE=parseFloat(RegExp.$1));c.replace(/\s\(.+\)/g,"").split(" ").forEach(function(c){/^(.+)\/(.+)$/.test(c)&&
		(b[RegExp.$1]=parseFloat(RegExp.$2))});return b}(),htmlencode=function(b){return b.replace(/&/g,"&amp;").replace(/</g,"&lt;").replace(/>/g,"&gt;").replace(/"/g,"&quot;")};
	</script>
	<script>
		var locales={en:{title:{bnext:"next",bprev:"previous",bcom:"comments",bthumb:"thumbnail",bplay:"slideshow",bupload:"upload images",bflush:"reset cache",bmkdir:"new folder",bdiag:"diagnostic",bdel:"delete selected images"},holder:{who:"enter your name\u2026",what:"enter your comment\u2026"},text:{loading:"loading\u2026"},date:{now:"now",min:"%d minute%s ago",hour:"%d hour%s ago",yesterday:"yesterday",day:"%d day%s ago",week:"%d week%s ago",month:"%d month%s ago"},help:{th:["keys","thumbnail mode","show mode"],tr:[["? | H","toggle this panel","toggle this panel"],["SPACE | ENTER","show the current selection","toggle slideshow on/off"],["HOME","select the first image","show the first image"],["END","select the last image","show the last image"],["ARROWS","change the selected image","show the previous/next image"],["ESC","go to the previous directory","back to the thumbnail mode"],["C","","toggle comments panel"],["T","","show image filename"],["I","","show image informations"],["+ | -","","change the slideshow timer"]]},
		bdel:"&#10006;",nocom:"be the first to comment",emptywho:"what's your name ?",emptywhat:"say something\u2026",play:"&#9654; PLAY",stop:"&#9632; STOP",dlall:"download all",dlsel:"download selected",zip:"compressing\u2026",load:"loading\u2026",nozip:"nothing to download",updir:"",uploadfiles:"upload %nb image%s (%z bytes) ?",flushed:"%nb file%s flushed",uploaded:"%nb file%s uploaded",deleted:"%nb file%s deleted",mkdir:"folder name ?",delay:"slideshow set to %ss",exifnotfound:"no information available"},
		fr:{title:{bnext:"suivante",bprev:"pr\u00e9c\u00e8dente",bcom:"commentaires",bthumb:"miniatures",bplay:"diaporama",bupload:"ajoute des images",bflush:"vide le cache",bmkdir:"nouveau dossier",bdiag:"diagnostique",bdel:"efface les images s\u00e9lectionn\u00e9es"},holder:{who:"entrez votre nom\u2026",what:"entrez votre commentaire\u2026"},text:{loading:"chargement\u2026"},date:{now:"a l'instant",min:"il y a %d minute%s",hour:"il y a %d heure%s",yesterday:"hier",day:"il y a %d jour%s",week:"il y a %d semaine%s",
		month:"il y a %d mois"},help:{th:["touches","mode miniatures","mode image"],tr:[["? | H","affiche/cache cette aide","affiche/cache cette aide"],["ESPACE | ENTR\u00c9E","affiche la s\u00e9lection","des/active le diaporama"],["D\u00c9BUT","s\u00e9lectionne la premi\u00e8re image","affiche la premi\u00e8re image"],["FIN","s\u00e9lectionne la derniere image","affiche la derniere image"],["FL\u00c8CHES","change la s\u00e9lection","affiche l'image suivante/pr\u00e9c\u00e8dente"],["\u00c9CHAP","retourne au r\u00e9pertoire pr\u00e9c\u00e8dent",
		"retourne au mode miniatures"],["C","","affiche/cache les commentaires"],["T","","affiche le nom de l'image"],["I","","affiche des informations sur l'image"],["+ | -","","change le d\u00e9lai du diaporama"]]},bdel:"&#10006;",nocom:"soyez le premier \u00e0 laisser un commentaire",emptywho:"de la part de ?",emptywhat:"dites quelque chose\u2026",play:"&#9654; LECTURE",stop:"&#9632; STOP",dlall:"tout t\u00e9l\u00e9charger",dlsel:"t\u00e9l\u00e9charger la s\u00e9lection",zip:"compression\u2026",load:"chargement\u2026",
		nozip:"rien \u00e0 t\u00e9l\u00e9charger",updir:"",uploadfiles:"poster %nb image%s (%z octets) ?",flushed:"%nb fichier%s supprim\u00e9%s",uploaded:"%nb image%s ajout\u00e9e%s",deleted:"%nb image%s effac\u00e9e%s",mkdir:"nom du dossier ?",delay:"le diaporama passe \u00e0 %ss",exifnotfound:"aucune information disponible"}},loc,setlocale=function(m){var b,w;loc=locales[m]?locales[m]:locales.en;loc.reldate=function(e){var g=(new Date).getTime();e=(new Date(e)).getTime();g=(g-e)/1E3;e=function(e,l){l=
		Math.round(l);return e.replace("%d",l).replace("%s",1<l?"s":"")};return 60>g?loc.date.now:3600>g?e(loc.date.min,g/60):86400>g?e(loc.date.hour,g/3600):172800>g?loc.date.yesterday:604800>g?e(loc.date.day,g/86400):2592E3>g?e(loc.date.week,g/604800):e(loc.date.month,g/2592E3)};loc.size=function(e){return 2048>e?e+"b":1E6>e?Math.round(e/1024)+"kb":1E9>e?Math.round(e/1E6)+"M":Math.round(e/1E9)+"G"};loc.tpl=function(e,g){var b=loc[e],l;for(l in g)b=b.replace(RegExp("%"+l,"g"),g[l]),"nb"==l&&(b=b.replace(RegExp("%s",
		"g"),1<g.nb?"s":""));return b};if(loc.title)for(b in loc.title)(w=_("#"+b))&&w.setAttribute("title",loc.title[b]);if(loc.holder)for(b in loc.holder)(w=_("#"+b))&&w.setAttribute("placeholder",loc.holder[b]);if(loc.text)for(b in loc.text)_("#"+b,loc.text[b]);m="<table><tr><th>"+loc.help.th.join("</th><th>")+"</th></tr>";for(b in loc.help.tr)m+="<tr><td>"+loc.help.tr[b].join("</td><td>")+"</td></tr>";_("#help",m)};ready(function(){setlocale(hash.get("lang")||navigator.language)});
		var log=function(){var m,b,w=(new Date).getTime(),e={debug:1,info:2,warn:3,error:4},g=hash.get("log"),B=g&&e[g]?e[g]:0;if(!B)return{debug:function(){},info:function(){},warn:function(){},error:function(){}};console.log?m=function(l,b){if(e[l]>=B){var g,m=("     "+l).substr(-5)+"|";for(g in b)console.log(m+b[g])}}:(ready(function(){append(document.body,'<div id="log"></div>');b=_("#log")}),m=function(l,g){if(b&&e[l]>=B){var m,Q=("000000"+((new Date).getTime()-w)).substr(-6);for(m in g)append(b,'<div class="'+
		l+'"><span class="timer">'+Q+"</span>"+g[m].replace(" ","&nbsp;")+"</div>");b.scrollTop=b.scrollHeight}});return{debug:function(){m("debug",arguments)},info:function(){m("info",arguments)},warn:function(){m("warn",arguments)},error:function(){m("error",arguments)}}}(),osd;
		osd=function(){var m,b,w,e,g,B,l=!1;ready(function(){m=_("#osd");b=_("#progress")});return{hide:function(){l&&(l=clearTimeout(l));css(m,"-active")},show:function(){css(m,"+active")},error:function(e){log.error(e);osd.info(e,"error",5E3)},info:function(e,b,g){_(m,e).className=b||"";l&&clearTimeout(l);osd.show();l=setTimeout(osd.hide,g||3E3)},loc:function(e,b){osd.info(loc.tpl(e,b))},start:function(g,l,m){w=m||"%v/%m";e=g;B=l;e&&(css(b,"+active"),osd.set(0))},set:function(l,m){m&&m!=e&&(e=m,css(b,"+active"));
		g=l;_("#progresstext",w.replace(/%v/,l).replace(/%m/,e));l>=e&&(css(b,"-active"),B&&B());_("#progressbar").style.width=e?Math.floor(position("#progress").width*l/e)+"px":0},inc:function(){osd.set(++g)}}}();var walli;
		walli=function(){function m(){var a=_("#thumbbar"),d=position(a),a=window.getComputedStyle(a);_("#diapos").style.top=d.top+d.height+parseInt(a.getPropertyValue("marginBottom"))+"px"}function b(a,d){H&&(_("#bzip",d).className=a,m())}function w(a,d){var p=h[a],c,q=Date.now();c=new Image;U++;c.onload=function(c){c||(c=window.event);log.info("image #"+a+" "+p+" loaded in "+(Date.now()-q)/1E3+"s");d(a,c.target||c.srcElement);U--};c.onerror=function(){log.error("error loading "+("image #"+a+" "+p));d(a,
		null);U--};c.src=encodeURIComponent(p)}function e(a){a=/([^\/]+)\/$/.test(a)?RegExp.$1:a.replace(/^.*\//g,"");return a.replace(/\.[^\.]*$/,"").replace(/[\._\|]/g," ")}function g(a){return 999<a?Math.floor(a/1E3)+"K+":a}function B(){V&&!F&&(F=setInterval(function(){log.debug("refresh required");ajax("?!=count&path="+s,function(a){I.length==a.dirs&&h.length==a.files||l(s)})},1E3*V))}function l(a,d){F&&(clearInterval(F),F=!1);log.debug("loading path "+(a||"/"));b("hide",loc.dlall);x&&(_("#bdel").className=
		"hide");A=[];osd.hide();ajax("?!=ls&path="+a,function(a){var c=_("#diapos","");s=a.path;log.info((s||"/")+"loaded with "+a.dirs.length+" subdirs and "+a.files.length+" files found");if(s.length){var q=s.replace(/[^\/]+\/$/,"/"),R=document.createElement("li");css(R,"diapo up loaded");R.setAttribute("title",loc.updir);R.onclick=function(){l(q)};c.appendChild(R);var e="",g="";s.split("/").forEach(function(a){a&&(e+=a+"/",g+="<button onclick=\"walli.cd('"+e+"')\">"+htmlencode(a)+"</button>")});_("#path",
		g);m()}else _("#path","");h=a.files;I=a.dirs;G=a.coms;d&&d();v.layout.build();a.files.length&&H&&b("all");W();B();x&&_("#diag")&&walli.diag();J[s]&&C(0,0)})}function z(a,d){if(X[a]){var p=t.clientWidth,c=t.clientHeight,q=X[a],e=q.h,g=q.w;g>p&&(g=p,e=Math.floor(q.h/q.w*g));e>c&&(e=c,g=Math.floor(q.w/q.h*e));css(k[a],{width:g+"px",height:e+"px",left:Math.floor((p-g)/2+p*d*2)+"px",top:Math.floor((c-e)/2)+"px"})}}function ca(){S&&(K&&clearTimeout(K),K=setTimeout(walli.next,1E3*L))}function Q(a){L+=a;
		0>L&&(L=0);osd.loc("delay",{s:L})}function da(){css(document.body,"-sleep");document.body.onmousemove=document.body.ontouchstart=function(a){if(a.x!=ea||a.y!=fa)ea=a.x,fa=a.y,da()};D&&clearTimeout(D);D=setTimeout(la,1E3*ma)}function la(){D&&(D=clearTimeout(D));css(document.body,"+sleep")}function Y(a){S!==a&&((S=a)?(ca(),a=document.documentElement,a.requestFullscreen?a.requestFullscreen():a.mozRequestFullScreen?a.mozRequestFullScreen():a.webkitRequestFullScreen&&a.webkitRequestFullScreen(),css("#bplay",
		"+active"),css("#view","+play"),osd.loc("play"),da()):(K&&(K=clearTimeout(K)),D&&(D=clearTimeout(D)),document.body.onmousemove=document.body.ontouchstart=!1,css(document.body,"-sleep"),css("#bplay","-active"),css("#view","-play"),osd.loc("stop")))}function E(a){Z!==a&&(Z=a,log.debug("switch to "+a+" mode"),r=!0,"tof"==Z?(F&&(clearInterval(F),F=!1),css(M,"+active"),css("#thumb","-active")):(r=!1,Y(!1),osd.hide(),css(k[0],""),css(k[1],""),css(M,"-active"),css("#exif","-active"),css("#thumb","+active"),
		B()),W())}function ga(a){var d=_("#diapo"+n).parentNode,p=_("#minicom"+n);p&&d.removeChild(p);0<a&&append(d,'<span id="minicom'+n+'" class="minicom">'+a+"</span>")}function $(a,d){if(d||r&&T!==a)T=a,r&&(css(k[1-f],""),z(1-f,2)),a?(css("#bcom","+active"),css(M,"+com"+(d?"fix":"")),hash.set("com",1)):(css("#bcom","-active"),css(M,"-com"),css(M,"-comfix"),hash.del("com")),r&&setTimeout(function(){z(f,0)},550)}function aa(a){var d="",p=G[a];p&&p.length?(p.forEach(function(c,q){d+="<li><header>"+c.who+
		' <span title="'+c.when.replace("T"," ")+'">'+loc.reldate(c.when)+"</span></header><content>"+c.what.replace("\n","<br/>")+"</content>"+(c.own?'<button class="del" onclick="walli.rmcom(\''+a.replace("'","\\'")+"',"+c.id+')">'+loc.bdel+"</button>":"")+"</li>"}),_(O,d),O.scrollTop=O.scrollHeight,_("#comcount",g(p.length))):(_(O,loc.nocom),_("#comcount","0"))}function na(a,d){ajax({type:"POST",url:"?!=comment",data:{file:h[n],who:a,what:d},ok:function(a){G[a.file]=a.coms;aa(h[n]);ga(a.coms.length);N.value=
		""},error:osd.error})}function oa(a,d){ajax({type:"POST",url:"?!=uncomment",data:{file:a,id:d},ok:function(a){G[a.file]=a.coms;aa(h[n]);ga(a.coms.length)},error:osd.error})}function y(a){a&&a.stopPropagation&&a.stopPropagation()}function W(){r?hash.set("f",h[n]):s?hash.set("f",s):hash.del("f")}function ha(){var a=hash.get("f"),d=hash.get("com"),e=/^(.+\/)([^\/]*)$/.test(a)?RegExp.$1:"/",c=RegExp.$2;d?$(!0,!0):T&&$(!1,!0);return a.length?(d=function(){var d=h.indexOf(a),c=0;r&&d!=n&&(c=d<n?-1:1);-1!=
		d?walli.show(d,c):E("thumb")},e!=s?l(e,d):c?d():E("thumb"),!0):!1}function C(a,d,e){if(!r){var c=J[s]?J[s]:{x:0,y:0,n:0};e&&(c.x=a,c.y=d);c.o&&css(c.o,"-cursor");var q=_("#diapos li"),g=_("#diapos"),l=g.getBoundingClientRect(),b=[],f=!1,m=-1,h,k,n;if(q.length){for(;++m<q.length;)if(h=q[m].getBoundingClientRect(),h={t:h.top-l.top+g.scrollTop,w:h.width,h:h.height},h.w){k=Math.round(h.t+h.h/2);if(!1===f||k>f)f=k,b.push([]),n=b.length-1;b[n].push({t:h.t,b:h.t+h.h,n:m});e||c.n!=m||(c={x:a+b[n].length-
		1,y:d+n,n:m})}c.y>n?c.y=0:0>c.y&&(c.y=n);c.x>=b[c.y].length?c.x=0:0>c.x&&(c.x=b[c.y].length-1);k=b[c.y][c.x];c.o=q[k.n];c.n=k.n;h={t:g.scrollTop,b:g.scrollTop+l.height,h:l.height};n=h.t;k.b>h.b?n=k.b-h.h+30:k.t<h.t&&(n=k.t-30);g.scrollTop=n;css(c.o,"+cursor")}J[s]=c}}function ia(){r&&osd.info(e(h[n])+" <sup>"+(n+1)+"/"+h.length+"</sup>")}var L=5,ma=3,K=!1,F=!1,D=!1,V,s,h=[],I=[],A=[],n=!1,f=0,k=[],X=[],M,P,N,U=0,S=!1,r,t,u={},G=[],T,Z,O,ja,x=!1,H=!0,ea=0,fa=0,J={},v,ba,ka={boxed:{make:function(){var a=
		v.size,d=v.margin,p=_("#diapos"),c=[0,a+2*d,2*a+4*d],q=[0,a,2*a+2*d],b=Math.floor(_(p).clientWidth/c[1]);1>b&&(b=1);if(b!=v.lastnc){v.lastnc=b;var f=0,m=0,k=[],n=I.length+h.length,r=function(a,d){var c,q;for(c=q=0;q<d;c+=1<<q++);return c<<a},s=function(a,d,c,q){for(c=r(a,c);k.length<d+q;)k.push(0);for(a=0;a<q;++a)if(k[d+a]&c)return!0;return!1},t=function(a,e,b,g,p){for(var f,m=r(e,g);k.length<b+p;)k.push(0);for(f=0;f<p;++f)k[b+f]|=m;css(a,{position:"absolute",margin:d,left:c[1]*e+"px",top:c[1]*b+
		"px",width:q[g]+"px",height:q[p]+"px"})},u=function(a,d,c,p){var k=function(){var c=document.createElement("img");c.onload=function(){osd.inc();log.debug(a+" loaded");css(this.parentNode,"+loaded")};c.onclick=d;return c}(),l=Math.random(),h=document.createElement("li");css(h,"diapo "+c);h.appendChild(k);h.setAttribute("title",e(a));void 0!=p&&(k.id="diapo"+p,(H||x)&&append(h,'<input type="checkbox" id="chk'+p+'" n="'+p+'" onchange="walli.zwap('+p+')"/><label for="chk'+p+'"></label>'));(G[a]||[]).length&&
		append(h,'<span class="minicom">'+g(G[a].length)+"</span>");diapos.appendChild(h);0==f&&0==m?(c=2,p=1<b?2:1):1<b-f&&0.5<l&&!s(f,m,2,1)?(c=0.8<l&&n>b?2:1,p=2):(c=0.7<l&&n>b?2:1,p=1);t(h,f,m,p,c);for(k.src="?!=mini&file="+encodeURIComponent(a)+"&w="+q[p]+"&h="+q[c];s(f,m,1,1);)f++,f>=b&&(f=0,m++);n--};_("#diapos","");osd.start(h.length+I.length);I.forEach(function(a){u(a,function(){l(a)},"dir")});h.forEach(function(a,d){setTimeout(function(){u(a,function(){walli.show(d,0)},"",d)},d)})}},build:function(){v.lastnc=
		0;this.make()},resize:function(){this.make()},rotate:function(){this.make()}},"default":{build:function(){var a=function(a,p,c,q){var b=function(){var c=document.createElement("img");c.onload=function(){osd.inc();log.debug(a+" loaded");css(this.parentNode,"+loaded")};c.onclick=p;return c}(),f=document.createElement("li");css(f,"diapo "+c);f.appendChild(b);f.setAttribute("title",e(a));void 0!=q&&(b.id="diapo"+q,(H||x)&&append(f,'<input type="checkbox" id="chk'+q+'" n="'+q+'" onchange="walli.zwap('+
		q+')"/><label for="chk'+q+'"></label>'));(G[a]||[]).length&&append(f,'<span class="minicom">'+g(G[a].length)+"</span>");diapos.appendChild(f);b.src="?!=mini&file="+encodeURIComponent(a)+"&w="+v.size+"&h="+v.size};osd.start(h.length+I.length);I.forEach(function(d){a(d,function(){l(d)},"dir")});h.forEach(function(d,b){setTimeout(function(){a(d,function(){walli.show(b,0)},"",b)},b)})},resize:function(){},rotate:function(){}}};return{setup:function(a){ja=a.comments;V=a.refresh;x=a.god;H=a.zip;v=a.thumbnail||
		{engine:"default",size:150,margin:0};v.layout=ka[v.engine]||ka["default"];ba=a.slider||"default";M=_("#view");O=_("#coms");t=_("#slide");css(t,"+"+ba);t.onmousewheel=function(a){r&&(0>(a.wheelDelta||a.detail/3)?walli.prev():walli.next(),a.preventDefault())};var d=function(){k[f].className="animated";k[f].style.left=u.l+"px";u={}};t.onmousedown=t.ontouchstart=function(a){a.preventDefault();a.touches&&(a=a.touches[0]);k[f].className="touch";u={c:u.c||0,d:!0,m:!1,x:a.pageX,l:parseInt(k[f].style.left,
		10),h:setTimeout(d,1E3)}};t.onmousemove=t.ontouchmove=function(a){u.d&&(u.m=!0,a.preventDefault(),a.touches&&(a=a.touches[0]),a=a.pageX-u.x,k[f].style.left=u.l+a+"px",80<Math.abs(a)&&(clearTimeout(u.h),u={},k[f].className="animated",80<a?walli.prev():walli.next()))};t.onmouseup=t.onmouseout=t.ontouchend=t.ontouchcancel=function(a){a.preventDefault();u.d&&(clearTimeout(u.h),u.c?(clearTimeout(u.c),d(),walli.toggleplay()):u.m?d():u.c=setTimeout(function(){d();walli.next()},250))};k=[_("#img0"),_("#img1")];
		t.ondragstart=k[0].ondragstart=k[1].ondragstart=function(a){a.preventDefault();return!1};window.onresize=function(){r&&(k[1-f].className="",z(1-f,1),z(f,0));m();v.layout.resize()};window.onorientationchange=function(){var a=_("#viewport"),d=window.orientation||0;a.setAttribute("content",90==d||-90==d||270==d?"height=device-width,width=device-height,initial-scale=1.0,maximum-scale=1.0":"height=device-height,width=device-width,initial-scale=1.0,maximum-scale=1.0");r&&z(f,0);v.layout.rotate()};hotkeys.add("CTRL+D",
		function(){css("#log","*active")},!0).add(["SPACE","ENTER"],walli.toggleplay).add("C",walli.togglecom).add("HOME",walli.first).add("LEFT",walli.prev).add("RIGHT",walli.next).add("UP",walli.up).add("DOWN",walli.down).add("PAGEUP",walli.pgup).add("PAGEDOWN",walli.pgdown).add("END",walli.last).add(["ESC","BACKSPACE"],walli.back).add("DOWN",function(){!r&&h.length&&walli.show(0)}).add(["?","H"],function(){css("#help","*active")}).add(["T"],ia).add(["I"],walli.togglexif).add(["+"],function(){Q(1)}).add(["-"],
		function(){Q(-1)});_("#bprev").onclick=walli.prev;_("#bnext").onclick=walli.next;_("#bplay").onclick=walli.toggleplay;_("#bthumb").onclick=walli.thumb;_("#bzip").onclick=walli.dlzip;H||(_("#bzip").className="hide");ja&&(P=_("#who"),N=_("#what"),N.onfocus=P.onfocus=function(){Y(!1)},_("#comments").onclick=y,_("#bcom").onclick=walli.togglecom,_("#bsend").onclick=walli.sendcom);x?(_("#blogout").onclick=walli.logout,FormData?(_("#iupload").onchange=function(a){a=new FormData;for(var d=0,c=this.files,
		b,e=0;e<c.length;++e)b=c[e],d+=b.size,a.append("file"+e,b);if(confirm(loc.tpl("uploadfiles",{z:loc.size(d),nb:c.length}))){var f=new XMLHttpRequest;f.open("POST","?!=img&path="+s);f.onload=function(){if(200==f.status){var a=JSON.parse(f.responseText);a.added&&l(s);osd.loc("uploaded",{nb:a.added})}else osd.error("error "+f.status)};f.upload.onprogress=function(a){event.lengthComputable&&osd.set(a.loaded,a.total)};f.send(a)}},_("#bupload").onclick=function(){_("#iupload").click()}):css("#bupload",{diplay:"none"}),
		_("#bdel").onclick=walli.del,_("#bdiag").onclick=walli.togglediag,_("#bflush").onclick=walli.flush,_("#bmkdir").onclick=walli.mkdir):a.admin&&(_("#blogin").onclick=walli.login);log.info("show on!");var b=_("#intro");if(b){var c=setTimeout(function(){css(b,"hide")},5E3);b.onclick=function(){clearTimeout(c);css(b,"hide")}}ha()||(E("thumb"),l("/"));hash.onchange(ha)},login:function(){document.location="?login"+document.location.hash},logout:function(){document.location="?logout"+document.location.hash},
		del:function(){if(x){var a=h.filter(function(a,b){return-1!=A.indexOf(b)});A.length?ajax({type:"POST",url:"?!=del",data:{files:a.join("*")},ok:function(a){a.deleted&&l(s);osd.loc("deleted",{nb:a.deleted})},error:osd.error}):osd.error(loc.noselection)}},togglexif:function(){css("#exif","*active");walli.exif()},exif:function(){var a=_("#exif.active");a.length&&(_(a,loc.load),ajax({url:"?!=exif",data:{file:h[n]},ok:function(d){var b,c,e;if(d&&d.exif)for(c in b="",d.exif){b+="<div><h3>"+c+"</h3><table>";
		for(e in d.exif[c])null!=d.exif[c][e]&&""!=d.exif[c][e]&&(b+="<tr><th>"+e+"</th><td>"+d.exif[c][e]+"</td></tr>");b+="</table></div>"}else b=loc.exifnotfound;_(a,b)},error:function(d){_(a,d)}}))},togglediag:function(){var a=_("#diag");a?document.body.removeChild(a):walli.diag()},diag:function(){x&&ajax({url:"?!=diag",data:{path:s},ok:function(a){var d=_("#diag"),b="<ul>",c;for(c in a.stats)b+='<li class="stat">'+("size"==c?loc.size(a.stats[c]):a.stats[c]+" "+c)+"</li>";for(c in a.checks)b+='<li class="'+
		(a.checks[c]?"ok":"bad")+'">'+c+(a.checks[c]?" enabled":" disabled")+"</li>";d||(d=document.createElement("div"),d.id="diag",d.onclick=function(){document.body.removeChild(d)},document.body.appendChild(d));d.innerHTML=b},error:osd.error})},flush:function(){x&&ajax({url:"?!=flush",ok:function(a){osd.loc("flushed",{nb:a.flushed})},error:osd.error})},mkdir:function(){var a;x&&(a=prompt(loc.mkdir))&&ajax({type:"POST",url:"?!=mkdir",data:{dir:a,path:s},ok:function(){l(s)},error:osd.error})},dlzip:function(){var a=
		A.length?h.filter(function(a,b){return-1!=A.indexOf(b)}):h;H&&a.length?(_("#bzip",loc.zip),ajax({type:"POST",url:"?!=zip",data:{files:a.join("*")},ok:function(a){document.location="?!=zip&zip="+a.zip;walli.zwap()},error:osd.error})):osd.loc("nozip")},zwap:function(a){void 0!=a&&(-1==A.indexOf(a)?A.push(a):A=A.filter(function(d){return d!=a}));A.length?(b("selected",loc.tpl("dlsel",{nb:A.length})),x&&(_("#bdel").className="")):(b("all",loc.dlall),x&&(_("#bdel").className="hide"))},thumb:function(){E("thumb")},
		show:function(a,d,b){h.length&&(n=0>a?h.length+a:a>=h.length?a%h.length:a,r||E("tof"),css("#mask","+active"),w(n,function(a,b){css("#mask","-active");r?f=1-f:d=0;X[f]={w:b.width,h:b.height};t.removeChild(k[f]);k[f].src=b.src;if(d)"box"==ba?(css(k[f],0>d?"left":"right"),z(f,0),t.appendChild(k[f]),css(k[f],"animated center"),css(k[1-f],"animated "+(0<d?"left":"right")),z(1-f,0)):(css(k[f],0>d?"left":"right"),z(f,d),t.appendChild(k[f]),z(f,0),css(k[f],"animated center"),css(k[1-f],"animated "+(0<d?"left":
		"right")),z(1-f,-d));else{css(k[f],"");var e=position("#diapo"+n);css(k[f],{width:e.width+"px",height:e.height+"px",left:e.left+"px",top:e.top+"px"});t.appendChild(k[f]);css(k[f],"animated");z(f,0)}ca();W();ia();walli.exif();1<h.length&&w((n+1)%h.length,function(){})}),aa(h[n]))},next:function(a){y(a);r?walli.show(n+1,1):C(1,0)},prev:function(a){y(a);r?walli.show(n-1,-1):C(-1,0)},down:function(a){y(a);r?walli.show(n+1,1):C(0,1)},up:function(a){y(a);r?walli.show(n-1,-1):C(0,-1)},pgdown:function(a){y(a);
		r?walli.show(n+5,1):C(0,5)},pgup:function(a){y(a);r?walli.show(n-5,-1):C(0,-5)},first:function(a){y(a);r?walli.show(0,-1):C(0,0,!0)},last:function(a){y(a);r?walli.show(-1,1):C(-1,-1,!0)},play:function(a){y(a);h.length&&(r||walli.show(n,0),E("tof"))},stop:function(a){y(a);E("thumb")},toggleplay:function(a){y(a);r?Y(!S):(J[s]||C(0,0,!0),J[s].o&&J[s].o.children[0].click(a))},togglecom:function(a){a&&a.stopPropagation();$(!T)},sendcom:function(){1>P.value.length?(osd.loc("emptywho"),P.focus()):1>N.value.length?
		(osd.loc("emptywhat"),N.focus()):na(P.value,N.value)},rmcom:function(a,b){oa(a,b)},back:function(){if(r)return E("thumb");var a=s.split("/");1<a.length&&l(a.slice(0,a.length-2).join("/"))},cd:function(a){E("thumb");l(a)}}}();
	</script>
	<script>
		ready(function(){
			walli.setup({
				refresh: <?php print($REFRESH_DELAY) ?>,
				comments: <?php print($withcom?'true':'false') ?>,
				admin: <?php print($withadm?'true':'false') ?>,
				zip: <?php print($withzip?'true':'false') ?>,
				god: <?php print($godmode?'true':'false') ?>,
				thumbnail: <?php print(json_encode($THUMB)) ?>,
				slider: <?php print($SLIDER?"'$SLIDER'":'default') ?> 
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