// Keep the approved page colours while allowing Android to paint each system
// bar independently. Older APKs read `color` and ignore the optional fields.
export function posSystemAppearance({home=false,dark=false,hero,accent,overlay=false}={}) {
  const canvas=dark?'#0D171B':'#F5F7F6';
  const panel=dark?'#142329':'#FFFFFF';
  const status=overlay?panel:home&&/^#[0-9a-f]{6}$/i.test(hero||'')?hero:canvas;
  return {
    action:'appearance',theme:dark?'dark':'light',color:status,
    statusBarColor:status,navigationBarColor:panel,backgroundColor:canvas,
    panelColor:panel,textColor:dark?'#EDF5F2':'#172D29',mutedColor:dark?'#A3B8B1':'#647971',borderColor:dark?'#2C413B':'#DCE7E2',
    accentColor:/^#[0-9a-f]{6}$/i.test(accent||'')?accent:dark?'#B4D8D3':'#0D5C63',
  };
}
