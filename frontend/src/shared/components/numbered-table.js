import {cloneVNode, Fragment, h, isVNode} from 'vue';

function copy(node, children, props) {
  const result = cloneVNode(node, props);
  result.children = children;
  result.dynamicChildren = null;
  result.patchFlag = 0;
  return result;
}

/** Add a presentation column without changing business rows or their event handlers. */
export function numberTables(nodes, start = 1, label = 'التسلسل') {
  function table(node) {
    let sequence = Math.max(1, Number(start) || 1);
    function visit(value, section) {
      if (!isVNode(value)) return value;
      if (value.type === Fragment) return copy(value, value.children.map(child => visit(child, section)));
      if (value.type === 'thead' || value.type === 'tbody') section = value.type;
      let children = Array.isArray(value.children) ? value.children.map(child => visit(child, section)) : value.children;
      if (value.type === 'tr' && Array.isArray(children)) {
        const collectCells=items=>items.flatMap(child=>isVNode(child)&&child.type===Fragment?collectCells(child.children):isVNode(child)&&['td','th'].includes(child.type)?[child]:[]);
        const cells = collectCells(children);
        if (section === 'thead' && cells.length) children = [h('th', {scope:'col', class:'table-sequence'}, label), ...children];
        if (section === 'tbody' && cells.length) {
          const fullRow = cells.length === 1 && Number(cells[0].props?.colspan) > 1;
          children = fullRow ? children.map(child => child === cells[0] ? copy(child, child.children, {colspan:Number(child.props.colspan)+1}) : child) : [h('td', {class:'table-sequence'}, String(sequence++)), ...children];
        }
      }
      return copy(value, children);
    }
    return visit(node);
  }
  return nodes.map(node => {
    if (!isVNode(node)) return node;
    if (node.type === 'table') return table(node);
    if (Array.isArray(node.children)) return copy(node, numberTables(node.children, start, label));
    return node;
  });
}
