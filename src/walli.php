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
@include('themes/theme.inc.php');

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
	<link rel="shorcut icon" type="image/png" href="themes/favicon.png" />
	<link href="themes/theme.css" rel="stylesheet" type="text/css"/>
<?php if( preg_match('/android|ipad|mobile/mi',$_SERVER['HTTP_USER_AGENT']) ){ ?>
	<link href="themes/mobile.css" rel="stylesheet" type="text/css"/>	
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
	<script src="js/ie.min.js"></script>
	<![endif]-->

	<script src="js/miniko.min.js"></script>
	<script src="js/minitools.min.js"></script>
	<script src="js/walli.js"></script>
	<script>
		ready(function(){
			walli.setup({
				refresh: <?php print($REFRESH_DELAY) ?>,
				comments: <?php print($withcom?'true':'false') ?>,
				admin: <?php print($withadm?'true':'false') ?>,
				zip: <?php print($withzip?'true':'false') ?>,
				god: <?php print($godmode?'true':'false') ?>,
				thumbnail: <?php print(json_encode($THUMB)) ?>,
				slider: "<?php print($SLIDER?$SLIDER:'default')?>"
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