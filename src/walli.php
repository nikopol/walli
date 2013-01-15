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
	<meta http-equiv="X-UA-Compatible" content="IE=Edge"/>
	<!--<meta id="viewport" name="viewport" content="height=device-height,width=device-width,initial-scale=1.0,maximum-scale=1.0"/>-->
	<meta name="apple-mobile-web-app-capable" content="yes"/>
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<link rel="shorcut icon" type="image/png" href="icons/favicon.png" />
	<link href="css/walli.css" rel="stylesheet" type="text/css"/>
<?php if( preg_match('/android|ipad|mobile/mi',$_SERVER['HTTP_USER_AGENT']) ){ ?>
	<link href="css/mobile.css" rel="stylesheet" type="text/css"/>	
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
	<script src="js/ie.js"></script>
	<![endif]-->

	<script src="js/miniko.js"></script>
	<script src="js/minitools.js"></script>
	<script src="js/walli.js"></script>
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