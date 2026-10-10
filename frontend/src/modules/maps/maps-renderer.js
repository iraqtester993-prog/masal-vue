import {mapTime,roleSymbols} from './maps-model.js';

export function markerMarkup(user){const color=/^#[0-9a-f]{6}$/i.test(user.networkColor||'')?user.networkColor:'#0898b5',symbol=roleSymbols[user.role]||'●';return `<span style="--network-color:${color}">${symbol}<i class="marker-presence"></i></span>`;}
export function popupNode(user,{document:doc=document,onDetails,copy=link=>navigator.clipboard.writeText(link),notify=()=>{}}={}) {
  const node=doc.createElement('article');node.className='map-point-popup';node.dir='rtl';node.style.setProperty('--network-color',/^#[0-9a-f]{6}$/i.test(user.networkColor||'')?user.networkColor:'#0898b5');
  const head=doc.createElement('header'),title=doc.createElement('div'),name=doc.createElement('strong'),meta=doc.createElement('small');name.textContent=user.name;meta.textContent=`${user.kind} · ${user.account}`;title.append(name,meta);
  const status=doc.createElement('span');status.className=`map-popup-state ${user.online?'online':'offline'}`;status.textContent=user.online?'متصل':'غير متصل';head.append(title,status);node.append(head);
  const grid=doc.createElement('dl'),add=(label,value)=>{if(!value)return;const dt=doc.createElement('dt'),dd=doc.createElement('dd');dt.textContent=label;dd.textContent=value;grid.append(dt,dd);};
  add('الحساب التابع له',user.parentName);add('رقم الهاتف',user.phone);add('المحافظة / المدينة',user.city);add('العنوان',user.address);add('آخر ظهور',mapTime(user.lastSeen));add('مصدر الموقع',user.source);if(user.location)add('الإحداثيات',`${user.location.lat.toFixed(5)}, ${user.location.lng.toFixed(5)}`);node.append(grid);
  const actions=doc.createElement('div');actions.className='map-popup-actions';const details=doc.createElement('button');details.type='button';details.className='btn primary small';details.textContent='عرض التفاصيل';details.addEventListener('click',event=>{event.stopPropagation();onDetails?.(user);});actions.append(details);
  if(user.location){const button=doc.createElement('button');button.type='button';button.className='btn small';button.textContent='نسخ رابط الموقع';button.addEventListener('click',async event=>{event.stopPropagation();try{await copy(`https://www.google.com/maps?q=${encodeURIComponent(user.location.lat+','+user.location.lng)}`);notify('تم نسخ رابط الموقع ويمكن مشاركته');}catch{notify('تعذر نسخ رابط الموقع',true);}});actions.append(button);}
  node.append(actions);return node;
}

export function createMapRenderer({L,canvas,countries,onTileError=()=>{},onSelect=()=>{},onDetails=()=>{},notify=()=>{},Resize=ResizeObserver}) {
  const map=L.map(canvas,{minZoom:2,maxZoom:19,zoomAnimation:false,markerZoomAnimation:false}).setView([33.3,44.4],6),layer=L.layerGroup().addTo(map);
  map.createPane('offline-background');map.getPane('offline-background').style.zIndex=150;
  L.geoJSON(countries,{pane:'offline-background',interactive:false,style:{color:'#93a99d',weight:1,fillColor:'#e9e7dc',fillOpacity:1}}).addTo(map);
  const tiles=L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Street_Map/MapServer/tile/{z}/{y}/{x}',{maxZoom:19,maxNativeZoom:19,attribution:'Tiles © <a href="https://www.esri.com/" target="_blank" rel="noopener">Esri</a> — Esri, HERE, Garmin, USGS, Intermap, INCREMENT P, NRCan, Esri Japan, METI, Esri China (Hong Kong), Esri Korea, Esri (Thailand), NGCC, © OpenStreetMap contributors, GIS User Community'}).on('tileerror',()=>onTileError(true)).on('tileload',()=>onTileError(false)).addTo(map);
  let rows=[],selectedId=null,disposed=false,markers=new Map();
  function draw(next=rows,selected=selectedId){if(disposed)return;rows=next;selectedId=selected;layer.clearLayers();markers=new Map();const groups=new Map();for(const user of rows){if(!user.location)continue;const key=`${user.location.lat.toFixed(5)}:${user.location.lng.toFixed(5)}`;if(!groups.has(key))groups.set(key,[]);groups.get(key).push(user);}
    for(const users of groups.values())for(let index=0;index<users.length;index++){const user=users[index],base=map.latLngToLayerPoint([user.location.lat,user.location.lng]),angle=Math.PI*2*index/Math.max(1,users.length)-Math.PI/2,radius=users.length>1?Math.min(30,14+users.length*2):0,shown=map.layerPointToLatLng([base.x+Math.cos(angle)*radius,base.y+Math.sin(angle)*radius]);
      const marker=L.marker(shown,{zIndexOffset:user.role==='main_agent'?300:['sub_agent','sub_branch'].includes(user.role)?200:user.role==='pos'?100:0,icon:L.divIcon({className:`account-map-marker network-marker ${user.online?'online':'offline'}${user.id===selectedId?' selected-marker':''}`,html:markerMarkup(user),iconSize:[34,34]}),title:`${user.name} — ${user.kind}`,keyboard:true}).addTo(layer);markers.set(user.id,marker);marker.bindPopup(()=>popupNode(user,{onDetails,notify}),{className:'masal-map-popup',maxWidth:350,minWidth:270,offset:[0,-12],autoPan:true,autoPanPadding:[28,28],autoClose:true,closeOnClick:true});marker.on('click',()=>{selectedId=user.id;onSelect(user);});}
  }
  function fit(){const points=rows.filter(user=>user.location).map(user=>[user.location.lat,user.location.lng]);if(points.length&&!disposed)map.fitBounds(points,{padding:[40,40],maxZoom:13});}
  function choose(user,popup=true){if(disposed)return;selectedId=user.id;if(user.location)map.setView([user.location.lat,user.location.lng],Math.max(15,map.getZoom()));draw();if(popup)markers.get(user.id)?.openPopup();}
  const resize=new Resize(()=>{if(!disposed)map.invalidateSize();});resize.observe(canvas);map.on('moveend zoomend',()=>draw());
  return {draw,fit,choose,retryTiles(){if(!disposed){onTileError(false);tiles.redraw();}},dispose(){if(disposed)return;disposed=true;resize.disconnect();map.remove();markers.clear();rows=[];}};
}
