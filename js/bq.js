const hooks    = {}, 
      hooked   = new WeakMap(), 
      pending  = {};

document.startViewTransition ??= (func) => func();

const visiblilityObserver = new IntersectionObserver((intersections) => {
  for (const node of intersections) {
    node.isIntersecting && node.target.dispatchEvent(new CustomEvent(':visible'));
  }
});

export const lazy = (node) => {
  return new Promise((resolve) => {

    node.addEventListener(':visible', () => {
      resolve();
      visiblilityObserver.unobserve(node);
    }, { once: true });
    
    visiblilityObserver.observe(node);
  });
}

export const hook = (selector = '', hook = (e) => null) => {

  for (const e of document.querySelectorAll(selector)) {
    call(e, [hook]);
  }

  hooks[selector] ??= [];
  hooks[selector].push(hook);
}
 
const sync = (key, value, attributes = []) => {

  for (const recipient of document.querySelectorAll(`[data-sync="${ key }"]`)) {
    value ? recipient.textContent = value : null;

    for (const attr of attributes) {
      recipient.setAttribute(attr.name, attr.value);
    }
  }

  document.dispatchEvent(new CustomEvent(`bq:sync/${ key }`, { detail: value }));
  document.dispatchEvent(new CustomEvent(`bq:sync`, { detail: value }));
}

export const dom = (html = '') => {
  const template = document.createElement('template');
  template.innerHTML = html;

  const fragment = template.content;

  for (const node of fragment.querySelectorAll('[data-sync]')) {
    sync(
      node.dataset.sync, node.textContent, 
      Array.from(node.attributes).filter(
        attr => attr.name.startsWith('data-sync.')
      ).map(
        attr => ({
          name: attr.name.replace('data-sync.', ''),
          value: attr.value
        })
      )
    );
  }

  for (const update of fragment.querySelectorAll('node-update')) {
    const nodes = document.querySelectorAll(update.dataset.select);

    for (const node of nodes) {
      
      for (const patch of update.children) {
        const k = patch.nodeName.toLowerCase();

        if (k == 'class-remove') {
          node.classList.remove(patch.dataset.name);
        }

        if (k == 'class-add') {
          node.classList.add(patch.dataset.name);
        }

        if (k == 'attribute-remove') {
          node.removeAttribute(patch.dataset.name);
        }

        if (k == 'attribute-set') {
          node.setAttribute(patch.dataset.name, patch.dataset.value ?? '');
        }
      }
    }    
  }

  return fragment;
}

/** -- */

const call = (e, hooks = []) => {
  const done = hooked.get(e) ?? [];

  for (const hook of hooks) {
    if (!done.includes(hook)) {

      if (e.hasAttribute('data-lazy')) {
        lazy(e).then(() => hook(e));
      } else {
        hook(e);
      }

      done.push(hook);
      hooked.set(e, done);
    }       
  }
}

const observer = new MutationObserver(mutations => {

  for (const e of mutations) {
    const target = e.target;

    for (const selector in hooks) {     
      for (const e of target.querySelectorAll(selector)) {
        call(e, hooks[selector]);
      }
    }
  }

});

observer.observe(document, { childList: true, subtree: true });

const reset = (form, select = 'input,select,textarea,[name]') => {
  for (const input of form?.querySelectorAll(select) ?? []) {
    input.value = '';
  }
}

hook('[type="reset"]', (e) => {

  e.addEventListener('click', (ev) => {
    const form = e.closest('form');

    reset(form, e.hasAttribute('name') ? `[name="${ e.getAttribute('name') }"]` : null);

    form.dispatchEvent(new Event('submit', { bubbles: true }));

    ev.stopPropagation();
    ev.preventDefault();
  });

});

hook('[data-trigger]', (e) => {
  let timeout;

  e.addEventListener(e.dataset.trigger, () => {
    clearTimeout(timeout);

    const submit = () => e.closest('form').dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));

    e.dataset.delay ? timeout = setTimeout(submit, parseInt(e.dataset.delay)) : submit();
  });
  
});

hook('[data-async]', async (e) => {

  const response = await fetch(e.dataset.async, {
    headers: {
      'accept': 'text/html'
    }
  });

  response.ok && e.append(dom(await response.text()));
});

