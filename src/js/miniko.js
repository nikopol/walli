// miniko.js 0.7
// ~L~ nikomomo@gmail.com 2012-2014
// https://github.com/nikopol/miniko.js

/*
**selectors**

if content is provided, all matching element will have it.

  _(element,[content])    : return the element provided
  _("#id",[content])      : return the element by id
  _("<el>...</el>")       : create and return element(s)
  _("selector",[content]) : return an elements array matching a selector

  all these methods setup innerHTML if content is provided

**manipulation**

  append(sel,html)        : append html to matching element(s)

**style**  

  css(sel,'class')        : set/overwrite classname to matching element(s)
  css(sel,'+C1-C2*C3')    : add C1 to matching element(s) and
                            remove C2 to matching element(s) and
                            toggle C3 to matching element(s)
  css(sel,'?class')       : return the count of class in matchinf element(s)
  css(sel,{style:value})  : style's values to matching element(s)
  
**geometry**

  position(element)
  position("#id")         : return {left,top,width,height}

**ajax**
  
  ajax(url,ok)            : perform a GET ajax call
  ajax({                  : perform an ajax call
     url: '?'           
     type: 'GET|DELETE|POST|PUT'
     data: {...},
     ok: function(data,xhr){},
     error: function(responsetext,xhr){},
     datatype: 'application/json',
     contenttype: 'application/x-www-form-urlencoded',
     timeout: 30,
     headers: {
      'key': value
     }
  })
  
**events**

  ready(callback) : callback when the dom is ready

*/

(function(W){
	"use strict";
	var
	D = W.document,
	//A = function(o){ return o ? (o instanceof Array ? o : [o]) : []; };
	A = function(o){ return o===null ? [] : o instanceof Array ? o : [o] };

	W._ = function(s,h){
		var o,l,n;
		if(typeof(s)=='object')
			o = s;
		else if(s.length) {
			if(s[0]=='#' && !/[ ,\.\>\<]/.test(s))
				o = D.getElementById(s.substr(1));
			else if(/^<.+>$/.test(s)) {
				(n = D.createElement('div')).innerHTML = s;
				l = [].slice.call(n.childNodes);
				o = l.length ? ( l.length>1 ? l : l[0] ) : undefined;
			} else {
				l = D.querySelectorAll(s);
				for(o=[],n=0; n<l.length; ++n) o.push(l[n]);
			}
		}
		if(o && h!=undefined) A(o).forEach(function(e){ e.innerHTML = h });
		return o;
	};

	W.append = function(s,c){
		var o=_(s);
		if(c==undefined) return o;
		A(o).forEach(function(e){
			var n,d=_('<div>',c);
			for(n=0; n<d.childNodes.length; ++n)
				e.appendChild(d.childNodes[n]);
		});
		return o;
	};

	W.css = function(s,c){
		var o=_(s),m,z,v,q=/([\+\-\*])([^\+\-\*\s]+)/g;
		if(c==undefined) return o;
		if(typeof(c)=="object") {
				A(o).forEach(function(e){
					for(m in c) e.style[m] = c[m];
				});
		} else if( /^[\+\-\*]/.test(c) ) {
			while((m = q.exec(c)) !== null) {
				z = m[1];
				v = m[2];
				A(o).forEach(function(e){
					var w = e.className.split(/\s+/).filter(function(n){return n});
					if(z!='-' && w.indexOf(v)==-1) w.push(v); //add class
					else if(z!='+') w=w.filter(function(n){return n!=v}); //remove class
					e.className = w.join(' ');
				});
			}
		} else if( c[0]=='?' ) {
			m = 0;
			c = c.substr(1);
			A(o).forEach(function(e){ 
				m += e.className.split(' ').filter(function(d){ return d==c }).length; 
			});
			o = m;
		} else
			A(o).forEach(function(e){ e.className = c });
		return o;
	};

	W.ajax = function(o,fn){
		if(typeof(o)=='string') o = { url:o, ok:fn };
		var
			app  = 'application/',
			type = o.type || 'GET',
			url  = o.url || '',
			ctyp = o.contenttype || app+'x-www-form-urlencoded',
			dtyp = o.datatype || app+'json',
			xhr  = new window.XMLHttpRequest(),
			timer,d,n;
		if(o.data){
			if(typeof(o.data)=='string')
				d = o.data;
			else if(/json/.test(ctyp))
				d = JSON.stringify(o.data);
			else {
				d = [];
				for(n in o.data)
					d.push(encodeURIComponent(n)+'='+encodeURIComponent(o.data[n]));
				d = d.join('&');
			}
			if(/GET|DEL/i.test(type)) {
				url += /\?/.test(url) ? '&'+d : '?'+d;
				d = '';
			}
		}
		if(!o.error) o.error=function(t,xhr){ console.error(t,xhr) };
		if(!o.ok) o.ok=function(){};
		xhr.onreadystatechange = function(){
			if(xhr.readyState==4) {
				if(timer) clearTimeout(timer);
				if(/^2/.test(xhr.status)) {
					d=xhr.responseText;
					if(/json/.test(dtyp)) {
						try { d = JSON.parse(xhr.responseText) }
						catch(e) { return o.error('json parse error: '+e.message,xhr) }
					}
					o.ok(d,xhr);
				} else
					o.error(xhr.responseText,xhr);
			}
		};
		xhr.open(type, url, true);
		xhr.setRequestHeader('Content-Type', ctyp);
		if(o.headers)
			for(n in o.headers) xhr.setRequestHeader(n, o.headers[n]);
		if(o.timeout) timer = setTimeout(function(){
			xhr.onreadystatechange = function(){};
			xhr.abort();
			if(o.error) o.error('timeout',xhr);
		}, o.timeout*1000);
		xhr.send(d);
		return xhr;
	};

	W.position = function(e){
		var	o = _(e);
		if(o) {
			var r = o.getBoundingClientRect();
			return {
				left: r.left+window.pageXOffset,
				top: r.top+window.pageYOffset,
				width: r.width,
				height: r.height
			};
		}
		return false;
	};

	W.ready = function(cb){
		if(/complete|loaded|interactive/.test(D.readyState))
			cb();
		else if(D.attachEvent)
			D.attachEvent('ondocumentready',cb()); 
		else
			D.addEventListener('DOMContentLoaded',function(){cb()}, false);
	};

})(window);