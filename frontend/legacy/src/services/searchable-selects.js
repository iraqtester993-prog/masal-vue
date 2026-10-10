(function (root) {
  "use strict";
  const normalize = (s) =>
    String(s || "")
      .normalize("NFKD")
      .replace(/[\u064b-\u065f\u0670\u0640\u0300-\u036f]/g, "")
      .replace(/[أإآ]/g, "ا")
      .toLocaleLowerCase()
      .trim();
  function setup(vm) {
    let select = null,
      popup = null,
      search = null,
      list = null,
      items = [],
      active = -1,
      observer = null,
      raf = 0,
      original = {};
    const text = (s) => (vm.tr ? vm.tr(s) : s);
    function close(focus = false) {
      if (!select) return;
      const prior = select;
      observer?.disconnect();
      observer = null;
      if (popup?.matches(":popover-open")) popup.hidePopover();
      popup?.remove();
      for (const k of ["aria-expanded", "aria-controls"])
        original[k] === null
          ? prior.removeAttribute(k)
          : prior.setAttribute(k, original[k]);
      select = popup = search = list = null;
      items = [];
      active = -1;
      if (focus && prior.isConnected) prior.focus({ preventScroll: true });
    }
    function place() {
      if (
        !select ||
        !select.isConnected ||
        select.disabled ||
        !select.getClientRects().length
      ) {
        close();
        return;
      }
      const r = select.getBoundingClientRect(),
        v = window.visualViewport,
        left = v?.offsetLeft || 0,
        top = v?.offsetTop || 0,
        width = v?.width || innerWidth,
        height = v?.height || innerHeight;
      const w = Math.min(Math.max(280, r.width), width - 24),
        below = top + height - r.bottom - 12,
        above = r.top - top - 12,
        down = below >= 220 || below >= above,
        h = Math.max(100, Math.min(360, down ? below : above));
      popup.style.width = w + "px";
      popup.style.maxHeight = h + "px";
      popup.style.left =
        Math.max(
          left + 12,
          Math.min(
            document.documentElement.dir === "rtl" ? r.right - w : r.left,
            left + width - w - 12,
          ),
        ) + "px";
      popup.style.top =
        (down
          ? r.bottom + 6
          : Math.max(
              top + 12,
              r.top - popup.getBoundingClientRect().height - 6,
            )) + "px";
    }
    function focusOption(index) {
      active = index;
      for (let i = 0; i < items.length; i++)
        items[i].button.classList.toggle("is-active", i === active);
      if (items[active]) {
        search.setAttribute("aria-activedescendant", items[active].button.id);
        items[active].button.scrollIntoView({ block: "nearest" });
      } else search.removeAttribute("aria-activedescendant");
    }
    function choose(index) {
      const item = items[index];
      if (
        !item ||
        !select ||
        select.disabled ||
        item.option.disabled ||
        item.option.parentElement?.disabled
      )
        return;
      const source = select;
      if (source.multiple) {
        item.option.selected = !item.option.selected;
        source.dispatchEvent(new Event("input", { bubbles: true }));
        source.dispatchEvent(new Event("change", { bubbles: true }));
        render();
        search?.focus();
        return;
      }
      source.selectedIndex = item.index;
      close(true);
      source.dispatchEvent(new Event("input", { bubbles: true }));
      source.dispatchEvent(new Event("change", { bubbles: true }));
    }
    function render() {
      if (!select || !list) return;
      const q = normalize(search.value);
      list.replaceChildren();
      items = [];
      active = -1;
      let group = "";
      [...select.options].forEach((option, index) => {
        if (
          option.hidden ||
          option.parentElement?.hidden ||
          !normalize(option.textContent).includes(q)
        )
          return;
        const groupName =
          option.parentElement?.tagName === "OPTGROUP"
            ? option.parentElement.label
            : "";
        if (groupName && groupName !== group) {
          const heading = document.createElement("div");
          heading.className = "search-select-group";
          heading.textContent = groupName;
          list.append(heading);
        }
        group = groupName;
        const button = document.createElement("button");
        button.type = "button";
        button.className = "search-select-option";
        button.id = "masal-search-option-" + index;
        button.setAttribute("role", "option");
        button.setAttribute("aria-selected", String(option.selected));
        button.disabled = option.disabled || !!option.parentElement?.disabled;
        const label = document.createElement("span");
        label.textContent = option.textContent || text("بدون");
        button.append(label);
        if (option.selected) {
          const tick = document.createElement("span");
          tick.textContent = "✓";
          tick.setAttribute("aria-hidden", "true");
          button.append(tick);
        }
        button.tabIndex = -1;
        const row = items.length;
        button.addEventListener("pointerdown", (e) => e.preventDefault());
        button.addEventListener("click", () => choose(row));
        list.append(button);
        items.push({ option, button, index });
      });
      if (!items.length) {
        const empty = document.createElement("div");
        empty.className = "search-select-empty";
        empty.textContent = text("لا توجد نتائج مطابقة");
        list.append(empty);
      }
      const selected = items.findIndex(
        (i) => i.option.selected && !i.button.disabled,
      );
      focusOption(
        selected >= 0 ? selected : items.findIndex((i) => !i.button.disabled),
      );
      place();
    }
    function open(source, query = "") {
      if (source.disabled || source.closest("[inert]")) return;
      if (select === source) {
        search.focus();
        return;
      }
      close();
      select = source;
      source.focus({ preventScroll: true });
      original = Object.fromEntries(
        ["aria-expanded", "aria-controls"].map((k) => [
          k,
          source.getAttribute(k),
        ]),
      );
      popup = document.createElement("div");
      popup.className = "searchable-select-popup";
      popup.id = "masal-search-select";
      popup.dir = document.documentElement.dir || "rtl";
      popup.setAttribute("popover", "manual");
      popup.setAttribute("role", "dialog");
      const name =
        source.getAttribute("aria-label") ||
        source.labels?.[0]?.childNodes[0]?.textContent?.trim() ||
        text("اختيار");
      popup.setAttribute("aria-label", name);
      search = document.createElement("input");
      search.type = "search";
      search.className = "search-select-input";
      search.placeholder = text("بحث…");
      search.setAttribute("aria-label", text("بحث في القائمة"));
      search.setAttribute("role", "combobox");
      search.setAttribute("aria-autocomplete", "list");
      search.setAttribute("aria-expanded", "true");
      search.setAttribute("aria-controls", "masal-search-select-options");
      search.autocomplete = "off";
      search.value = query;
      list = document.createElement("div");
      list.id = "masal-search-select-options";
      list.className = "search-select-options";
      list.setAttribute("role", "listbox");
      list.setAttribute("aria-label", name);
      if (source.multiple) list.setAttribute("aria-multiselectable", "true");
      popup.append(search, list);
      document.body.append(popup);
      if (popup.showPopover) popup.showPopover();
      source.setAttribute("aria-expanded", "true");
      source.setAttribute("aria-controls", popup.id);
      search.addEventListener("input", render);
      observer = new MutationObserver(() => {
        if (select?.disabled) close();
        else render();
      });
      observer.observe(source, {
        attributes: true,
        childList: true,
        subtree: true,
        characterData: true,
      });
      render();
      search.focus({ preventScroll: true });
      place();
    }
    let pointerSource = null;
    function pointer(e) {
      const target = e.target.closest?.("select");
      if (target) {
        if (e.button !== 0) return;
        e.preventDefault();
        pointerSource = target;
        if (select === target) close(true);
        else open(target);
      } else if (popup && !popup.contains(e.target)) close();
    }
    function click(e) {
      const target = e.target.closest?.("select");
      if (target) {
        e.preventDefault();
        if (pointerSource === target) {
          pointerSource = null;
          return;
        }
        if (select === target) close(true);
        else open(target);
      }
    }
    function key(e) {
      if (popup && (popup.contains(e.target) || e.target === select)) {
        if (e.key === "Escape") {
          e.preventDefault();
          e.stopImmediatePropagation();
          close(true);
          return;
        }
        if (e.key === "Tab") {
          close(true);
          return;
        }
        if (["ArrowDown", "ArrowUp", "Home", "End"].includes(e.key)) {
          e.preventDefault();
          e.stopPropagation();
          const enabled = items
            .map((x, i) => (x.button.disabled ? -1 : i))
            .filter((i) => i >= 0);
          if (!enabled.length) return;
          const at = enabled.indexOf(active),
            next =
              e.key === "Home"
                ? 0
                : e.key === "End"
                  ? enabled.length - 1
                  : e.key === "ArrowDown"
                    ? (at + 1) % enabled.length
                    : (at - 1 + enabled.length) % enabled.length;
          focusOption(enabled[next]);
          return;
        }
        if (e.key === "Enter") {
          e.preventDefault();
          e.stopPropagation();
          choose(active);
          return;
        }
      }
      const target = e.target.closest?.("select");
      if (
        target &&
        !e.ctrlKey &&
        !e.metaKey &&
        (e.key === "ArrowDown" ||
          e.key === "ArrowUp" ||
          e.key === "Enter" ||
          e.key === " " ||
          e.key === "F4" ||
          e.key.length === 1)
      ) {
        e.preventDefault();
        e.stopPropagation();
        open(target, e.key.length === 1 && e.key !== " " ? e.key : "");
      }
    }
    const layout = () => {
      cancelAnimationFrame(raf);
      raf = requestAnimationFrame(place);
    };
    const mutation = new MutationObserver(() => {
      if (select && (!select.isConnected || select.closest("[inert]"))) close();
    });
    mutation.observe(document.getElementById("app"), {
      subtree: true,
      childList: true,
    });
    document.addEventListener("pointerdown", pointer, true);
    document.addEventListener("click", click, true);
    document.addEventListener("keydown", key, true);
    window.addEventListener("resize", layout);
    window.addEventListener("scroll", layout, true);
    window.visualViewport?.addEventListener("resize", layout);
    const unwatch = [
      vm.$watch("page", () => close()),
      vm.$watch("currentUser", () => close()),
      vm.$watch("modal", () => close()),
    ];
    return () => {
      close();
      mutation.disconnect();
      cancelAnimationFrame(raf);
      unwatch.forEach((fn) => fn());
      document.removeEventListener("pointerdown", pointer, true);
      document.removeEventListener("click", click, true);
      document.removeEventListener("keydown", key, true);
      window.removeEventListener("resize", layout);
      window.removeEventListener("scroll", layout, true);
      window.visualViewport?.removeEventListener("resize", layout);
    };
  }
  function install(o) {
    const mounted = o.mounted,
      unmounted = o.beforeUnmount;
    o.mounted = function () {
      mounted?.call(this);
      this._stopSearchSelects = setup(this);
    };
    o.beforeUnmount = function () {
      this._stopSearchSelects?.();
      unmounted?.call(this);
    };
  }
  root.MasalSearchSelects = { install };
})(globalThis);