hook('form', (e) => {
  const select  = e.dataset.select,
        append  = e.dataset.append,
        remove  = e.dataset.remove,
        prepend = e.dataset.prepend,
        replace = e.dataset.replace;

  if (e.hasAttribute('data-native')) {
    return;
  }

  e.addEventListener('submit', async (ev) => {
    const submitter   = ev.submitter ?? e,
          url         = new URL(e.dataset.url ?? submitter.getAttribute('formaction') ?? e.action ?? location.pathname, location.href),
          method      = (submitter.getAttribute('formmethod') ?? e.getAttribute('method') ?? 'GET').toUpperCase(),
          contentType = submitter.getAttribute('formenctype') ?? e.getAttribute('enctype') ?? 'application/x-www-form-urlencoded';

    const data = {};

    ev.stopPropagation();
    ev.preventDefault();

    document.dispatchEvent(new CustomEvent(`bq:fetch`, {  detail: e.action ?? location.pathname }));

    const setProperty = (key, v) => {
      const parts = key.split('.');

      if (v === null) {
        return;
      }

      let d = data;

      for (let i = 0, k; i < parts.length; i++) {
        k = parts[i];

        if (i == parts.length -1) {

         if (Array.isArray(d[k])) {
            d[k].push(v);
          } else if (d[k]) {
            d[k] = [d[k]];

            d[k].push(v);
          } else {
            d[k] = v;
          }

          continue;
        } 

        d[k] = {};
      }
    }

    const valueOf = (input) => {

      const v = () => input.value == '' ? null : input.value;

      if (input.type == 'radio' || input.type == 'checkbox') {
        return input.checked ? v() : null;
      }
      
      if (input.type == 'number') {
        return input.valueAsNumber;
      } 
      
      return v();
    }

    for (const input of e.querySelectorAll('input[name],select[name],textarea[name]')) {
      setProperty(input.name, valueOf(input));
    }

    e.classList.add('--loading');

    if (append || replace) {
      document.querySelector(append || replace).classList.add('--loading');
    }
  
    if (remove) {
      for (const e of document.querySelectorAll(remove)) {
        e.remove();
      }
    }

    if (pending[url.pathname]) {
      pending[url.pathname].abort('-');
    } 
  
    pending[url.pathname] = new AbortController();

    let body;

    for (const k of Array.from(url.searchParams.keys())) {
      url.searchParams.delete(k);
    }

    if (['POST', 'PUT', 'PATCH'].includes(method)) {
      body = contentType == 'application/json' ? JSON.stringify(data) : new URLSearchParams(data).toString();
    } else {

      for (const k in data) {

        if (Array.isArray(data[k])) {

          for (const v of data[k]) {
            url.searchParams.append(k, v);
          }

        } else {
          url.searchParams.append(k, data[k]);
        }
      }

    }
  
    const response = await fetch(method == 'GET' ? url : url.pathname, { 
      method, body,
      signal: pending[url.pathname].signal,
      headers: {
        'content-type': contentType,
        'accept': 'text/html'
      }
    }).catch(_ => (console.error(_) && { ok: false, status: 500, statusText: _.toString() }));

    const html = dom(await response.text());

    delete pending[url.pathname];

    if (response.ok) {
      if (url.pathname == location.pathname) {
        window.history.replaceState(null, null, url);
      }     
    }

    document.startViewTransition(() => {
        
      if (append) {
        const appendContainer = document.querySelector(append);

        for (const e of html.querySelectorAll(select)) {
          appendContainer.append(e);
        }
      }

      if (replace) {
        document.querySelector(replace).replaceWith(html.querySelector(select || replace));
      }

      if (prepend) {
        const p = document.querySelector(prepend);

        p.parentNode.insertBefore(html.querySelector(select), p);
      }

    });

    if (append) {
      const appendContainer = document.querySelector(append);
      
      requestAnimationFrame(() => appendContainer.classList.remove('--loading'));
    }

    e.classList.remove('--loading');

    document.dispatchEvent(new CustomEvent(`bq:fetched`, { 
      detail: e.dataset.url ?? location.pathname 
    }));

  });
});