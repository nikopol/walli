// WALLI JS
// https://github.com/nikopol/walli
// niko 2012-2013

"use strict";

/*LOCALE*/

var
	locales = {
		en: {
			title: {
				bnext: 'next',
				bprev: 'previous',
				bcom: 'comments',
				bthumb: 'thumbnail',
				bplay: 'slideshow'
			},
			holder: {
				who: 'enter your name…',
				what: 'enter your comment…'
			},
			text: {
				loading: 'loading…'
				//bsend: '&#10004;'
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
			bdel: '&#10006;',
			nocom: 'be the first to comment',
			emptywho: "what's your name ?",
			emptywhat: 'say something…',
			play: '&#9654; PLAY',
			stop: '&#9632; STOP',
			dlall: 'download all',
			dlsel: 'download selected',
			zip: 'compressing…',
			nozip: 'nothing to download',
			updir: ''
		},
		fr: {
			title: {
				bnext: 'suivante',
				bprev: 'précèdente',
				bcom: 'commentaires',
				bthumb: 'miniatures',
				bplay: 'diaporama'
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
			bdel: '&#10006;',
			nocom: 'soyez le premier à laisser un commentaire',
			emptywho: 'de la part de ?',
			emptywhat: 'dites quelque chose…',
			play: '&#9654; LECTURE',
			stop: '&#9632; STOP',
			dlall: 'tout télécharger',
			dlsel: 'télécharger la sélection',
			zip: 'compression…',
			nozip: 'rien à télécharger',
			updir: ''
		}
	},
	loc,
	setlocale = function(l){
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

		//set dom element's text
		if(loc.title)  for(k in loc.title)  if(o=_('#'+k)) o.setAttribute('title',loc.title[k]);
		if(loc.holder) for(k in loc.holder) if(o=_('#'+k)) o.setAttribute('placeholder',loc.holder[k]);
		if(loc.text)   for(k in loc.text)   _('#'+k,loc.text[k]);
	};
ready(function(){ setlocale(navigator.language) });

/*LOG*/

var log = (function(){
	var
		L, _l,
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
			_l = _('#log');
		});
		L = function(lev,args){
			if(_l && level[lev] >= ll) {
				var k, t=('000000'+(new Date().getTime()-start)).substr(-6);
				for(k in args) append(_l,'<div class="'+lev+'"><span class="timer">'+t+'</span>'+args[k].replace(' ','&nbsp;')+'</div>');
				_l.scrollTop = _l.scrollHeight;
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

var osd = (function(){
	var _o, lab, max, val, cb, timerid = false;
	ready(function(){ _o=_('#osd') });
	return {
		hide: function(){
			timerid = false;
			css(_o,'-active');
		},
		show: function(){
			css(_o,'+active');
		},
		loading: function(n,callback,msg){
			lab = msg || 'LOADING';
			max = n;
			cb = callback;
			this.show();
			this.set(0);
		},
		set: function(n){
			val = n;
			_(_o,lab+' '+n+'/'+max);
			if(n >= max) {
				this.hide();
				if(cb) cb();
			}
		},
		inc: function(){ this.set(++val) },
		error: function(msg){
			this.info(msg,'error',3000);
		},
		info: function(msg,cls,duration){
			_(_o,msg).className = cls || '';
			this.show();
			if(timerid) clearTimeout(timerid);
			timerid = setTimeout(this.hide,duration || 1500);
		}
	};
})();

/*MAIN*/

var walli;
walli = (function(){

	var
		DELAY = 5,         //delay for slideshow
		TOUCHDELTA=80,     //touch move delta
		TOUCHTTL=1000,     //max time for touch
		slideid = false,   //timer for slideshow
		checkid = false,   //timer for refresh
		refresh,           //delay in s between checks
		path,              //current path
		files = [],        //files for current directory
		dirs = [],         //sub dirs for current directory
		tozip = [],        //selected files
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
		comok;             //comments flag

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
		//i.src = '?!=img&file='+encodeURIComponent(f);
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
		var diapos = _('#diapos',''), rail = _('#path','');
		_('#bzip',loc.dlall).className='hide';
		tozip=[];
		ajax('?!=ls&path='+p, function(ls){
			path = ls.path;
			log.info((path || '/')+'loaded with '+ls.dirs.length+' subdirs and '+ls.files.length+' files found');
			if(path.length){
				var sub = path.replace(/[^\/]+\/$/,'/');
				var d = document.createElement('li');
				css(d,'diapo up loaded');
				d.setAttribute('title',loc.updir);
				d.onclick = function(){loadpath(sub)};
				diapos.appendChild(d);
			}
			var add = function(url,click,cls,id){
				var image = (function(){
					var
						n = files.length-1,
						o = document.createElement('img'),
						u = url;
					o.onload = function(){
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
					append(d,'<input type="checkbox" id="chk'+id+'" n="'+id+'" onchange="walli.zwap('+id+')"/><label for="chk'+id+'"></label>');
				}
				if((coms[url]||[]).length)
					append(d,'<span class="minicom">'+numk(coms[url].length)+'</span>');
				diapos.appendChild(d);
				image.src = '?!=mini&file='+encodeURIComponent(url);
			};
			files = ls.files;
			dirs = ls.dirs;
			coms = ls.coms;
			refresh = ls.refresh;
			ls.dirs.forEach(function(d){ add(d,function(){loadpath(d)},'dir') });
			ls.files.forEach(function(d,i){ add(files[i],function(){walli.show(i,0)},'',i) });
			if(ls.files.length) _('#bzip').className='all';
			sethash();
			if(path){
				var rp = '';
				path.split('/').forEach(function(n){
					if(n){
						rp += n+'/';
						append(rail,'<button onclick="walli.cd(\''+rp+'\')">'+n+'</button>');
					}
				});
			}
			if(cb) cb();
			setupcheck();
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
			left: Math.floor((ww-w)/2+(ww*p))+'px',
			top: Math.floor((wh-h)/2)+'px'
		});
		log.debug("calcpos("+n+","+p+")="+img[n].style.left);
	}

	function setplaytimer(){
		if(playing) {
			if(slideid) clearTimeout(slideid);
			slideid = setTimeout(walli.next,DELAY*1000);
		}
	}

	function setplay(b){
		if(playing === b) return;
		playing = b;
		if(b){
			setplaytimer();
			css('#bplay','active');
			osd.info(loc.play);
		} else {
			if(slideid) clearTimeout(slideid);
			slideid = false;
			css('#bplay','');
			osd.info(loc.stop);
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
		}else if(mode=="zik"){
		}else if(mode=="movie"){
		}else{ //thumb
			showing = false;
			setplay(false);
			css(img[0],'');
			css(img[1],'');
			css(view,'-active');
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
				if(d.error)
					osd.error(d.error);
				else {
					coms[d.file] = d.coms;
					loadcoms(files[idx]);
					setminicom(d.coms.length);
					what.value = '';
				}
			},
			error: function(xhr){
				osd.error(xhr.statusText);
			}
		});
	}

	function delcom(file,id){
		ajax({
			type: 'POST',
			url: '?!=uncomment',
			data: { file:file, id:id },
			ok: function(d){
				if(d.error)
					osd.error(d.error);
				else {
					coms[d.file] = d.coms;
					loadcoms(files[idx]);
					setminicom(d.coms.length);
				}
			},
			error: function(xhr){
				osd.error(xhr.statusText);
			}
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

	return {
		setup: function(o) {
			comok = o.comments;

			var b = document.body;

			view = _('#view');
			//view.onclick = walli.thumb;
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
			//img[0].onclick =
			//img[1].onclick = walli.next;
			slide.ondragstart = 
			img[0].ondragstart =
			img[1].ondragstart = function(e){
				e.preventDefault();
				return false;
			};
			
			window.onresize = function(){ if(showing) calcpos(nimg,0); };
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
				.add('SPACE',walli.toggleplay)
				.add('C',walli.togglecom)
				.add('HOME',walli.first)
				.add('LEFT',walli.prev)
				.add('RIGHT',walli.next)
				.add('END',walli.last)
				.add(['ESC','UP'],walli.back)
				.add('DOWN',function(){if(!showing && files.length)walli.show(0)})
				//.add('+',walli.speedinc)
				//.add('-',walli.speeddec)
			;

			_('#bprev').onclick    = walli.prev;
			_('#bnext').onclick    = walli.next;
			_('#bplay').onclick    = walli.toggleplay;
			_('#bthumb').onclick   = walli.thumb;
			_('#bzip').onclick     = walli.dlzip;

			if(comok) {
				who = _('#who');
				what = _('#what');
				what.onfocus =
				who.onfocus = function(){ setplay(false); };
				_('#comments').onclick = stopev;
				_('#bcom').onclick     = walli.togglecom;
				_('#bsend').onclick    = walli.sendcom;
			}
			
			log.info("show on!");

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
		dlzip: function(){
			var lst = tozip.length
				? files.filter(function(f,n){return tozip.indexOf(n)!=-1})
				: files;
			if(lst.length){
				_('#bzip',loc.zip);
				ajax({
					url: '?!=zip',
					type: 'POST',
					data: {files:lst.join('*')},
					ok: function(d){
						if(d.error) osd.error(d.error);
						else document.location='?!=zip&zip='+d.zip;
						walli.zwap();
					},
					error: function(e){
						osd.error(e);
						walli.zwap();
					}
				});
			} else
				osd.info(loc.nozip);
		},
		zwap: function(n) {
			if(n!=undefined) {
				if(tozip.indexOf(n)==-1) tozip.push(n);
				else tozip=tozip.filter(function(i){return i!=n});
			}
			if(tozip.length) _('#bzip',loc.dlsel.replace('%d',tozip.length)).className='selected';
			else             _('#bzip',loc.dlall).className='all';
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
			css('#mask','+active');
			loadimg(idx,function(ni,i){
				//log.debug("load "+ni+" ok p="+p);
				css('#mask','-active');
				if(showing) {
					nimg = 1-nimg;
				} else {
					setmode('tof');
					p=0;
				}
				att[nimg] = { w:i.width, h:i.height };
				slide.removeChild(img[nimg]); /*remove&append to force redraw*/
				img[nimg].src = i.src;
				if(p) {
					//slide
					css(img[nimg],'');
					calcpos(nimg,p);
					slide.appendChild(img[nimg]);
					css(img[nimg],'animated');
					calcpos(nimg,0);
					calcpos(1-nimg,-p);
				}else{
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
				setTimeout(function(){
					osd.info(cleantitle(files[idx])+' ('+(idx+1)+'/'+files.length+')');
				}, 1000);
				if(files.length>1) loadimg((idx+1)%files.length,function(){});
			});
			loadcoms(files[idx]);
		},
		next: function(e){
			stopev(e);
			if(showing) walli.show(++idx,1);
		},
		prev: function(e){ 
			stopev(e);
			if(showing) walli.show(--idx,-1);
		},
		first: function(e){
			stopev(e);
			if(showing) walli.show(0,-1);
		},
		last: function(e){
			stopev(e);
			if(showing) walli.show(-1,1);
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
			setplay(!playing);
		},
		togglecom: function(e){
			if(e) e.stopPropagation();
			setcom(!comon);
		},
		sendcom: function(){
			if(who.value.length<1) {
				osd.info(loc.emptywho);
				who.focus();
			} else if(what.value.length<1) {
				osd.info(loc.emptywhat);
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