/*
IE SHIT
niko 2012
*/

/* array */

if(![].forEach)
	Array.prototype.forEach=function(cb){ 
		for(var i=0; i<this.length; ++i)
			cb(this[i],i,this)
	};

if(![].filter)
	Array.prototype.filter=function(test){
		var f = [];
		for(var i=0; i<this.length; ++i)
			if(test(this[i],i))
				f.push(this[i]);
		return f;
	};

if(![].indexOf)
	Array.prototype.indexOf=function(w,f){
		for(var i=f||0; i<this.length; ++i)
			if(this[i] == w)
				return i;
		return -1;
	};
