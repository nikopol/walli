// WALLi JS
// https://github.com/nikopol/walli
// niko 2012-2013

/*LOCALE*/

var
	locales = {
		en: {
			title: {
				bnext: 'next',
				bprev: 'previous',
				bcom: 'comments',
				bthumb: 'thumbnail',
				bplay: 'slideshow',
				bupload: 'upload images',
				bflush: 'reset cache',
				bmkdir: 'new folder',
				bdiag: 'diagnostic',
				bdel: 'delete selected images'
			},
			holder: {
				who: 'enter your name…',
				what: 'enter your comment…'
			},
			text: {
				//bsend: '&#10004;',
				loading: 'loading…'
			},
			date: {
				now: 'now',
				min: '%d minute%s ago',
				hour: '%d hour%s ago',
				yesterday: 'yesterday',
				day: '%d day%s ago',
				week: '%d week%s ago',
				month: '%d month%s ago'
			},
			help: {
				th: ['keys',         'thumbnail mode',              'show mode'],
				tr: [
					['? | H',        'toggle this panel',           'toggle this panel'],
					['SPACE | ENTER','show the current selection',  'toggle slideshow on/off'],
					['HOME',         'select the first image',      'show the first image'],
					['END',          'select the last image',       'show the last image'],
					['ARROWS',       'change the selected image',   'show the previous/next image'],
					['ESC',          'go to the previous directory','back to the thumbnail mode'],
					['C',            '',                            'toggle comments panel'],
					['T',            '',                            'show image filename'],
					['I',            '',                            'show image informations'],
					['+ | -',        '',                            'change the slideshow timer']
				]
			},
			bdel: '&#10006;',
			nocom: 'be the first to comment',
			emptywho: "what's your name ?",
			emptywhat: 'say something…',
			play: '&#9654; PLAY',
			stop: '&#9632; STOP',
			dlall: 'download all',
			dlsel: 'download selected',
			zip: 'compressing…',
			load: 'loading…',
			nozip: 'nothing to download',
			updir: '',
			uploadfiles: 'upload %nb image%s (%z bytes) ?',
			flushed: '%nb file%s flushed',
			uploaded: '%nb file%s uploaded',
			deleted: '%nb file%s deleted',
			mkdir: 'folder name ?',
			delay: "slideshow set to %ss",
			exifnotfound: "no information available"
		},
		fr: {
			title: {
				bnext: 'suivante',
				bprev: 'précèdente',
				bcom: 'commentaires',
				bthumb: 'miniatures',
				bplay: 'diaporama',
				bupload: 'ajoute des images',
				bflush: 'vide le cache',
				bmkdir: 'nouveau dossier',
				bdiag: 'diagnostique',
				bdel: "efface les images sélectionnées"
			},
			holder: {
				who: 'entrez votre nom…',
				what: 'entrez votre commentaire…'
			},
			text: {
				loading: 'chargement…'
			},
			date: {
				now: "a l'instant",
				min: 'il y a %d minute%s',
				hour: 'il y a %d heure%s',
				yesterday: 'hier',
				day: 'il y a %d jour%s',
				week: 'il y a %d semaine%s',
				month: 'il y a %d mois'
			},
			help: {
				th: ['touches','mode miniatures','mode image'],
				tr: [
					['? | H','affiche/cache cette aide','affiche/cache cette aide'],
					['ESPACE | ENTRÉE','affiche la sélection','des/active le diaporama'],
					['DÉBUT','sélectionne la première image','affiche la première image'],
					['FIN','sélectionne la derniere image','affiche la derniere image'],
					['FLÈCHES','change la sélection',"affiche l'image suivante/précèdente"],
					['ÉCHAP','retourne au répertoire précèdent','retourne au mode miniatures'],
					['C','','affiche/cache les commentaires'],
					['T','',"affiche le nom de l'image"],
					['I','',"affiche des informations sur l'image"],
					['+ | -','','change le délai du diaporama']
				]
			},
			bdel: '&#10006;',
			nocom: 'soyez le premier à laisser un commentaire',
			emptywho: 'de la part de ?',
			emptywhat: 'dites quelque chose…',
			play: '&#9654; LECTURE',
			stop: '&#9632; STOP',
			dlall: 'tout télécharger',
			dlsel: 'télécharger la sélection',
			zip: 'compression…',
			load: 'chargement…',
			nozip: 'rien à télécharger',
			updir: '',
			uploadfiles: 'poster %nb image%s (%z octets) ?',
			flushed: '%nb fichier%s supprimé%s',
			uploaded: '%nb image%s ajoutée%s',
			deleted: '%nb image%s effacée%s',
			mkdir: 'nom du dossier ?',
			delay: "le diaporama passe à %ss",
			exifnotfound: "aucune information disponible"
		}
	},
	loc,
	setlocale = function(l){
		"use strict";
		var k, t, o;
		loc = locales[l]?locales[l]:locales.en;

		//return a date relatively to now
		loc.reldate = function(dat){
			var
				n = new Date().getTime(),
				d = new Date(dat).getTime(),
				e = (n-d)/1000,
				fmt = function(t,n){
					n = Math.round(n);
					return t
						.replace('%d',n)
						.replace('%s',n>1?'s':'')
				};
			if(e<60)      return loc.date.now;
			if(e<3600)    return fmt(loc.date.min,e/60); 
			if(e<86400)   return fmt(loc.date.hour,e/3600);
			if(e<172800)  return loc.date.yesterday;
			if(e<604800)  return fmt(loc.date.day,e/86400);
			if(e<2592000) return fmt(loc.date.week,e/604800);
			return fmt(loc.date.month,e/2592000)
		};
		loc.size = function(s) {
			if(s<2048)       return s+'b';
			if(s<1000000)    return Math.round(s/1024)+'kb';
			if(s<1000000000) return Math.round(s/1000000)+'M';
			return Math.round(s/1000000000)+'G';
		};
		loc.tpl = function(c,h) {
			var t = loc[c];
			for(var k in h) {
				t = t.replace(RegExp("%"+k,"g"),h[k]);
				if(k=="nb") t = t.replace(RegExp("%s","g"),h.nb>1?"s":"");
			}
			return t;
		};

		//set dom element's text
		if(loc.title)  for(k in loc.title)  if(o=_('#'+k)) o.setAttribute('title',loc.title[k]);
		if(loc.holder) for(k in loc.holder) if(o=_('#'+k)) o.setAttribute('placeholder',loc.holder[k]);
		if(loc.text)   for(k in loc.text)   _('#'+k,loc.text[k]);
		t = "<table><tr><th>"+loc.help.th.join('</th><th>')+"</th></tr>";
		for(k in loc.help.tr) t += "<tr><td>"+loc.help.tr[k].join('</td><td>')+"</td></tr>";
		_('#help',t);
	};
