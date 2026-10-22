// Construction du DOM élément par élément : les données (noms d'articles…) passent toujours par textContent.

/**
 * @param {string} tag
 * @param {{class?: string, text?: string, attrs?: object, on?: object}} options
 * @param {Array<Node|string|null|false>} children
 */
export function el(tag, { class: className, text, attrs = {}, on = {} } = {}, children = []) {
  const node = document.createElement(tag);
  if (className) {
    node.className = className;
  }
  if (text !== undefined) {
    node.textContent = text;
  }
  for (const [name, value] of Object.entries(attrs)) {
    if (value === true) {
      node.setAttribute(name, "");
    } else if (value !== false && value !== null && value !== undefined) {
      node.setAttribute(name, String(value));
    }
  }
  for (const [event, handler] of Object.entries(on)) {
    node.addEventListener(event, handler);
  }
  for (const child of children) {
    if (child) {
      node.append(child);
    }
  }
  return node;
}
