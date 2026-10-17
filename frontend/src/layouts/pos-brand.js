export function posBrandStyle(color) {
  const value = /^#[0-9a-f]{6}$/i.test(color || '') ? color : '#0D5C63';
  const channels = [1, 3, 5].map(index => parseInt(value.slice(index, index + 2), 16));
  const linear = channels.map(channel => { const n = channel / 255; return n <= .04045 ? n / 12.92 : ((n + .055) / 1.055) ** 2.4; });
  const luminance = linear[0] * .2126 + linear[1] * .7152 + linear[2] * .0722;
  const darkInkLuminance=[17,22,23].map(channel=>{const n=channel/255;return n<=.04045?n/12.92:((n+.055)/1.055)**2.4;}).reduce((sum,c,i)=>sum+c*[.2126,.7152,.0722][i],0);
  const ink = luminance > .179 ? (luminance+.05)/(darkInkLuminance+.05)>=4.5?'#111617':'#000000' : '#FFFFFF';
  const header = '#' + channels.map(channel => Math.round(channel * .32).toString(16).padStart(2, '0')).join('');
  const lightLink=luminance>.12?header:value;
  const darkLink='#'+channels.map(channel=>Math.max(180,Math.round(channel+(255-channel)*.7)).toString(16).padStart(2,'0')).join('');
  const tint=(base,weight)=>'#'+channels.map((channel,index)=>Math.round(channel*weight+base[index]*(1-weight)).toString(16).padStart(2,'0')).join('');
  // Hero surfaces always carry white text, including when an agent chooses yellow or white.
  const surface=weight=>{let rgb=channels.map(channel=>Math.round(channel*weight));while(rgb.reduce((sum,c,i)=>sum+[.2126,.7152,.0722][i]*(c/255<=.04045?c/255/12.92:((c/255+.055)/1.055)**2.4),0)>.12)rgb=rgb.map(c=>Math.floor(c*.94));return '#'+rgb.map(c=>c.toString(16).padStart(2,'0')).join('');};
  const hero=surface(.55),card=surface(.76);
  const cardChannels=[1,3,5].map(index=>parseInt(card.slice(index,index+2),16));
  const heroMuted='#'+cardChannels.map(c=>Math.round(c*.12+255*.88).toString(16).padStart(2,'0')).join('');
  return {'--pos-soft-light':tint([255,255,255],.09),'--pos-soft-dark':tint([20,29,41],.22),'--pos-brand':value,'--pos-brand-ink':ink,'--pos-header':header,'--pos-link-light':lightLink,'--pos-link-dark':darkLink,'--pos-hero':hero,'--pos-hero-card':card,'--pos-hero-muted':heroMuted};
}