ready(function(){ setlocale(hash.get('lang')||navigator.language) });

/*LOG*/

var log = (function(){
	"use strict";
	var
		L, d,
		start = new Date().getTime(),
		level = { debug:1, info:2, warn:3, error:4},
		ull = hash.get('log'),
		ll = (ull && level[ull]) ? level[ull] : 0;
	if(!ll) return {
		debug: function(){},
		info: function(){},
		warn: function(){},
		error: function(){}
	};
	if(console.log) {
	 	L = function(lev,args){
	 		if(level[lev] >= ll) {
	 			var k, cls = ('     '+lev).substr(-5)+'|';
	 			for(k in args) console.log(cls+args[k]);
	 		}
	 	};
	} else {
		ready(function(){
			append(document.body,'<div id="log"></div>');
			d = _('#log');
		});
		L = function(lev,args){
			if(d && level[lev] >= ll) {
				var k, t=('000000'+(new Date().getTime()-start)).substr(-6);
				for(k in args) append(d,'<div class="'+lev+'"><span class="timer">'+t+'</span>'+args[k].replace(' ','&nbsp;')+'</div>');
				d.scrollTop = d.scrollHeight;
			}
		};
	}
	return {
		debug: function(){ L('debug',arguments); },
		info:  function(){ L('info',arguments); },
		warn:  function(){ L('warn',arguments); },
		error: function(){ L('error',arguments); }
	};
})();

/*OSD INFO*/

var osd;
osd = (function(){
	"use strict";
	var o, p, lab, max, val, cb, timerid = false;
	ready(function(){ 
		o = _('#osd');
		p = _('#progress');
	});
	return {
		/*TEXT*/
		hide: function(){
			if(timerid) timerid = clearTimeout(timerid);
			css(o,'-active');
		},
		show: function(){
			css(o,'+active');
		},
		error: function(msg){
			log.error(msg);
			osd.info(msg,'error',5000);
		},
		info: function(msg,cls,duration){
			_(o,msg).className = cls || '';
			if(timerid) clearTimeout(timerid);
			osd.show();
			timerid = setTimeout(osd.hide,duration || 3000);
		},
		loc: function(m,v){
			osd.info(loc.tpl(m,v));
		},
		/*PROGRESS BAR*/
		start: function(n,callback,msg){
			lab = msg || '%v/%m';
			max = n;
			cb = callback;
			if(max) {
				css(p,'+active');
				osd.set(0);
			}
		},
		set: function(n,m){
			if(!!m && m != max) {
				max = m;
				css(p,'+active');
			}
			val = n;
			_('#progresstext',lab.replace(/%v/,n).replace(/%m/,max));
			if(n >= max) {
				css(p,'-active');
				if(cb) cb();
			}
			_('#progressbar').style.width = max ? Math.floor(position('#progress').width*n/max)+'px' : 0;
		},
		inc: function(){ osd.set(++val) }
	};
})();

