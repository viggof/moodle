YUI.add("resize-plugin",function(b){var a=function(c){c.node=((b.Widget&&c.host instanceof b.Widget)?c.host.get("boundingBox"):c.host);if(c.host instanceof b.Widget){c.widget=c.host;}else{c.widget=false;}a.superclass.constructor.call(this,c);};a.NAME="resize-plugin";a.NS="resize";a.ATTRS={node:{value:undefined},widget:{value:undefined}};b.extend(a,b.Resize,{initializer:function(c){this.set("node",c.node);this.set("widget",c.widget);this.on("resize:resize",function(d){this._correctDimensions(d);});},_correctDimensions:function(f){var d=this.get("node"),c={old:d.getX(),cur:f.currentTarget.info.left},g={old:d.getY(),cur:f.currentTarget.info.top};if(this.get("widget")){this._setWidgetProperties(f,c,g);}if(this._isDifferent(c.old,c.cur)){d.set("x",c.cur);}if(this._isDifferent(g.old,g.cur)){d.set("y",g.cur);}},_setWidgetProperties:function(h,c,k){var g=this.get("widget"),j=g.get("height"),d=g.get("width"),f=h.currentTarget.info.offsetWidth-h.currentTarget.totalHSurrounding,i=h.currentTarget.info.offsetHeight-h.currentTarget.totalVSurrounding;if(this._isDifferent(j,i)){g.set("height",i);}if(this._isDifferent(d,f)){g.set("width",f);}if(g.hasImpl&&g.hasImpl(b.WidgetPosition)){if(this._isDifferent(g.get("x"),c.cur)){g.set("x",c.cur);}if(this._isDifferent(g.get("y"),k.cur)){g.set("y",k.cur);}}},_isDifferent:function(c,d){if(c!==d){return d;}else{return false;}}});b.namespace("Plugin");b.Plugin.Resize=a;},"@VERSION@",{skinnable:false,requires:["resize-base","plugin"],optional:["resize-constrain"]});