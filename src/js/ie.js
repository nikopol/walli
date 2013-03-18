/*
IE SHIT
niko 2012-2013
*/

/* array */

if(![].forEach)
	Array.prototype.forEach=function(cb,i,_){ 
		for(_=this,i=0; i<_.length; cb(_[i],i++,_));
	};

if(![].filter)
	Array.prototype.filter=function(cb,i,_,f){
		for(_=this,f=[],i=0; i<_.length; ++i)
			cb(_[i],i) && f.push(_[i]);
		return f;
	};

if(![].indexOf)
	Array.prototype.indexOf=function(w,f,i,_){
		for(_=this,i=f||0; i<_.length; ++i)
			if(_[i] == w) return i;
		return -1;
	};