/*MAIN*/

var walli;
walli = (function(){
	"use strict";
	var
		DELAY = 5,         //delay for slideshow
		SLEEPDELAY = 3,    //delay for slideshow
		TOUCHDELTA = 80,   //touch move delta
		TOUCHTTL = 1000,   //max time for touch
		slideid = false,   //timer for slideshow
		checkid = false,   //timer for refresh
		sleepid = false,   //timer for sleep mode
		refresh,           //delay in s between checks
		path,              //current path
		files = [],        //files for current directory
		dirs = [],         //sub dirs for current directory
		chkfiles = [],        //selected files
		idx = false,       //current file
		nimg = 0,          //current img element
		img = [],          //img elements
		att = [],          //img attributes (w,h)
		view,              //view container element
		who,               //who input element for comment
		what,              //what input element for comment
		loading = 0,       //nb image currently loading
		playing = false,   //slideshow flag
		showing,           //photo(true)/diapo(false) flag
		slide,             //slide panel
		touch = {},        //touch status
		coms = [],         //comments for files
		comon,             //comments panel flag
		mode,              //display mode thumb/tof/zik/video
		cul,               //comments ul container
		comok,             //comments flag
		god = false,       //godmode flag
		zip = true,        //zip download flag
		smx = 0,           //sleep mouse pos
		smy = 0,           //sleep mouse pos
		cur = {};          //cursor pos

	function layout(){
		//auto resize diapos
		var p = position('#thumbbar');
		_('#diapos').style.top = (p.top+p.height)+'px';
	}

	function setbzip(c,t) {
		if(zip) {
			_('#bzip',t).className=c;
			layout();
		}
	}
	
	function loadimg(n,cb){
		var f = files[n],i,t=Date.now();
		i = new Image();
		loading++;
		i.onload = function(e){
			if(!e) e = window.event;
			var l = 'image #'+n+' '+f;
			log.info(l+' loaded in '+((Date.now()-t)/1000)+'s');
			cb(n,e.target||e.srcElement);
			loading--;
		};
		i.onerror = function(){
			var l = 'image #'+n+' '+f;
			log.error('error loading '+l);
			cb(n,null);
			loading--;
		};
		i.src = encodeURIComponent(f);
	}

	function cleantitle(f){
		f = /([^\/]+)\/$/.test(f)
			? RegExp.$1               //its a dir: remove parents
			: f.replace(/^.*\//g,''); //its a file: remove path
		return f
			.replace(/\.[^\.]*$/,'') //remove extension
			.replace(/[\._\|]/g,' ') //translate separator into spaces
		;
	}

	function numk(n){
		return n > 999 ? Math.floor(n/1000)+'K+' : n
	}

	function unsetcheck() {
		if(checkid) {
			clearInterval(checkid);
			checkid=false;
		}
	}

	function setupcheck() {
		if(refresh && !checkid)
			checkid = setInterval(function(){
				log.debug('refresh required');
				ajax('?!=count&path='+path, function(c){
					if(dirs.length!=c.dirs || files.length!=c.files) loadpath(path);
				});
			},refresh*1000);
	}

	function loadpath(p,cb){
		unsetcheck();
		log.debug('loading path '+(p || '/'));
		setbzip('hide',loc.dlall);
		if(god) _('#bdel').className='hide';
		chkfiles = [];
		ajax('?!=ls&path='+p, function(ls){
			var diapos = _('#diapos','');
			path = ls.path;
			log.info((path || '/')+'loaded with '+ls.dirs.length+' subdirs and '+ls.files.length+' files found');
			if(path.length){
				var sub = path.replace(/[^\/]+\/$/,'/');
				var d = document.createElement('li');
				css(d,'diapo up loaded');
				d.setAttribute('title',loc.updir);
				d.onclick = function(){loadpath(sub)};
				diapos.appendChild(d);
				var rp = '', r='';
				path.split('/').forEach(function(n){
					if(n){
						rp += n+'/';
						r += '<button onclick="walli.cd(\''+rp+'\')">'+n+'</button>';
					}
				});
				_('#path',r);
				layout();
			} else
				_('#path','');
			var add = function(url,click,cls,id){
				var image = (function(){
					var
						n = files.length-1,
						o = document.createElement('img'),
						u = url;
					o.onload = function(){
						osd.inc();
						log.debug(u+' loaded');
						css(this.parentNode,'+loaded');
					};
					o.onclick = click;
					return o;
				})();
				var d = document.createElement('li');
				css(d,'diapo '+cls);
				d.appendChild(image);
				d.setAttribute('title',cleantitle(url));
				if(id != undefined){
					image.id = 'diapo'+id;
					if(zip || god) append(d,'<input type="checkbox" id="chk'+id+'" n="'+id+'" onchange="walli.zwap('+id+')"/><label for="chk'+id+'"></label>');
				}
				if((coms[url]||[]).length)
					append(d,'<span class="minicom">'+numk(coms[url].length)+'</span>');
				diapos.appendChild(d);
				image.src = '?!=mini&file='+encodeURIComponent(url);
			};
			files = ls.files;
			dirs = ls.dirs;
			coms = ls.coms;
			if(cb) cb();
			osd.start(files.length+dirs.length);
			ls.dirs.forEach(function(d){ add(d,function(){loadpath(d)},'dir') });
			//delayed loading for big folder
			ls.files.forEach(function(d,i){ setTimeout(function(){add(d,function(){walli.show(i,0)},'',i)},i) });
			if(ls.files.length && zip) setbzip('all');
			sethash();
			setupcheck();
			if(god && _('#diag')) walli.diag();
			if(cur[path]) cursor(0,0);
		});
	}

	function calcpos(n,p){
		if(!att[n]) return;
		var
			ww = slide.clientWidth,
			wh = slide.clientHeight,
			a = att[n],
			h = a.h,
			w = a.w;
		if(w > ww){ w = ww; h = Math.floor(w*(a.h/a.w)); }
		if(h > wh){ h = wh; w = Math.floor(h*(a.w/a.h)); }
		css(img[n],{
			width: w+'px',
			height: h+'px',
			left: Math.floor((ww-w)/2+(ww*p*2))+'px',
			top: Math.floor((wh-h)/2)+'px'
		});
		//log.debug("calcpos("+n+","+p+")="+img[n].style.left);
	}

	function fullscreen(){
		var e = document.documentElement;
		if(e.requestFullscreen)            e.requestFullscreen();
		else if(e.mozRequestFullScreen)    e.mozRequestFullScreen();
		else if(e.webkitRequestFullScreen) e.webkitRequestFullScreen();
	}

	function setplaytimer(){
		if(playing) {
			if(slideid) clearTimeout(slideid);
			slideid = setTimeout(walli.next,DELAY*1000);
		}
	}

	function setdelay(d) {
		DELAY += d;
		if(DELAY<0) DELAY = 0;
		osd.loc('delay',{s:DELAY});
	}

	function checksleep() {
		css(document.body,'-sleep');
		document.body.onmousemove = 
		document.body.ontouchstart = function(e){
			if(e.x!=smx || e.y!=smy) {
				smx = e.x;
				smy = e.y;
				checksleep();
			}
		}
		if(sleepid) clearTimeout(sleepid);
		sleepid = setTimeout(setsleep,SLEEPDELAY*1000);
	}

	function unchecksleep() {
		if(sleepid) sleepid = clearTimeout(sleepid);
		document.body.onmousemove =
		document.body.ontouchstart = false;
		css(document.body,'-sleep');
	}

	function setsleep() {
		if(sleepid) sleepid = clearTimeout(sleepid);
		css(document.body,'+sleep');
	}

	function setplay(b){
		if(playing === b) return;
		playing = b;
		if(b){
			setplaytimer();
			fullscreen();
			css('#bplay','+active');
			css('#view','+play');
			osd.loc('play');
			checksleep();
		} else {
			if(slideid) slideid = clearTimeout(slideid);
			unchecksleep();
			css('#bplay','-active');
			css('#view','-play');
			osd.loc('stop');
		}
	}

	function setmode(m){
		if(mode === m) return;
		mode = m;
		log.debug('switch to '+m+' mode');
		showing = true;
		if(mode=="tof"){
			unsetcheck();
			css(view,'+active');
			css('#thumb','-active');
		//}else if(mode=="zik"){
		//}else if(mode=="movie"){
		}else{ //thumb
			showing = false;
			setplay(false);
			osd.hide();
			css(img[0],'');
			css(img[1],'');
			css(view,'-active');
			css('#exif','-active');
			css('#thumb','+active');
			setupcheck();
		}
		sethash();
	}

	function setminicom(n){
		var d = _('#diapo'+idx).parentNode,
		    c = _('#minicom'+idx);
		if(c) d.removeChild(c);
		if(n>0) append(d,'<span id="minicom'+idx+'" class="minicom">'+n+'</span>');
	}

	function setcom(b,fix){
		if(!fix && (!showing || comon === b)) return;
		comon = b;
		if(showing){
			css(img[1-nimg],'');
			calcpos(1-nimg,2);
		}
		if(b) {
			css('#bcom','+active');
			css(view,'+com'+(fix ? 'fix' : ''));
			hash.set('com',1);
		} else {
			css('#bcom','-active');
			css(view,'-com');
			css(view,'-comfix');
			hash.del('com');
		}
		if(showing)
			setTimeout(function(){
				calcpos(nimg,0)
			},550);
	}

	function loadcoms(f){
		var
			h = '',
			l = coms[f];
		if(l && l.length) {
			l.forEach(function(c,n){
				h +=
'<li>'+
	'<header>'+c.who+' <span title="'+c.when.replace('T',' ')+'">'+loc.reldate(c.when)+'</span></header>'+
	'<content>'+c.what.replace("\n","<br/>")+'</content>'+
	(c.own?'<button class="del" onclick="walli.rmcom(\''+f.replace("'","\\'")+'\','+c.id+')">'+loc.bdel+'</button>':'')+
'</li>';
			});
			_(cul,h);
			cul.scrollTop = cul.scrollHeight;
			_('#comcount',numk(l.length));
		} else {
			_(cul,loc.nocom);
			_('#comcount','0');
		}
	}

	function addcom(name,msg){
		ajax({
			type: 'POST',
			url: '?!=comment',
			data: { file:files[idx], who:name, what:msg },
			ok: function(d){
				coms[d.file] = d.coms;
				loadcoms(files[idx]);
				setminicom(d.coms.length);
				what.value = '';
			},
			error: osd.error
		});
	}

	function delcom(file,id){
		ajax({
			type: 'POST',
			url: '?!=uncomment',
			data: { file:file, id:id },
			ok: function(d){
				coms[d.file] = d.coms;
				loadcoms(files[idx]);
				setminicom(d.coms.length);
			},
			error: osd.error
		});
	}

	function stopev(e){ if(e && e.stopPropagation) e.stopPropagation(); }

	function sethash(){
		if(showing) {
			hash.set('f',files[idx]);
		} else {
			if(path) hash.set('f',path);
			else hash.del('f');
		}
	}

	function gethash(){
		var
			f = hash.get('f'),
			c = hash.get('com'),
			p = /^(.+\/)([^\/]*)$/.test(f) ? RegExp.$1 : '/',
			z = RegExp.$2;
		//coms display
		if(c)          setcom(true,true);
		else if(comon) setcom(false,true);
		//path
		if(f.length) {
			var setimg = function(){
				var
					n = files.indexOf(f),
					p = 0;
				if(showing && n!=idx) p=n<idx?-1:1;
				if(n != -1) walli.show(n,p);
				else        setmode('thumb');
			}
			if(p != path) loadpath(p, setimg);
			else if(z)    setimg();
			else          setmode('thumb');
			return true;
		}
		return false;
	}

	function cursor(dx,dy,abs) {
		if( !showing ) {
			var c = cur[path] ? cur[path] : {x:0,y:0,n:0};
			if(abs) { 
				c.x = dx;
				c.y = dy;
			}
			if(c.o) css(c.o,'-cursor');
			var	
				dia = _('#diapos li'), 
				d = _('#diapos'), 
				dr = d.getBoundingClientRect(),
				tab = [],
				top = false,
				n = -1,
				p, t, y;
			if( dia.length ) {
				//calc diapos table
				while(++n < dia.length) {
					p = dia[n].getBoundingClientRect();
					p = {
						t: p.top-dr.top+d.scrollTop,
						w: p.width,
						h: p.height
					};
					if( p.w ) {
						t = Math.round(p.t+p.h/2);
						if( top===false || t > top ) {
							top = t;
							tab.push([]);
							y = tab.length-1;
						}
						tab[y].push({
							t: p.t,
							b: p.t+p.h,
							n: n
						});
						if(!abs && c.n==n) c={x:dx+tab[y].length-1,y:dy+y,n:n};
					}
				}
				//bound cursor
				if( c.y > y )                c.y = 0;
				else if( c.y < 0 )           c.y = y;
				if( c.x >= tab[c.y].length ) c.x = 0;
				else if( c.x < 0 )           c.x = tab[c.y].length-1;
				t = tab[c.y][c.x];
				c.o = dia[t.n];
				c.n = t.n;
				//autoscroll
				p = {
					t: d.scrollTop,
					b: d.scrollTop+dr.height,
					h: dr.height
				};
				y = p.t;
				if( t.b > p.b )      y = t.b-p.h+30;
				else if( t.t < p.t ) y = t.t-30;
				d.scrollTop = y;
				css(c.o,'+cursor');
			}
			cur[path] = c;
		}
	}

	function showtitle() {
		if(showing) osd.info(cleantitle(files[idx])+' <sup>'+(idx+1)+'/'+files.length+'</sup>');
	}

	return {
		setup: function(o) {
			comok = o.comments;
			refresh = o.refresh;
			god = o.god;
			zip = o.zip;
	
			view = _('#view');
			cul = _('#coms');

			slide = _('#slide');
			//slide.onresize = function(){ log.debug('slide='+this.clientWidth); };
			slide.onmousewheel = function(e){
				if(showing) {
					var delta = e.wheelDelta || e.detail/3;
					if(delta<0) walli.prev();
					else walli.next();
					e.preventDefault();
				}
			};

			var noswipe = function(){
				img[nimg].className = 'animated';
				img[nimg].style.left = touch.l+"px";
				touch = {};
			};

			slide.onmousedown = 
			slide.ontouchstart = function(e){
				e.preventDefault();
				if(e.touches) e = e.touches[0];
				img[nimg].className = 'touch';
				touch = {
					d: true,
					x: e.pageX,
					l: parseInt(img[nimg].style.left,10),
					h: setTimeout(noswipe,TOUCHTTL)
				};
			};

			slide.onmousemove = 
			slide.ontouchmove = function(e){
				if(touch.d) {
					e.preventDefault();
					if(e.touches) e = e.touches[0];
					var dx = e.pageX-touch.x;
					img[nimg].style.left = (touch.l+dx)+"px";
					if(Math.abs(dx)>TOUCHDELTA) {
						clearTimeout(touch.h);
						touch = {};
						img[nimg].className = 'animated';
						dx>TOUCHDELTA ? walli.prev() : walli.next();
					}
				}
			};

			slide.onmouseup = 
			slide.onmouseout = 
			slide.ontouchend = 
			slide.ontouchcancel = function(e){
				e.preventDefault();
				if(touch.d) {
					clearTimeout(touch.h);
					noswipe();
				}
			};
		
			img = [_('#img0'), _('#img1')];
			slide.ondragstart = 
			img[0].ondragstart =
			img[1].ondragstart = function(e){
				e.preventDefault();
				return false;
			};
			
			window.onresize = function(){ 
				if(showing) {
					img[1-nimg].className = '';
					calcpos(1-nimg,1);
					calcpos(nimg,0);
				}
				layout();
			};
			window.onorientationchange = function(){
				var
					v = _('#viewport'),
					o = window.orientation || 0;
				v.setAttribute('content', (o == 90 || o == -90 || o == 270)
					? 'height=device-width,width=device-height,initial-scale=1.0,maximum-scale=1.0'
					: 'height=device-height,width=device-width,initial-scale=1.0,maximum-scale=1.0'
				);
				if(showing) calcpos(nimg,0);
			}

			hotkeys
				.add('CTRL+D',function(){ css('#log','*active') },true)
				.add(['SPACE','ENTER'],walli.toggleplay)
				.add('C',walli.togglecom)
				.add('HOME',walli.first)
				.add('LEFT',walli.prev)
				.add('RIGHT',walli.next)
				.add('UP',walli.up)
				.add('DOWN',walli.down)
				.add('PAGEUP',walli.pgup)
				.add('PAGEDOWN',walli.pgdown)
				.add('END',walli.last)
				.add(['ESC','BACKSPACE'],walli.back)
				.add('DOWN',function(){if(!showing && files.length)walli.show(0)})
				.add(['?','H'],function(){ css('#help','*active') })
				.add(['T'],showtitle)
				.add(['I'],walli.togglexif)
				.add(['+'],function(){ setdelay(1) })
				.add(['-'],function(){ setdelay(-1) })
			;

			_('#bprev').onclick    = walli.prev;
			_('#bnext').onclick    = walli.next;
			_('#bplay').onclick    = walli.toggleplay;
			_('#bthumb').onclick   = walli.thumb;
			_('#bzip').onclick     = walli.dlzip;

			if(!zip) _('#bzip').className = 'hide';

			if(comok) {
				who = _('#who');
				what = _('#what');
				what.onfocus =
				who.onfocus = function(){ setplay(false); };
				_('#comments').onclick = stopev;
				_('#bcom').onclick     = walli.togglecom;
				_('#bsend').onclick    = walli.sendcom;
			}

			if(god) {
				//logout
				_('#blogout').onclick=walli.logout;
				//upload
				if(FormData) {
					_('#iupload').onchange=function(e){
						var fdata = new FormData(), size=0, files=this.files;
						for(var f,i=0;i<files.length;++i) {
							f=files[i];
							size += f.size;
							fdata.append('file'+i,f);
						}
						if(confirm(loc.tpl('uploadfiles',{z:loc.size(size),nb:files.length}))) {
							var xhr = new XMLHttpRequest();
							xhr.open('POST', '?!=img&path='+path);
							xhr.onload = function(){
								if(xhr.status == 200) {
									var d = JSON.parse(xhr.responseText);
									osd.loc('uploaded',{nb:d.added});
									if(d.added) loadpath(path);
								} else 
									osd.error("error "+xhr.status);
							};
							xhr.upload.onprogress = function(e){
								if(event.lengthComputable)
									osd.set(e.loaded,e.total);
							};
							xhr.send(fdata);
						}
					};
					_('#bupload').onclick=function(){
						_('#iupload').click();
					};
				} else
					css('#bupload',{diplay:'none'});
				//del
				_('#bdel').onclick=walli.del;
				//diag
				_('#bdiag').onclick=walli.togglediag;
				//reset
				_('#bflush').onclick=walli.flush;
				//mkdir
				_('#bmkdir').onclick=walli.mkdir;
			} else if(o.admin)
				_('#blogin').onclick=walli.login;
			
			log.info("show on!");


			// window.onwebkitvisibilitychange=function(e){
			// 	if ( document.webkitHidden ) {

			//manage intro
			var i = _('#intro');
			if(i) {
				var itimer = setTimeout(function(){ css(i,'hide'); }, 5000);
				i.onclick=function(){
					clearTimeout(itimer);
					css(i,'hide');
				}
			}

			//manage permalink
			if(!gethash()){
				setmode('thumb');
				loadpath('/');
			}
			hash.onchange(gethash);
		},
		login: function(){
			document.location = '?login'+document.location.hash;
		},
		logout: function(){
			document.location = '?logout'+document.location.hash;
		},
		del: function(){
			if(god) {
				var lst = files.filter(function(f,n){return chkfiles.indexOf(n)!=-1});
				if(chkfiles.length){
					ajax({
						type: 'POST',
						url: '?!=del',
						data: {files:lst.join('*')},
						ok: function(d){
							osd.loc('deleted',{nb:d.deleted});
							if(d.deleted) loadpath(path);
						},
						error: osd.error
					});
				} else
					osd.error(loc.noselection);
			}
		},
		togglexif: function(){
			css('#exif','*active');
			walli.exif();
		},
		exif: function(){
			var b = _('#exif.active');
			if(b.length) {
				_(b,loc.load);
				ajax({
					url: '?!=exif',
					data: {file:files[idx]},
					ok: function(d){
						var h,k,j;
						if(d && d.exif) {
							h = '';
							for(k in d.exif) {
								h += '<div><h3>'+k+'</h3><table>';
								for(j in d.exif[k])
									if(d.exif[k][j]!=null && d.exif[k][j]!="") h += '<tr><th>'+j+'</th><td>'+d.exif[k][j]+'</td></tr>';
								h += '</table></div>';
							}
						} else
							h = loc.exifnotfound;
						_(b,h);
					},
					error: function(e){ _(b,e) }
				});
			}
		},
		togglediag: function(){
			var b = _('#diag');
			if(b) document.body.removeChild(b); else walli.diag();
		},
		diag: function(){
			if(god)
				ajax({
					url: '?!=diag',
					data: {path:path},
					ok: function(d){
						var h = _('#diag'), l = '<ul>';
						for(var k in d.stats) l += '<li class="stat">'+(k=='size'?loc.size(d.stats[k]):d.stats[k]+' '+k)+'</li>';
						for(var k in d.checks) l += '<li class="'+(d.checks[k]?'ok':'bad')+'">'+k+(d.checks[k]?' enabled':' disabled')+'</li>';
						if(!h){
							h = document.createElement('div');
							h.id='diag';
							h.onclick=function(){ document.body.removeChild(h) };
							document.body.appendChild(h);
						}
						h.innerHTML = l;
					},
					error: osd.error
				});
		},
		flush: function(){
			if(god)
				ajax({
					url: '?!=flush',
					ok: function(d){
						osd.loc('flushed',{nb:d.flushed});
					},
					error: osd.error
				});
		},
		mkdir: function(){
			var d;
			if(god && (d=prompt(loc.mkdir)))
				ajax({
					type: 'POST',
					url: '?!=mkdir',
					data: {dir:d,path:path},
					ok: function(){
						loadpath(path);
					},
					error: osd.error
				});
		},
		dlzip: function(){
			var lst = chkfiles.length
				? files.filter(function(f,n){return chkfiles.indexOf(n)!=-1})
				: files;
			if(zip && lst.length){
				_('#bzip',loc.zip);
				ajax({
					type: 'POST',
					url: '?!=zip',
					data: {files:lst.join('*')},
					ok: function(d){
						document.location='?!=zip&zip='+d.zip;
						walli.zwap();
					},
					error: osd.error
				});
			} else
				osd.loc('nozip');
		},
		zwap: function(n) {
			if(n!=undefined) {
				if(chkfiles.indexOf(n)==-1) chkfiles.push(n);
				else chkfiles=chkfiles.filter(function(i){return i!=n});
			}
			if(chkfiles.length) {
				setbzip('selected',loc.tpl('dlsel',{nb:chkfiles.length}));
				if(god) _('#bdel').className='';
			} else {
				setbzip('all',loc.dlall);
				if(god) _('#bdel').className='hide';
			}

		},
		thumb: function() {
			setmode('thumb');
		},
		show: function(n,p,cb) {
			if(!files.length) return;
			idx = 
				n < 0 ? files.length+n :
				n >= files.length ? n%files.length :
				n;
			//log.debug('display #'+idx+' '+files[idx]);
			if(!showing) setmode('tof');
			css('#mask','+active');
			loadimg(idx,function(ni,i){
				//log.debug("load "+ni+" ok p="+p);
				css('#mask','-active');
				if(showing) nimg = 1-nimg; //switch image element idx
				else p=0;                  //zoom from thumbnail
				att[nimg] = { w:i.width, h:i.height };
				slide.removeChild(img[nimg]); /*remove&append to force redraw*/
				img[nimg].src = i.src;
				if(p) {
					//slide
					css(img[nimg],p<0?'left':'right');
					calcpos(nimg,p);					
					slide.appendChild(img[nimg]);
					calcpos(nimg,0);
					css(img[nimg],'animated center');				
					css(img[1-nimg],'animated '+(p>0?'left':'right'));
					calcpos(1-nimg,-p);
				} else {
					//zoom from diapo
					css(img[nimg],'');
					var z = position('#diapo'+idx);
					css(img[nimg],{
						width: z.width+'px',
						height: z.height+'px',
						left: z.left+'px',
						top: z.top+'px'
					});
					slide.appendChild(img[nimg]);
					css(img[nimg],'animated');
					calcpos(nimg,0);
				}
				setplaytimer();
				sethash();
				showtitle();
				walli.exif();
				if(files.length>1) loadimg((idx+1)%files.length,function(){});
			});
			loadcoms(files[idx]);
		},
		next: function(e){
			stopev(e);
			if(showing) walli.show(idx+1,1);
			else        cursor(1,0);
		},
		prev: function(e){ 
			stopev(e);
			if(showing) walli.show(idx-1,-1);
			else        cursor(-1,0);
		},
		down: function(e){
			stopev(e);
			if(showing) walli.show(idx+1,1);
			else        cursor(0,1);
		},
		up: function(e){ 
			stopev(e);
			if(showing) walli.show(idx-1,-1);
			else        cursor(0,-1);
		},
		pgdown: function(e){
			stopev(e);
			if(showing) walli.show(idx+5,1);
			else        cursor(0,5);
		},
		pgup: function(e){ 
			stopev(e);
			if(showing) walli.show(idx-5,-1);
			else        cursor(0,-5);
		},
		first: function(e){
			stopev(e);
			if(showing) walli.show(0,-1);
			else        cursor(0,0,true);
		},
		last: function(e){
			stopev(e);
			if(showing) walli.show(-1,1);
			else        cursor(-1,-1,true);
		},
		play: function(e){
			stopev(e);
			if(files.length) {
				if(!showing) walli.show(idx,0);
				setmode('tof');
			}
		},
		stop: function(e){
			stopev(e);
			setmode('thumb');
		},
		toggleplay: function(e){
			stopev(e);
			if(showing) setplay(!playing);
			else {
				if(!cur[path]) cursor(0,0,true);
				if(cur[path].o) cur[path].o.children[0].click(e);
			}
		},
		togglecom: function(e){
			if(e) e.stopPropagation();
			setcom(!comon);
		},
		sendcom: function(){
			if(who.value.length<1) {
				osd.loc('emptywho');
				who.focus();
			} else if(what.value.length<1) {
				osd.loc('emptywhat');
				what.focus();
			} else
				addcom(who.value,what.value);
		},
		rmcom: function(f,id){
			delcom(f,id);
		},
		back: function(){
			if(showing) return setmode('thumb');
			var d = path.split('/');
			if(d.length>1) 
				loadpath(d.slice(0,d.length-2).join('/'))
		},
		cd: function(p){
			setmode('thumb');
			loadpath(p);
		}
	};
})();