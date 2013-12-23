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
		@import url(http://fonts.googleapis.com/css?family=Satisfy);
		*{margin:0;padding:0;outline-style:none}
		body{overflow:hidden;-webkit-font-smoothing:subpixel-antialiased !important;background:#fff url(data:image/jpg;base64,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) repeat;font:normal 16px "Satisfy";color:#aaa}
		body.sleep{cursor:none}
		a,a:visited{text-decoration:none}
		input[type="text"],textarea,select{width:380px;font:normal 16px "Satisfy";border:none;background-color:transparent;color:#666;padding:0}
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
		#osd{display:none;position:absolute;top:0;left:0;right:0;height:40px;z-index:0;color:#fff;font:bold 30px Arial;opacity:0;text-align:center;padding:0 10px;background-color:rgba(0,0,0,0.2);-webkit-transition:all 0.5s ease;-moz-transition:all 0.5s ease;transition:all 0.5s ease;font:bold 30px Satisfy;text-shadow:0 4px 3px rgba(0, 0, 0, 0.4), 0 8px 10px rgba(0, 0, 0, 0.1), 0 8px 9px rgba(0, 0, 0, 0.1)}
		#osd sup{position:absolute;top:0;left:0;right:0;height:40px;z-index:1;color:#ccc;font:bold 20px Arial;text-align:right;margin-right:5px;font:normal 16px Arial;text-shadow:none;color:#fff}
		#osd.active{display:block;z-index:5;opacity:1;-webkit-transition:opacity 0.2s ease-in-out;-moz-transition:opacity 0.2s ease-in-out;transition:opacity 0.2s ease-in-out}
		#osd.error{color:#f77}
		#progress{display:none;position:absolute;left:0;bottom:0;height:25px;right:0}
		#progress.active{display:block;z-index:50;background-color:rgba(0,0,0,0.2);background-color:rgba(0,0,0,0.3)}
		#progressbar{position:absolute;height:25px;background-color:rgba(255,255,255,0.2);z-index:51;background-color:rgba(0,0,0,0.5)}
		#progresstext{position:absolute;width:100%;font-size:22px;text-align:center;color:#eee;z-index:52}
		#thumb{position:absolute;top:0;bottom:0;left:0;right:0;opacity:0;z-index:0;overflow:auto;padding:5px 10px 10px 10px;-webkit-transition:all 0.5s linear;-moz-transition:all 0.5s linear;transition:all 0.5s linear}
		#thumb.active{opacity:1;z-index:1;-webkit-transition:all 0.5s linear;-moz-transition:all 0.5s linear;transition:all 0.5s linear}
		#thumbbar{position:absolute;left:0;right:0;top:0;padding:5px 5px 0 20px;font-size:0;clear-after:both;height:34px;-moz-box-shadow:0px 5px 3px rgba(0, 0, 0, 0.2);-webkit-box-shadow:0px 5px 3px rgba(0, 0, 0, 0.2);box-shadow:0px 5px 3px rgba(0, 0, 0, 0.2);background:rgba(0,0,0,0.1);z-index:20}
		#thumbbar h1{font:bold 24px Arial;display:inline;margin:0 4px 0 -14px;padding:0 12px;cursor:default;font:bold 50px "Satisfy";height:20px;color:#fff;margin:0 15px 0 5px;line-height:60px;text-shadow:0 1px 0 #ccc, 0 2px 0 #c9c9c9,0 3px 0 #bbb, 0 4px 0 #b9b9b9,0 5px 0 #aaa, 0 6px 1px rgba(0,0,0,0.1),0 0 5px rgba(0, 0, 0, 0.1), 0 1px 3px rgba(0,0,0,0.3),0 3px 5px rgba(0, 0, 0, 0.2), 0 5px 10px rgba(0,0,0,0.25),0 10px 10px rgba(0, 0, 0, 0.2), 0 20px 20px rgba(0,0,0,0.15)}
		#thumbbar button{position:relative;display:inline;height:28px;font:normal 18px Arial;vertical-align:top;padding:0 5px;font:normal 18px Satisfy;color:#888;padding:1px 10px 0 5px;margin:0 4px 2px 2px;background:-webkit-gradient(linear, left top, left bottom, from(#fffc9f), to(#e0e080));background:-moz-linear-gradient(top, #fffc9f, #e0e080);background:linear-gradient(top, #fffc9f, #e0e080);-moz-box-shadow:-1px 2px 1px rgba(0, 0, 0, 0.2), -2px 1px 1px rgba(0, 0, 0, 0.1);-webkit-box-shadow:-1px 2px 1px rgba(0, 0, 0, 0.2), -2px 1px 1px rgba(0, 0, 0, 0.1);box-shadow:-1px 2px 1px rgba(0, 0, 0, 0.2), -2px 1px 1px rgba(0, 0, 0, 0.1)}
		#bzip.empty,#bzip.hide,#bzip.all{display:none}
		#path{display:inline}
		#diapos{position:absolute;top:40px;padding:11px 11px 0 11px;left:0;right:0;bottom:0;overflow:auto;padding:10px 5px 0 5px}
		li.diapo{position:relative;display:inline;float:left;width:150px;height:168px;overflow:hidden;text-align:center;vertical-align:bottom;margin:5px;padding:0;height:172px;margin:8px;background-color:#fff;padding:10px 10px 2px 10px;-webkit-box-shadow:5px 5px 5px 1px rgba(0,0,0,.5);-moz-box-shadow:5px 5px 5px 1px rgba(0,0,0,.5);box-shadow:5px 5px 5px 1px rgba(0,0,0,.5)}
		li.diapo img{display:block}
		li.diapo:after{content:attr(title)}
		li.diapo.up{display:none}
		li.diapo.loaded{-webkit-transition:all 0.4s linear;-moz-transition:all 0.4s linear;transition:all 0.4s linear;color:#666}
		li.diapo.loaded span.minicom{position:absolute;top:5px;right:5px;width:24px;height:21px;font-size:10px;line-height:17px;text-align:center;color:#fff;background:#666;top:16px;right:16px;font-size:10px;line-height:18px;font-weight:bold;background:transparent url(data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAABgAAAAVCAYAAABc6S4mAAAABHNCSVQICAgIfAhkiAAAAAlwSFlzAAAD0AAAA9ABSs1rUAAAABl0RVh0U29mdHdhcmUAd3d3Lmlua3NjYXBlLm9yZ5vuPBoAAAEDSURBVDiN7ZUxTsNAEEXfj30JTkGXiipF7kCLRI+EtIWF5M644AzcgYqKDgpOkBuEghIJu2AzFLsOjuI1RqL0l1Zj7cy+L89Ks6rr+hVYSjIAsxDiohf3+wO1+1zcI9a85MAyFqsH6n8fqYMMSTo4ul6Mgf5Ds8FsMBvMBhOUx7jjcGr2lY0BJPnE8BOwyM3sEjhLHD4fMfgidODBzD4SNc9Kjd6qqk6yLNsOpHw0fZR07ZzbJODAT4uOE3m+Ijwg3YA3QJI2wJVz7mkM3Cl5yWa2MrMdgeqBd0kXTdOcToWP/gGwJrSiNbPbtm3vyrL8nAr+zUCES7z33t8URfH2V3Cnb5qdYd58KZMsAAAAAElFTkSuQmCC) no-repeat center center}
		li.diapo.loaded.up:hover{-webkit-box-shadow:none;-moz-box-shadow:none;box-shadow:none}
		li.diapo input[type="checkbox"]{position:absolute;top:5px;left:5px;display:none}
		#view{position:absolute;overflow:hidden;top:0;bottom:0;left:0;right:0;opacity:0;z-index:0;-webkit-transition:all 0.5s linear;-moz-transition:all 0.5s linear;transition:all 0.5s linear}
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
		#view.com #slide{left:360px;-webkit-transition:all 0.5s ease;-moz-transition:all 0.5s ease;transition:all 0.5s ease;left:320px}
		#view.comfix #slide{left:360px;left:320px}
		#view #comments{position:absolute;top:40px;left:-300px;bottom:0;width:300px;z-index:7;overflow:hidden;-webkit-transition:all 0.5s ease;-moz-transition:all 0.5s ease;transition:all 0.5s ease;color:#666;background:#fff url(data:image/jpg;base64,/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAAUEBAQEBAUEBAUIBQQFCAkHBQUHCQsJCQkJCQsOCwwMDAwLDgwMDQ4NDAwQEBEREBAXFxcXFxoaGhoaGhoaGhr/2wBDAQYGBgsKCxQODhQXEg8SFxoaGhoaGhoaGhoaGhoaGhoaGhoaGhoaGhoaGhoaGhoaGhoaGhoaGhoaGhoaGhoaGhr/wgARCACIAIQDAREAAhEBAxEB/8QAFwABAQEBAAAAAAAAAAAAAAAAAAECB//EABYBAQEBAAAAAAAAAAAAAAAAAAABA//aAAwDAQACEAMQAAAB6tjQAAFItSAAqRQAAAKQBCgAhQAABQQBagEAAKpIAAAAAhaQFUkBQQALQkAAAAAAABSIUCkBSFIUEKAAQoAUhSFBIFoAZigACkBSAAFBFqCBagBQSKKhQSAALUgC0iAFoCQAApCkWpAAVSQAAAFItSAAqkgAAAAWhIhQABVBBFqARakBSAAAAAAAAAAoBFqQLUgWpAVSQP/EABQQAQAAAAAAAAAAAAAAAAAAAID/2gAIAQEAAQUCEP8A/8QAFBEBAAAAAAAAAAAAAAAAAAAAgP/aAAgBAwEBPwEQ/wD/xAAUEQEAAAAAAAAAAAAAAAAAAACA/9oACAECAQE/ARD/AP/EABQQAQAAAAAAAAAAAAAAAAAAAID/2gAIAQEABj8CEP8A/8QAIBAAAAUEAwEAAAAAAAAAAAAAASAhMEAQETFRUOHw0f/aAAgBAQABPyGnsN+wx1A1UL1WJ2deCvDXkhiBBCCBUb0QGUf+G05//9oADAMBAAIAAwAAABBNvNVjPKd5dpebKv8AX2zycm+Fn6TATzf3+2n0Dcm3wHbbaSbTSVVemU99vskmkkEEA+AA7SWjUbS0vE2EtA6EBb/52Yb2AG2+mjGf0G+ye0Y2Xg73+27Ie728ImFuOV27bf8Att8n25ZhuNxtR9//xAAUEQEAAAAAAAAAAAAAAAAAAACA/9oACAEDAQE/EBD/AP/EABsRAAEEAwAAAAAAAAAAAAAAAAERMEBgECBQ/9oACAECAQE/ELMuFop1V8QRBDh6X//EACQQAAEEAwACAwEBAQEAAAAAAAEAESFBMVFhcYGRobHw4dHB/9oACAEBAAE/EN8ekR94jiv3+EMg+K4mZm5SDQWDRSbn11ERH51NPvXlBmxqkBA9V1EADH11MC/uW6mEnToWn20cRFt9cXXDNpFm+WlbvLzxMQY9F+IZD8vi18JQwNxaBB189UR/3qBmni0G48Wo40X1ZBEfPUWnU2jeLl1Dk598RGMB2via2rcYQJxU1xS2Prifn1xS8NNCbDjVIVxpZQXz5UkDNv8AKDuCxrSBwl4pT3w3VOjct1F2PHoIktd1xDJiRmOJzERFKcTjS/8ACb4opuF+LWKeeIFmxV8Tm2q0CwAeItQNfe0wh2u0MjDRvayHirO1dP72iAAZhjZ2jpxpKrIt5OlD5E94g8GIaHTy9NtS7zetKaf0BpZAB61pAGM1pSWzUsFrNQwTmc+I2p606UvdWNqetERtcn62i5Bd3mPabObmFPeYmFL39aQctmmgKeu2GCj/AJnSDfzphnXCoBH+qIHjaYRitqOfBQbjeCogR8dUB8fB2mGI+9qGOHD7RacXtQBTB/xNpvvSDMNOKKYYtmedoGHcU8naev8A07WBMXaJz7tEte7Tz/vE8j0tTMX1E/Hnq9/a/D1P/OiXhZT/ANKdnn7NJ+BvalrfUbRft62pnN6RhxLSiMnN60p7PjSBMZq181PtFx5/1ayqHqVMM/yvlvI2nPXqVLZo2nM5uIXvrQg2x/FRsX+o3i0WkxaLbF/iDbH8FY9IDGGLfq8s9fK+PK+F8KGeF8KOKBEKOP8A4g7Q7etqZM3+ovOb0mMu7yi+ZadKTv60g0erUxmo9poGUPdWiDG4cOtO/wAr5+eqB4HerG7vqN5l5cKdHygXHPHUSP2uqJ90mOR5pPzdcQY1quIZw+KWoltdRM7WohxXFLD1SFeA7jqz/wBbqecRMMuXNKjoO8L39cTFmB++omD7vqLgGZm0b92v4TxB3Hq+Jkbi1A8Nvqzu7UQeh54gBDcl0+DYt+pxhx5fqJ0Zm0bENLyp/b4n/n4g4DeWjqOC53SIhvMsmz7riIqvHFgt4ricw9Uj/R1f7SDxnIpB+1SmA5fx1bZ7huo+5eWUl/dKf4cUviGfHFHBmyjkyHlpKJgu1w5UPkS9nSJG/viDEiRkNPEEDDRva+Hb/wBX5NoNDtTIEceLRJaq+qATh5sotlxD2UTPzfF4aGeeKHZwzbKnt6Rd7uhpbzf4vn4GkSYEv44pe0iuIuAHcs2lQd/pDTflp0QcjSD4mmMKRRf1tGzN0NouxM3QREy8vQ0p78DSl3nGgt4tjKMDAt86T5w3vSBByAPnScO5Z3G9KHGHjaYu2mqkMNHhuoSafwhE1DxrKB8NFI9IfbdRZiYvao4eWgokOcXvSLRj70qaHsxV8l5RJn3fEbydzxCmOmnifE6cOh1ENKj8vqaAZfz1MZd9Z6vD8nZVicNakXFT1F2d92ngsd2i7n/vFqfvi68Ntf/Z) repeat}
		#view.com #comments{left:60px;-webkit-transition:all 0.5s ease;-moz-transition:all 0.5s ease;transition:all 0.5s ease}
		#view.comfix #comments{left:60px;left:10px}
		#coms{overflow:auto;position:absolute;top:3px;bottom:180px;width:285px;background:#fff url(data:image/jpg;base64,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) repeat}
		#coms li{position:relative;padding:3px;margin:5px 5px 10px 5px;color:#aaa74a;background-color:#fffc9f;-webkit-box-shadow:1px 1px 2px 1px #666;-moz-box-shadow:1px 1px 2px 1px #666;box-shadow:1px 1px 2px 1px #666}
		#coms li button.del{position:absolute;top:1px;right:2px;font-size:12px;right:4px;top:3px;color:#aaa}
		#newcom{position:absolute;bottom:25px;width:280px;height:140px;padding:3px;background-color:#fffc9f;-webkit-box-shadow:2px 2px 2px 1px #666;-moz-box-shadow:2px 2px 2px 1px #666;box-shadow:3px 3px 2px 1px #666}
		#who, #what{width:280px}
		#what{height:88px;resize:none}
		#viewbar{position:absolute;top:0;left:0;width:60px;bottom:0;z-index:8}
		#viewbar button{position:absolute;border:none;background-color:transparent;opacity:0.5;-webkit-transition:all 0.3s linear;-moz-transition:all 0.3s linear;transition:all 0.3s linear;color:#444;opacity:1}
		#viewbar button:hover{opacity:1;-webkit-transition:all 0.3s linear;-moz-transition:all 0.3s linear;transition:all 0.3s linear}
		#viewbar button.active{opacity:1}
		body.sleep #viewbar{display:none}
		#mask{display:none;z-index:50;position:absolute;top:0;bottom:0;left:0;right:0}
		#mask.active{display:block}
		#mask #loading{position:absolute;bottom:28px;left:0;right:0;text-align:center;font:normal 32px Arial;color:#fff;font:bold 30px Satisfy;text-shadow:0 4px 3px rgba(0, 0, 0, 0.4), 0 8px 10px rgba(0, 0, 0, 0.1), 0 8px 9px rgba(0, 0, 0, 0.1)}
		#intro{z-index:90;position:absolute;top:0;bottom:0;left:0;right:0;background-color:rgba(200,150,200,0.4)}
		#intro.hide{z-index:0;opacity:0;-webkit-transition:all 1.4s linear;-moz-transition:all 1.4s linear;transition:all 1.4s linear}
		#intro h1{font-size:60px;color:#fff}
		#intro h2{font-size:30px;margin-bottom:10px}
		#godbar{position:absolute;right:20px;top:15px;width:16px;z-index:30;top:10px;width:160px}
		#godbar button{float:left;height:16px;width:16px;margin:3px;opacity:0.3;border:none;float:right}
		#godbar button:hover{opacity:1;-webkit-transition:all 0.3s linear;-moz-transition:all 0.3s linear;transition:all 0.3s linear}
		#godbar button.hide{display:none}
		#iupload{position:absolute;top:-1000px}
		#diag{position:absolute;color:#fff;top:40px;right:40px;overflow:auto;border-radius:4px;background-color:#333;z-index:90;padding:6px;-webkit-box-shadow:3px 3px 5px #000;-moz-box-shadow:3px 3px 5px #000;box-shadow:3px 3px 5px #000;top:50px;right:20px;font:16px arial,monaco}
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
		#thumbbar button:after{content:"";position:absolute;right:0;top:0;border-color:#fff #d5d4c6 #d0d080 #fff;border-style:solid;border-width:0 8px 8px 0;height:0;width:0;display:block;-moz-box-shadow:-1px 2px 1px rgba(0, 0, 0, 0.2), -2px 1px 1px rgba(0, 0, 0, 0.1);-webkit-box-shadow:-1px 2px 1px rgba(0, 0, 0, 0.2), -2px 1px 1px rgba(0, 0, 0, 0.1);box-shadow:-1px 2px 1px rgba(0, 0, 0, 0.2), -2px 1px 1px rgba(0, 0, 0, 0.1)}
		#thumbbar button:hover:after{border-width:0 16px 16px 0;-webkit-transition:border-width 1s ease-out}
		#thumbbar button:active{margin:2px 6px 0 0;-moz-box-shadow:none;-webkit-box-shadow:none;box-shadow:none}
		#thumbbar button#bzip{}
		#bzip{margin-left:10px}
		#bzip.empty,#bzip.all{display:none}
		#thumbbar #path button{background:-webkit-gradient(linear, left top, left bottom, from(#d3f2d4), to(#b8c7b9));background:-moz-linear-gradient(top, #d3f2d4, #b8c7b9)}
		#thumbbar #path button:after{border-color:#fff #d5d4c6 #c3e2c4 #fff}
		li.diapo.loaded:nth-child(even){-webkit-transform:rotate(-2deg);-moz-transform:rotate(-2deg);transform:rotate(-2deg)}
		li.diapo.loaded:nth-child(5n){-webkit-transform:rotate(4deg);-moz-transform:rotate(4deg);transform:rotate(4deg)}
		li.diapo.loaded:nth-child(3n){-webkit-transform:rotate(1deg);-moz-transform:rotate(1deg);transform:rotate(1deg)}
		}
		li.diapo input[type="checkbox"] + label{width:16px;height:16px;position:absolute;top:16px;left:16px;width:16px;height:16px;background:transparent url(data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAABAAAAAQCAYAAAAf8/9hAAAABHNCSVQICAgIfAhkiAAAAAlwSFlzAAADZgAAA2YBNMGSBgAAABl0RVh0U29mdHdhcmUAd3d3Lmlua3NjYXBlLm9yZ5vuPBoAAAIBSURBVDiNlZK9axRRFMV/d0ZwxiSYaGEQG4VgNipYKLGKn/EDzGJpYWtjI8jmvSLEITa785r9AwQVC6sQWAURgoIWEiwUF0H8gFSKiCAoyMtmd66Fk2VjdhEPvOqec+69511RVbphbm4uiKJoEXhtrb3WlQQEvQpxHBeBE8BV51zhvw2AEpDlnNleJOm2QrlcHg+CYAm4B+wAjovImDHm3T8NqtXqtkajcQeYUtVDItIHPAVqYRhez7Js2Rjzs22Qpumkqk4B+4AxYDivPbPWHgVI0/Qxf/JYwzdgWVUfbVLVu7loVVXfisgTEXkThuHNdheRC6p6EdiTv5PAYRHpE+fc+SzL5oEVETljjFnqFRiAc66oqvPAdxGZEFUlTdNzwAKwCpy11j7vJq5UKqdF5D7wKwzDY6VSqd4O0Tk3qao1oNVsNkdnZmY+dYrTNN0KfAZaInLKGPMCOu7AGLMIVID+MAxH/u4eBEEMbAFqa+J1BjmGAVqt1od8qoFyubwdYHp6+gvwFdjfKVh3B/l3HfHeD8VxfEVVE6AfuC0iN1T1FjDhve9PkqTZbYJRYCWKorqqVoEG8BK4rKofgQKwOYqivRtWcM4NADuBIWA34Lz3I9bacaAIvAd25fQDGwwGBwe9qtaBBVUtWGttkiQ/AKy1D7z3B0Xkkog8FJFXa7rfY9XNLpAieW8AAAAASUVORK5CYII=) no-repeat center center}
		li.diapo input[type="checkbox"]:checked + label{background:transparent url(data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAABAAAAAQCAYAAAAf8/9hAAAABHNCSVQICAgIfAhkiAAAAAlwSFlzAAADZgAAA2YBNMGSBgAAABl0RVh0U29mdHdhcmUAd3d3Lmlua3NjYXBlLm9yZ5vuPBoAAAI5SURBVDiNlZJBSJNhGMd/z/dtc25MpjIzQU+BacVueRciL3oQukjgqXMQ8/tOJTsNd/EiHiU6iB5qRVCiZyHCCEsbiTR2N7dcY9u37X06lGZuEb3wXt73//897/N/H1FV2q1kMmkFg8EtYNd13QdtRYD1t4vOzs4pYBy4n06nR/4bACREzjQP/yaSdi2kUqkxy7LejI56lMtCPu83IjLqOM7nfwIWFxd7PM97DEzOzp5Qr8PqahfAC9u2Hxljco7jlM4ACwsLt1R1Erj2a18CGBxsMDNzAsDaWoR83v+7qvBVlS+quuFT1SdAv22jPT1NicWaxGJN4vHqmWF6+jvZbIBi0aZYtMjn/b2VivSKSNhnWdY9Y8xT21b/xESZgYFGSyaBgBKP1wA4PAxwcBBQEY5Apu3Nzc2D7e3td8bInWw2YA8NNaSry7RNPJfzk8lEVFW+WZY9Pjc398kCcF33Ncik50ltfT2ipVLr79ZqQiYT0WaTMnA7kUh8gHNz4DjOFpCq10UKBbsF0GgI9ToCPHcc5+3p+cVS/QDd3U0APE+oVH5OUzhsCIVURbh+3nARMOz3o6GQYWcnyPJyVJeWunVjI0ypZNHX1xBVRpPJpO/U4LsAGLFtlZWVqB4fWyLCkSq53d2Om3t7HRoKGQECwWBwGNj/4wXpdDoCXK5WhULBagDpSqV6xXXdMWDKGN0/F+6Nlhai0WgV+Ag8M0avuq7rzs/PnwC4rvuyUqnFReSuiLwSkfenvh+Qoukzv1fdlgAAAABJRU5ErkJggg==) no-repeat center center}
		li.diapo.loaded:hover,li.diapo.loaded.cursor{z-index:10;color:#999;-webkit-transform:scale(1.2) rotate(0);-moz-transform:scale(1.2) rotate(0);transform:scale(1.2) rotate(0);-webkit-box-shadow:8px 8px 6px 3px rgba(0,0,0,.9);-moz-box-shadow:8px 8px 6px 3px rgba(0,0,0,.9);box-shadow:8px 8px 6px 3px rgba(0,0,0,.5);-webkit-transition:all 0.2s linear;-moz-transition:all 0.2s linear;transition:all 0.2s linear}
		#coms li content{color:#555}
		#coms li:nth-child(odd){-webkit-transform:rotate(-2deg);-moz-transform:rotate(-2deg);color:#a080a0;background-color:#F2D3F1}
		#coms li:nth-child(5n){-webkit-transform:rotate(3deg);-moz-transform:rotate(3deg)}
		#coms li:nth-child(3n){-webkit-transform:rotate(1deg);-moz-transform:rotate(1deg);color:#80a080;background-color:#D3F2D4}
		#who:focus, #what:focus{background-color:#eeeb8e}
		#newcom .unused{color:#999639}
		#bsend{font-size:32px;width:36px;color:#aaa74a;text-shadow:2px 2px 1px #000;text-align:right;line-height:20px;padding:0 2px 2px 0;margin-left:242px}
		#bsend:active{padding:2px 0 0 2px;text-shadow:none}
		#viewbar button:hover{-webkit-transform:scale(1.2);-moz-transform:scale(1.2);transform:scale(1.2)}
		#viewbar button#bprev{position:fixed;left:-100px;top:200px;width:160px;bottom:200px;opacity:0;background:url(data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAACAAAAAgCAYAAABzenr0AAAAGXRFWHRTb2Z0d2FyZQBBZG9iZSBJbWFnZVJlYWR5ccllPAAAAdxJREFUeNrsl00oRFEUx//3fYxBsZgl+YimpiZb8vGK8R0xWyvZkmIxZG0UCxYsJRY2ZiVRsyCUhaVYUUgimlKKMWNmnnNn3gwTsrkzU3qnTu9+vXt+75xzz+0xXdeRS5GQYzEBTAATIOcAv4nSPYet3nnwKmXJtgfkVi82mirRE43F+4XZBJBdXvgaquF2100hHI2PqRl1dZrxafga7XC31QwgBiuCIaBlBg+MiTPIt5Lps2WG7R0P+pNbK21e7NY7oHXUDOLkZhXNjiWE4KfFCr0kjoDR11gUCcPLPvg9KOIeULvmcF/ngK3dOYTT2xWQTexfjKRRiwMA8iioxiVs5QCF1LbV2p04D6yDqWIN/hQCyZKywXgSKuFnHBxfniGmhuJZocsJjWeIaFXTM483Q3vTGI1MYLGlGVpFKRCJGpMEIQkuVTwEKlmV2CdAkPTucBZj9FxwuaCVlyUWrm3QAiXRFhkDbjzygiPq6RwgQvrE51IQndDsVURK7vJPoovG3gWnArcZIH1LRiP6FYIAF9Q+aDwEJFfJOYHCz8Ab9/7XQpSCOJjFeH4xNi1WlBhjj5k8Fd/uAlIbaalBep0BD/xZY/hhKTDmX0nD+K/CzB8TE8AEyDXAhwADAIjfcKoAGPjLAAAAAElFTkSuQmCC) no-repeat 115px center rgba(0,0,0,0.4);-webkit-border-radius:0 190px 190px 0;-moz-border-radius:0 190px 190px 0;border-radius:0 190px 190px 0}
		#viewbar button#bprev:hover{opacity:1}
		#viewbar button#bnext{position:fixed;right:-100px;top:200px;width:160px;bottom:200px;opacity:0;background:url(data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAACAAAAAgCAYAAABzenr0AAAAGXRFWHRTb2Z0d2FyZQBBZG9iZSBJbWFnZVJlYWR5ccllPAAAAeNJREFUeNrslztIw1AUhv+0qVgr1Do4OSiIk4JOCiIOvkDaIUMddFAEV0HdxNGCiri5ig520MFBoVis2mIVZwuC4JMqiIoiltLm5bltilXXpHHICSfcmzuc7/z5by7hVFWFmWGDyWEBWAAWgOkARkeZbxnqwCJ2aMyboYBLVoDOenh7Atikub3UAI6sDAjtM+hogNAdwNZvCCaLw7uEZ1GGm9HqeTKwYyadARSUo7dliNoNCpjDVmQWflqWCwAuUYF7ZdyPrKRAz8NJpUtWJJK5GucPQfS3jlL/awJHEPsaBEe3mr4FPA13ARkxT60fwHewQhzdmmvHEE6s4uQcu3szEPjCoq0sbwijD+fLlw20NTbh9CLhpamT/+EG1RiCgqpMAYXP4Ow6gewHosx/OQAbLTicbKrvK2ChkLElWeuR/H+bBA6OEIstYIIeiTkAO4fP9W1UKjorwJqRJGBkMD++u6fih7nik7T8SJlmr7+Ksp7So9lB1+9A/zxCo7QDL6+ASAjR2CKm6Dmh4I3tAqZAivKG8sEAz3mY7DckezyOVyo+XVy8YD3afHg3yvV2Ajg+xkd4Fj6aJouLwwDJ/yhAWac1yhR+1hpGqQDo64IKbZz6XfxfBGf9mFgAFoDZAF8CDACZ1p8bdg5ccgAAAABJRU5ErkJggg==) no-repeat 15px center rgba(0,0,0,0.4);-webkit-border-radius:190px 0 0 190px;-moz-border-radius:190px 0 0 190px;border-radius:190px 0 0 190px}
		#viewbar button#bnext:hover{opacity:1}
		#viewbar button#bcom{left:82px;top:6px;width:32px;height:32px;background:url(data:image/png;base64,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) no-repeat center center}
		#viewbar button#bcom span{position:absolute;left:0;top:0;width:28px;text-align:center;font:bold 18px Satisfy}
		#viewbar button#bcom:hover span{text-shadow:0 0 5px #fff}
		#viewbar button#bplay{left:46px;top:6px;width:32px;height:32px;background:url(data:image/png;base64,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) no-repeat center center}
		#viewbar button#bthumb{left:6px;top:6px;width:32px;height:32px;background:url(data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAACAAAAAgCAYAAABzenr0AAAAGXRFWHRTb2Z0d2FyZQBBZG9iZSBJbWFnZVJlYWR5ccllPAAABGZJREFUeNrsVz1sHEUUfjO33jvHwPLjIDCGIIKQ6OgicakCxMUVGGSTiDgkCNrQQFxYokKkMFRAGQmRhPDjS7JIXJEQooBZJHc0NIhIOBxO4JzIdjj7fnZnePOzezN764tcGDc5aU7z5nsz8+bn++YtAQBn+gvwIQclzkD+CAU04crbr8DTaPLXTsz7nEKJMS5xSglwziunDj4+KuzVqZd9h5ASNuoBCLSBVwbePyvxT6ebfg7ckgEDg1bl8GR+1EG7X0w+XjyPE+ekA2cRlIORnVjdhoUx6pSO7B0Ch6gBQhzoo++qJdkXfy7hpdzYG7iUgnZoACkfT3BK3NJzL4rAFcxwod/7rsRFAAUR2VprEX7/qywdnnxkDPRiXRkQOiw1AX6uqgGeHRaDSAc1I1ajtSUIfzkvTeeZEdC7UVALAmhg/+pvqv/wUzEMBREAAWk0wRsYVh5YV21A4r8mDnJvv0aZ2kYLD1chvG9QrRDrbgdKjnXggU49bnbihoLrwqC3U4WFddpxwskIRDjU/XcpW9QpIYYDhUZfCGz7g+oEsO6as6Crg4d5j4LB6U8WACIAXl+CoBxMFM1LItpA78PaSi04M8uKXDuIgBoriwl+vd4IHvr2YtG8hNewLcZX6gvBDzNDRfOSizaBizjuxvIYlkEdkFwElkUsV7W9aTjRjf36wsT7KiJviMVre9NwGcBnyFOKPGV6i8T5RyScO3y0b7ewG1OjvgM2zyOASv7YOcnzV0/N+7ivlk4QBpXPDz4q8ekvUWeEjujuVMyK+OR+UDpAcPI9ozZPL37j7NI6wHMoMrmxNy2eg8FzgpO/9cKwpRMfX1jo4Dj5WEpnZoIRWwe6eKp2I6968AyeQ8JzsfK0TnBDB1iGztg60IOn6o9AK8XzvImTDJ0w8F4649yOp+vxPG9ESTN0ghgD0AydibtLHcji6Vrz5q8mzx9O8fy6wXOhCWdmIaUTNxL83x46s54O4CWHG1jm9SBbogNN8dL+LzrwwVfwE+7FLpOnWK68M67ygQMn/+zKB5AmldMTOyTPm1Mv4XsPlk6EmA8UjvlJPuCkdEbkA4d0PrBNTN4rH1A8Xz8fyBHo0gmeygf29MgH8nfygS3PB24hJ2eQpywWDrGKNlQ7PP87ODvbtnhs8vxavY06cdnSiYV6qysfAGOCW7fJB4TrTSx/6PqW6EBL6wDfbB3oa7+7v0aiyAPrw4AsO+99vV2YJz9kNSSjZ8KU8OUDR6nEp8tQQ2p55hFRDnOT+0DkE+TQ6WotYqFn6QywuRMTT+wWqx/gCDrjr1s8DsvHPYGp55R4z6fyhUs+SXA8JG9ft47E+QSNMPgje3dYOvLJhasSdyTXM9973tGBDB7bOrGujohXm2TpiH648vGl6HrvXTDf4wwec0j9Nqgjtg5snMe2TmxcRwglHR1YqDeDocoli8erjCc6sIycvSzyBeOSxXm9sLO+K1aN74rm8j/BuR9DK18QbXd0QOD/CTAAVkcNQCJhd7EAAAAASUVORK5CYII=) no-repeat center center}
		#intro > div{margin:100px auto 0 auto;width:300px;height:300px;vertical-align:middle;text-align:center;-moz-border-radius:150px;-webkit-border-radius:150px;border-radius:150px;border:3px solid #777;color:#fff;background-color:#c9c;font:normal 16px Satisfy;box-shadow:0 0 10px 10px rgba(255,255,255,0.5)}
		#blogin{background:url(data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAABAAAAAQCAYAAAAf8/9hAAAABmJLR0QA/wD/AP+gvaeTAAAACXBIWXMAAAsTAAALEwEAmpwYAAAAB3RJTUUH3QEQDRoCWfbT2gAAANxJREFUOMulk7EOQUEQRQ9P3g9o/IRKITqlT5DMF9AoVHqJTlQ0lPcbtFQqlY+g0ko0T7OE8d7LiptM9mb27t2ZnWwlyzI8zKwJzIBWSB2BiaST11a8gZl1gB2QOu0d6Eo6vCerfGMZDq+AeohVyC29OM+gGdaxpKukKzB2e6UGCYCk2zPxxpMYg5/wt8FrCma2AEYRZ+7AUNLGVzCIvDQFpnkt+LnvgXaIvdtrPEmt5Ka+pEtorw+cf33EpIBHG6wLeLRBr4B/oObGk7qP1SsZ5VcF8xzhNoTHS/sAZQw6nLbFjCkAAAAASUVORK5CYII=) repeat center center}
		#blogout{background:url(data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAABAAAAAQCAYAAAAf8/9hAAAABmJLR0QA/wD/AP+gvaeTAAAACXBIWXMAAAsTAAALEwEAmpwYAAAAB3RJTUUH3QEQDRo14Ut21QAAAOFJREFUOMulkq1uQkEQhT9+wgv0OVCIBldZZN1N5glQkKD6BLimNWBqj0WTYEChUDwEKGyTa27NQJrpbrOkJ5ns5OyZs7Oz22qahggz6wNzYODUAXiVdIzaVjQwsyGwBXpBWwNPkvY/yTa/sfDiJfDgsXRuEcUpg76vM0kXSRdgFvb+NOgASPq6Ep6vgU0UdymEpFGKb/NP3F7BzN6BSWHdh6Rp7GB8x8Hj1BXiu++AR49d2OuVDLGSdPbrVcDp3iF2MnmxwWcmLzZ4zuTZj1THQZrZS6auTnXwlhCuPCJu2m/z6z0KUUzH3gAAAABJRU5ErkJggg==) repeat center center}
		#bupload{background:url(data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAABAAAAAQCAYAAAAf8/9hAAAAM0lEQVQ4y2NgoBAw4pKIior6j8xftmwZVrVMlLpgGBjAiC3AiAXLli1jHC5hMJqQBhgAABzZDBpqRcGNAAAAAElFTkSuQmCC) repeat center center}
		#bmkdir{background:url(data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAABAAAAAQCAYAAAAf8/9hAAAAiklEQVQ4y8WRwQnDQAwExyIt5J0KUkPaUIn7OLga3EIEKSDPNJF8zvcwtsGyIfocB7vLrAT/ngHA3T/AdUkgadgKuLT3BTyWBO7+XfG+Jd2sfSJBP56zA3d/AvekPwyoBwCqAeVAQDFJkVxiSIrpCpkaFWAKyNQoPSBRI5qnE+yt0bU2R9qDf8r8AGlAJTbBH6XLAAAAAElFTkSuQmCC) repeat center center}
		#bflush{background:url(data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAABAAAAAQCAYAAAAf8/9hAAABKElEQVQ4y8WTT0eEURTGf1MjhohW0QeIuLs2Q6uI2bQrw8msWrSPodVQtCl9g1nFPGrVNiIiZhuXaBsRrYaYTdHmvLre7jvVqrM59z7nPufc8w/+S8ysDVCvMDaAdWDBoRfgWtLY7T1gE7ioZ8h7QA+YK5lGZnYIzAAHQPz2AzM7Azp+fQDu/LwKLAOn5YD1hNx18huwK0kl5+dAO+vAc953bEvSVYm8kyMDTLleA+aBYYbcAfpV3ShSWHI9zLyJwEoGH1e2MRVJ95PshYNH181fDJABs8ClpNeiBjfACGiaWWsCuQUMgGPvFtMAMcb3EMKHT99GCOEpxhgzkftAA+hJugWolR4NAEuKlw5S+CqLtgtO7S+jDBxJOknB2g/LtOjQc7pMqXwCiPZdkmM4XEAAAAAASUVORK5CYII=) repeat center center}
		#bdel{background:url(data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAABAAAAAQCAYAAAAf8/9hAAABD0lEQVQ4y6WTMWoDMRBFn9dpFnyAXEJN6oUFFwGDcWFwswfIDQypA7nKNAFDIBBwYVjYG+gEuYNBkCpFvoysVeyABxa00vw/X39GcGNM0p+u61pgC2zMLGRnNbADXs1sGBEI/AnUwAFYRhKB90ADBOAxkkyUMAc+BI5xAJZaR3CMACzMrL/TxjYDA8ylaJqBUe4z0FfaWAN9waO2AEa569yDWhXbK8b3kh9KXbhGcgYGqApJ0wvVR2dVUn1WcDuPBthLKWkbZ5Le/HMAB81CiAp2F9we/lDynl7hRcMxMkxfThKE+SXQWC4SkpPbZnbMSM5G+eSq9/7LOTcA98AqbZX3/ts59wY8AE/pY7o5fgDPymHx+73mMwAAAABJRU5ErkJggg==) repeat center center}
		#bdiag{background:url(data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAABAAAAAQCAYAAAAf8/9hAAABAklEQVQ4y9WTMUtDQRCEv0sgP0CwSiUIgnitrYGAIFhYWDiFFtr6A/wHVla26bdMJVhGlKQKCLmAnSBYBYRXWT6be3KeL4FgGqfcm9nZub1zZVnyfyCpKWk3rTWW7NEDhpKOq0JzCfcecB5Nj7z30xDCSyMfcY74FrhISi3g9EcESVvANM8o6Qa4zHreAycALpI2gQHQBgqga2ZjSdfAVSZ+AA7M7DNtMAD2EtIH0M/GBngC9itxGuEMeEuIazXiUepcwSVZN4DHGCPHOMYq8oPvSzSzV6ADvGec5zh2UbchV7Oy7Xih68AkOs/mvY9few8hzLz3d8AOcLhIvBK4v37nL1uKUZsLZ6+eAAAAAElFTkSuQmCC) repeat center center}
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
		nozip:"rien \u00e0 t\u00e9l\u00e9charger",updir:"",uploadfiles:"poster %nb image%s (%z octets) ?",flushed:"%nb fichier%s supprim\u00e9%s",uploaded:"%nb image%s ajout\u00e9e%s",deleted:"%nb image%s effac\u00e9e%s",mkdir:"nom du dossier ?",delay:"le diaporama passe \u00e0 %ss",exifnotfound:"aucune information disponible"}},loc,setlocale=function(f){var e,w;loc=locales[f]?locales[f]:locales.en;loc.reldate=function(b){var g=(new Date).getTime();b=(new Date(b)).getTime();g=(g-b)/1E3;b=function(b,h){h=
		Math.round(h);return b.replace("%d",h).replace("%s",1<h?"s":"")};return 60>g?loc.date.now:3600>g?b(loc.date.min,g/60):86400>g?b(loc.date.hour,g/3600):172800>g?loc.date.yesterday:604800>g?b(loc.date.day,g/86400):2592E3>g?b(loc.date.week,g/604800):b(loc.date.month,g/2592E3)};loc.size=function(b){return 2048>b?b+"b":1E6>b?Math.round(b/1024)+"kb":1E9>b?Math.round(b/1E6)+"M":Math.round(b/1E9)+"G"};loc.tpl=function(b,g){var e=loc[b],h;for(h in g)e=e.replace(RegExp("%"+h,"g"),g[h]),"nb"==h&&(e=e.replace(RegExp("%s",
		"g"),1<g.nb?"s":""));return e};if(loc.title)for(e in loc.title)(w=_("#"+e))&&w.setAttribute("title",loc.title[e]);if(loc.holder)for(e in loc.holder)(w=_("#"+e))&&w.setAttribute("placeholder",loc.holder[e]);if(loc.text)for(e in loc.text)_("#"+e,loc.text[e]);f="<table><tr><th>"+loc.help.th.join("</th><th>")+"</th></tr>";for(e in loc.help.tr)f+="<tr><td>"+loc.help.tr[e].join("</td><td>")+"</td></tr>";_("#help",f)};ready(function(){setlocale(hash.get("lang")||navigator.language)});
		var log=function(){var f,e,w=(new Date).getTime(),b={debug:1,info:2,warn:3,error:4},g=hash.get("log"),A=g&&b[g]?b[g]:0;if(!A)return{debug:function(){},info:function(){},warn:function(){},error:function(){}};console.log?f=function(h,e){if(b[h]>=A){var g,f=("     "+h).substr(-5)+"|";for(g in e)console.log(f+e[g])}}:(ready(function(){append(document.body,'<div id="log"></div>');e=_("#log")}),f=function(h,g){if(e&&b[h]>=A){var f,Q=("000000"+((new Date).getTime()-w)).substr(-6);for(f in g)append(e,'<div class="'+
		h+'"><span class="timer">'+Q+"</span>"+g[f].replace(" ","&nbsp;")+"</div>");e.scrollTop=e.scrollHeight}});return{debug:function(){f("debug",arguments)},info:function(){f("info",arguments)},warn:function(){f("warn",arguments)},error:function(){f("error",arguments)}}}(),osd;
		osd=function(){var f,e,w,b,g,A,h=!1;ready(function(){f=_("#osd");e=_("#progress")});return{hide:function(){h&&(h=clearTimeout(h));css(f,"-active")},show:function(){css(f,"+active")},error:function(b){log.error(b);osd.info(b,"error",5E3)},info:function(b,e,g){_(f,b).className=e||"";h&&clearTimeout(h);osd.show();h=setTimeout(osd.hide,g||3E3)},loc:function(b,e){osd.info(loc.tpl(b,e))},start:function(g,h,f){w=f||"%v/%m";b=g;A=h;b&&(css(e,"+active"),osd.set(0))},set:function(h,f){f&&f!=b&&(b=f,css(e,"+active"));
		g=h;_("#progresstext",w.replace(/%v/,h).replace(/%m/,b));h>=b&&(css(e,"-active"),A&&A());_("#progressbar").style.width=b?Math.floor(position("#progress").width*h/b)+"px":0},inc:function(){osd.set(++g)}}}();var walli;
		walli=function(){function f(){var a=_("#thumbbar"),d=position(a),a=window.getComputedStyle(a);_("#diapos").style.top=d.top+d.height+parseInt(a.getPropertyValue("marginBottom"))+"px"}function e(a,d){H&&(_("#bzip",d).className=a,f())}function w(a,d){var p=l[a],c,q=Date.now();c=new Image;T++;c.onload=function(c){c||(c=window.event);log.info("image #"+a+" "+p+" loaded in "+(Date.now()-q)/1E3+"s");d(a,c.target||c.srcElement);T--};c.onerror=function(){log.error("error loading "+("image #"+a+" "+p));d(a,
		null);T--};c.src=encodeURIComponent(p)}function b(a){a=/([^\/]+)\/$/.test(a)?RegExp.$1:a.replace(/^.*\//g,"");return a.replace(/\.[^\.]*$/,"").replace(/[\._\|]/g," ")}function g(a){return 999<a?Math.floor(a/1E3)+"K+":a}function A(){U&&!F&&(F=setInterval(function(){log.debug("refresh required");ajax("?!=count&path="+s,function(a){I.length==a.dirs&&l.length==a.files||h(s)})},1E3*U))}function h(a,d){F&&(clearInterval(F),F=!1);log.debug("loading path "+(a||"/"));e("hide",loc.dlall);x&&(_("#bdel").className=
		"hide");z=[];osd.hide();ajax("?!=ls&path="+a,function(a){var c=_("#diapos","");s=a.path;log.info((s||"/")+"loaded with "+a.dirs.length+" subdirs and "+a.files.length+" files found");if(s.length){var q=s.replace(/[^\/]+\/$/,"/"),b=document.createElement("li");css(b,"diapo up loaded");b.setAttribute("title",loc.updir);b.onclick=function(){h(q)};c.appendChild(b);var g="",m="";s.split("/").forEach(function(a){a&&(g+=a+"/",m+="<button onclick=\"walli.cd('"+g+"')\">"+htmlencode(a)+"</button>")});_("#path",
		m);f()}else _("#path","");l=a.files;I=a.dirs;G=a.coms;d&&d();v.layout.build();a.files.length&&H&&e("all");V();A();x&&_("#diag")&&walli.diag();J[s]&&B(0,0)})}function C(a,d){if(W[a]){var p=u.clientWidth,c=u.clientHeight,q=W[a],b=q.h,g=q.w;g>p&&(g=p,b=Math.floor(q.h/q.w*g));b>c&&(b=c,g=Math.floor(q.w/q.h*b));css(k[a],{width:g+"px",height:b+"px",left:Math.floor((p-g)/2+p*d*2)+"px",top:Math.floor((c-b)/2)+"px"})}}function aa(){R&&(K&&clearTimeout(K),K=setTimeout(walli.next,1E3*L))}function Q(a){L+=a;
		0>L&&(L=0);osd.loc("delay",{s:L})}function ba(){css(document.body,"-sleep");document.body.onmousemove=document.body.ontouchstart=function(a){if(a.x!=ca||a.y!=da)ca=a.x,da=a.y,ba()};D&&clearTimeout(D);D=setTimeout(ja,1E3*ka)}function ja(){D&&(D=clearTimeout(D));css(document.body,"+sleep")}function X(a){R!==a&&((R=a)?(aa(),a=document.documentElement,a.requestFullscreen?a.requestFullscreen():a.mozRequestFullScreen?a.mozRequestFullScreen():a.webkitRequestFullScreen&&a.webkitRequestFullScreen(),css("#bplay",
		"+active"),css("#view","+play"),osd.loc("play"),ba()):(K&&(K=clearTimeout(K)),D&&(D=clearTimeout(D)),document.body.onmousemove=document.body.ontouchstart=!1,css(document.body,"-sleep"),css("#bplay","-active"),css("#view","-play"),osd.loc("stop")))}function E(a){Y!==a&&(Y=a,log.debug("switch to "+a+" mode"),r=!0,"tof"==Y?(F&&(clearInterval(F),F=!1),css(M,"+active"),css("#thumb","-active")):(r=!1,X(!1),osd.hide(),css(k[0],""),css(k[1],""),css(M,"-active"),css("#exif","-active"),css("#thumb","+active"),
		A()),V())}function ea(a){var d=_("#diapo"+n).parentNode,p=_("#minicom"+n);p&&d.removeChild(p);0<a&&append(d,'<span id="minicom'+n+'" class="minicom">'+a+"</span>")}function Z(a,d){if(d||r&&S!==a)S=a,r&&(css(k[1-m],""),C(1-m,2)),a?(css("#bcom","+active"),css(M,"+com"+(d?"fix":"")),hash.set("com",1)):(css("#bcom","-active"),css(M,"-com"),css(M,"-comfix"),hash.del("com")),r&&setTimeout(function(){C(m,0)},550)}function $(a){var d="",p=G[a];p&&p.length?(p.forEach(function(c,q){d+="<li><header>"+c.who+
		' <span title="'+c.when.replace("T"," ")+'">'+loc.reldate(c.when)+"</span></header><content>"+c.what.replace("\n","<br/>")+"</content>"+(c.own?'<button class="del" onclick="walli.rmcom(\''+a.replace("'","\\'")+"',"+c.id+')">'+loc.bdel+"</button>":"")+"</li>"}),_(O,d),O.scrollTop=O.scrollHeight,_("#comcount",g(p.length))):(_(O,loc.nocom),_("#comcount","0"))}function la(a,d){ajax({type:"POST",url:"?!=comment",data:{file:l[n],who:a,what:d},ok:function(a){G[a.file]=a.coms;$(l[n]);ea(a.coms.length);N.value=
		""},error:osd.error})}function ma(a,d){ajax({type:"POST",url:"?!=uncomment",data:{file:a,id:d},ok:function(a){G[a.file]=a.coms;$(l[n]);ea(a.coms.length)},error:osd.error})}function y(a){a&&a.stopPropagation&&a.stopPropagation()}function V(){r?hash.set("f",l[n]):s?hash.set("f",s):hash.del("f")}function fa(){var a=hash.get("f"),d=hash.get("com"),b=/^(.+\/)([^\/]*)$/.test(a)?RegExp.$1:"/",c=RegExp.$2;d?Z(!0,!0):S&&Z(!1,!0);return a.length?(d=function(){var d=l.indexOf(a),c=0;r&&d!=n&&(c=d<n?-1:1);-1!=
		d?walli.show(d,c):E("thumb")},b!=s?h(b,d):c?d():E("thumb"),!0):!1}function B(a,d,b){if(!r){var c=J[s]?J[s]:{x:0,y:0,n:0};b&&(c.x=a,c.y=d);c.o&&css(c.o,"-cursor");var q=_("#diapos li"),g=_("#diapos"),h=g.getBoundingClientRect(),e=[],m=!1,f=-1,l,k,n;if(q.length){for(;++f<q.length;)if(l=q[f].getBoundingClientRect(),l={t:l.top-h.top+g.scrollTop,w:l.width,h:l.height},l.w){k=Math.round(l.t+l.h/2);if(!1===m||k>m)m=k,e.push([]),n=e.length-1;e[n].push({t:l.t,b:l.t+l.h,n:f});b||c.n!=f||(c={x:a+e[n].length-
		1,y:d+n,n:f})}c.y>n?c.y=0:0>c.y&&(c.y=n);c.x>=e[c.y].length?c.x=0:0>c.x&&(c.x=e[c.y].length-1);k=e[c.y][c.x];c.o=q[k.n];c.n=k.n;l={t:g.scrollTop,b:g.scrollTop+h.height,h:h.height};n=l.t;k.b>l.b?n=k.b-l.h+30:k.t<l.t&&(n=k.t-30);g.scrollTop=n;css(c.o,"+cursor")}J[s]=c}}function ga(){r&&osd.info(b(l[n])+" <sup>"+(n+1)+"/"+l.length+"</sup>")}var L=5,ka=3,K=!1,F=!1,D=!1,U,s,l=[],I=[],z=[],n=!1,m=0,k=[],W=[],M,P,N,T=0,R=!1,r,u,t={},G=[],S,Y,O,ha,x=!1,H=!0,ca=0,da=0,J={},v={},ia={boxed:{make:function(){var a=
		v.size,d=v.margin,p=_("#diapos"),c=[0,a+2*d,2*a+4*d],q=[0,a,2*a+2*d],e=Math.floor(_(p).clientWidth/c[1]);1>e&&(e=1);if(e!=v.lastnc){v.lastnc=e;var f=0,m=0,k=[],n=I.length+l.length,r=function(a,d){var c,q;for(c=q=0;q<d;c+=1<<q++);return c<<a},s=function(a,d,c,q){for(c=r(a,c);k.length<d+q;)k.push(0);for(a=0;a<q;++a)if(k[d+a]&c)return!0;return!1},u=function(a,b,e,g,p){for(var f,h=r(b,g);k.length<e+p;)k.push(0);for(f=0;f<p;++f)k[e+f]|=h;css(a,{position:"absolute",margin:d,left:c[1]*b+"px",top:c[1]*e+
		"px",width:q[g]+"px",height:q[p]+"px"})},t=function(a,d,c,p){var h=function(){var c=document.createElement("img");c.onload=function(){osd.inc();log.debug(a+" loaded");css(this.parentNode,"+loaded")};c.onclick=d;return c}(),l=Math.random(),k=document.createElement("li");css(k,"diapo "+c);k.appendChild(h);k.setAttribute("title",b(a));void 0!=p&&(h.id="diapo"+p,(H||x)&&append(k,'<input type="checkbox" id="chk'+p+'" n="'+p+'" onchange="walli.zwap('+p+')"/><label for="chk'+p+'"></label>'));(G[a]||[]).length&&
		append(k,'<span class="minicom">'+g(G[a].length)+"</span>");diapos.appendChild(k);0==f&&0==m?(c=2,p=1<e?2:1):1<e-f&&0.5<l&&!s(f,m,2,1)?(c=0.8<l?2:1,p=2):(c=0.7<l?2:1,p=1);n<=e&&(c=1);u(k,f,m,p,c);for(h.src="?!=mini&file="+encodeURIComponent(a)+"&w="+q[p]+"&h="+q[c];s(f,m,1,1);)f++,f>=e&&(f=0,m++);n--};_("#diapos","");osd.start(l.length+I.length);I.forEach(function(a){t(a,function(){h(a)},"dir")});l.forEach(function(a,d){setTimeout(function(){t(a,function(){walli.show(d,0)},"",d)},d)})}},build:function(){v.lastnc=
		0;this.make()},resize:function(){this.make()},rotate:function(){this.make()}},"default":{build:function(){var a=function(a,p,c,q){var e=function(){var c=document.createElement("img");c.onload=function(){osd.inc();log.debug(a+" loaded");css(this.parentNode,"+loaded")};c.onclick=p;return c}(),f=document.createElement("li");css(f,"diapo "+c);f.appendChild(e);f.setAttribute("title",b(a));void 0!=q&&(e.id="diapo"+q,(H||x)&&append(f,'<input type="checkbox" id="chk'+q+'" n="'+q+'" onchange="walli.zwap('+
		q+')"/><label for="chk'+q+'"></label>'));(G[a]||[]).length&&append(f,'<span class="minicom">'+g(G[a].length)+"</span>");diapos.appendChild(f);e.src="?!=mini&file="+encodeURIComponent(a)+"&w="+v.size+"&h="+v.size};osd.start(l.length+I.length);I.forEach(function(d){a(d,function(){h(d)},"dir")});l.forEach(function(d,b){setTimeout(function(){a(d,function(){walli.show(b,0)},"",b)},b)})},resize:function(){},rotate:function(){}}};return{setup:function(a){ha=a.comments;U=a.refresh;x=a.god;H=a.zip;v=a.thumbnail||
		{engine:"default",size:150,margin:0};v.layout=ia[v.engine]||ia["default"];M=_("#view");O=_("#coms");u=_("#slide");u.onmousewheel=function(a){r&&(0>(a.wheelDelta||a.detail/3)?walli.prev():walli.next(),a.preventDefault())};var d=function(){k[m].className="animated";k[m].style.left=t.l+"px";t={}};u.onmousedown=u.ontouchstart=function(a){a.preventDefault();a.touches&&(a=a.touches[0]);k[m].className="touch";t={c:t.c||0,d:!0,m:!1,x:a.pageX,l:parseInt(k[m].style.left,10),h:setTimeout(d,1E3)}};u.onmousemove=
		u.ontouchmove=function(a){t.d&&(t.m=!0,a.preventDefault(),a.touches&&(a=a.touches[0]),a=a.pageX-t.x,k[m].style.left=t.l+a+"px",80<Math.abs(a)&&(clearTimeout(t.h),t={},k[m].className="animated",80<a?walli.prev():walli.next()))};u.onmouseup=u.onmouseout=u.ontouchend=u.ontouchcancel=function(a){a.preventDefault();t.d&&(clearTimeout(t.h),t.c?(clearTimeout(t.c),d(),walli.toggleplay()):t.m?d():t.c=setTimeout(function(){d();walli.next()},250))};k=[_("#img0"),_("#img1")];u.ondragstart=k[0].ondragstart=k[1].ondragstart=
		function(a){a.preventDefault();return!1};window.onresize=function(){r&&(k[1-m].className="",C(1-m,1),C(m,0));f();v.layout.resize()};window.onorientationchange=function(){var a=_("#viewport"),d=window.orientation||0;a.setAttribute("content",90==d||-90==d||270==d?"height=device-width,width=device-height,initial-scale=1.0,maximum-scale=1.0":"height=device-height,width=device-width,initial-scale=1.0,maximum-scale=1.0");r&&C(m,0);v.layout.rotate()};hotkeys.add("CTRL+D",function(){css("#log","*active")},
		!0).add(["SPACE","ENTER"],walli.toggleplay).add("C",walli.togglecom).add("HOME",walli.first).add("LEFT",walli.prev).add("RIGHT",walli.next).add("UP",walli.up).add("DOWN",walli.down).add("PAGEUP",walli.pgup).add("PAGEDOWN",walli.pgdown).add("END",walli.last).add(["ESC","BACKSPACE"],walli.back).add("DOWN",function(){!r&&l.length&&walli.show(0)}).add(["?","H"],function(){css("#help","*active")}).add(["T"],ga).add(["I"],walli.togglexif).add(["+"],function(){Q(1)}).add(["-"],function(){Q(-1)});_("#bprev").onclick=
		walli.prev;_("#bnext").onclick=walli.next;_("#bplay").onclick=walli.toggleplay;_("#bthumb").onclick=walli.thumb;_("#bzip").onclick=walli.dlzip;H||(_("#bzip").className="hide");ha&&(P=_("#who"),N=_("#what"),N.onfocus=P.onfocus=function(){X(!1)},_("#comments").onclick=y,_("#bcom").onclick=walli.togglecom,_("#bsend").onclick=walli.sendcom);x?(_("#blogout").onclick=walli.logout,FormData?(_("#iupload").onchange=function(a){a=new FormData;for(var d=0,c=this.files,b,e=0;e<c.length;++e)b=c[e],d+=b.size,a.append("file"+
		e,b);if(confirm(loc.tpl("uploadfiles",{z:loc.size(d),nb:c.length}))){var f=new XMLHttpRequest;f.open("POST","?!=img&path="+s);f.onload=function(){if(200==f.status){var a=JSON.parse(f.responseText);a.added&&h(s);osd.loc("uploaded",{nb:a.added})}else osd.error("error "+f.status)};f.upload.onprogress=function(a){event.lengthComputable&&osd.set(a.loaded,a.total)};f.send(a)}},_("#bupload").onclick=function(){_("#iupload").click()}):css("#bupload",{diplay:"none"}),_("#bdel").onclick=walli.del,_("#bdiag").onclick=
		walli.togglediag,_("#bflush").onclick=walli.flush,_("#bmkdir").onclick=walli.mkdir):a.admin&&(_("#blogin").onclick=walli.login);log.info("show on!");var b=_("#intro");if(b){var c=setTimeout(function(){css(b,"hide")},5E3);b.onclick=function(){clearTimeout(c);css(b,"hide")}}fa()||(E("thumb"),h("/"));hash.onchange(fa)},login:function(){document.location="?login"+document.location.hash},logout:function(){document.location="?logout"+document.location.hash},del:function(){if(x){var a=l.filter(function(a,
		b){return-1!=z.indexOf(b)});z.length?ajax({type:"POST",url:"?!=del",data:{files:a.join("*")},ok:function(a){a.deleted&&h(s);osd.loc("deleted",{nb:a.deleted})},error:osd.error}):osd.error(loc.noselection)}},togglexif:function(){css("#exif","*active");walli.exif()},exif:function(){var a=_("#exif.active");a.length&&(_(a,loc.load),ajax({url:"?!=exif",data:{file:l[n]},ok:function(d){var b,c,e;if(d&&d.exif)for(c in b="",d.exif){b+="<div><h3>"+c+"</h3><table>";for(e in d.exif[c])null!=d.exif[c][e]&&""!=
		d.exif[c][e]&&(b+="<tr><th>"+e+"</th><td>"+d.exif[c][e]+"</td></tr>");b+="</table></div>"}else b=loc.exifnotfound;_(a,b)},error:function(d){_(a,d)}}))},togglediag:function(){var a=_("#diag");a?document.body.removeChild(a):walli.diag()},diag:function(){x&&ajax({url:"?!=diag",data:{path:s},ok:function(a){var d=_("#diag"),b="<ul>",c;for(c in a.stats)b+='<li class="stat">'+("size"==c?loc.size(a.stats[c]):a.stats[c]+" "+c)+"</li>";for(c in a.checks)b+='<li class="'+(a.checks[c]?"ok":"bad")+'">'+c+(a.checks[c]?
		" enabled":" disabled")+"</li>";d||(d=document.createElement("div"),d.id="diag",d.onclick=function(){document.body.removeChild(d)},document.body.appendChild(d));d.innerHTML=b},error:osd.error})},flush:function(){x&&ajax({url:"?!=flush",ok:function(a){osd.loc("flushed",{nb:a.flushed})},error:osd.error})},mkdir:function(){var a;x&&(a=prompt(loc.mkdir))&&ajax({type:"POST",url:"?!=mkdir",data:{dir:a,path:s},ok:function(){h(s)},error:osd.error})},dlzip:function(){var a=z.length?l.filter(function(a,b){return-1!=
		z.indexOf(b)}):l;H&&a.length?(_("#bzip",loc.zip),ajax({type:"POST",url:"?!=zip",data:{files:a.join("*")},ok:function(a){document.location="?!=zip&zip="+a.zip;walli.zwap()},error:osd.error})):osd.loc("nozip")},zwap:function(a){void 0!=a&&(-1==z.indexOf(a)?z.push(a):z=z.filter(function(d){return d!=a}));z.length?(e("selected",loc.tpl("dlsel",{nb:z.length})),x&&(_("#bdel").className="")):(e("all",loc.dlall),x&&(_("#bdel").className="hide"))},thumb:function(){E("thumb")},show:function(a,d,b){l.length&&
		(n=0>a?l.length+a:a>=l.length?a%l.length:a,r||E("tof"),css("#mask","+active"),w(n,function(a,b){css("#mask","-active");r?m=1-m:d=0;W[m]={w:b.width,h:b.height};u.removeChild(k[m]);k[m].src=b.src;if(d)css(k[m],0>d?"left":"right"),C(m,d),u.appendChild(k[m]),C(m,0),css(k[m],"animated center"),css(k[1-m],"animated "+(0<d?"left":"right")),C(1-m,-d);else{css(k[m],"");var e=position("#diapo"+n);css(k[m],{width:e.width+"px",height:e.height+"px",left:e.left+"px",top:e.top+"px"});u.appendChild(k[m]);css(k[m],
		"animated");C(m,0)}aa();V();ga();walli.exif();1<l.length&&w((n+1)%l.length,function(){})}),$(l[n]))},next:function(a){y(a);r?walli.show(n+1,1):B(1,0)},prev:function(a){y(a);r?walli.show(n-1,-1):B(-1,0)},down:function(a){y(a);r?walli.show(n+1,1):B(0,1)},up:function(a){y(a);r?walli.show(n-1,-1):B(0,-1)},pgdown:function(a){y(a);r?walli.show(n+5,1):B(0,5)},pgup:function(a){y(a);r?walli.show(n-5,-1):B(0,-5)},first:function(a){y(a);r?walli.show(0,-1):B(0,0,!0)},last:function(a){y(a);r?walli.show(-1,1):
		B(-1,-1,!0)},play:function(a){y(a);l.length&&(r||walli.show(n,0),E("tof"))},stop:function(a){y(a);E("thumb")},toggleplay:function(a){y(a);r?X(!R):(J[s]||B(0,0,!0),J[s].o&&J[s].o.children[0].click(a))},togglecom:function(a){a&&a.stopPropagation();Z(!S)},sendcom:function(){1>P.value.length?(osd.loc("emptywho"),P.focus()):1>N.value.length?(osd.loc("emptywhat"),N.focus()):la(P.value,N.value)},rmcom:function(a,b){ma(a,b)},back:function(){if(r)return E("thumb");var a=s.split("/");1<a.length&&h(a.slice(0,
		a.length-2).join("/"))},cd:function(a){E("thumb");h(a)}}}();
	</script>
	<script>
		ready(function(){
			walli.setup({
				refresh: <?php print($REFRESH_DELAY) ?>,
				comments: <?php print($withcom?'true':'false')?>,
				admin: <?php print($withadm?'true':'false')?>,
				zip: <?php print($withzip?'true':'false')?>,
				god: <?php print($godmode?'true':'false')?>,
				thumbnail: <?php print(json_encode($THUMB)) ?>
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