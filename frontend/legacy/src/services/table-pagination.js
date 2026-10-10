(function (root) {
  "use strict";
  function setup(vm) {
    const states = new Map();
    let raf = 0;
    const tr = (s) => (vm.tr ? vm.tr(s) : s);
    function button(label, fn) {
      const b = document.createElement("button");
      b.type = "button";
      b.className = "btn small";
      b.textContent = tr(label);
      b.addEventListener("click", fn);
      return b;
    }
    function destroy(s) {
      s.top.remove();
      s.bottom.remove();
      s.rows.forEach((r) => r.classList.remove("table-page-hidden"));
      s.wrap.classList.remove("paginated-table-scroll");
    }
    function create(table) {
      const wrap = table.closest(".tablewrap");
      if (
        !wrap ||
        wrap.querySelectorAll("table").length !== 1 ||
        table.closest(".receipt,[data-no-pagination]")
      )
        return null;
      const s = { table, wrap, page: 1, size: 10, rows: [], signature: "" };
      s.top = document.createElement("div");
      s.top.className = "table-pagination-tools";
      const label = document.createElement("label");
      s.select = document.createElement("select");
      s.select.setAttribute("aria-label", tr("عدد الصفوف في الصفحة"));
      for (const value of [10, 25, 50, 100, 0]) {
        const o = document.createElement("option");
        o.value = String(value);
        o.textContent = value ? String(value) : tr("الكل");
        s.select.append(o);
      }
      label.append(s.select);
      s.top.append(label);
      s.count = document.createElement("span");
      s.top.append(s.count);
      s.select.addEventListener("change", () => {
        s.size = Number(s.select.value);
        s.page = 1;
        render(s);
        s.wrap.scrollTop = 0;
      });
      s.bottom = document.createElement("div");
      s.bottom.className = "table-pagination-footer";
      s.previous = button("السابق", () => {
        if (s.page > 1) {
          s.page--;
          render(s);
          s.wrap.scrollTop = 0;
        }
      });
      s.next = button("التالي", () => {
        if (s.page < s.pages) {
          s.page++;
          render(s);
          s.wrap.scrollTop = 0;
        }
      });
      s.status = document.createElement("span");
      s.status.setAttribute("aria-live", "polite");
      s.bottom.append(s.previous, s.status, s.next);
      wrap.before(s.top);
      wrap.after(s.bottom);
      wrap.classList.add("paginated-table-scroll");
      return s;
    }
    function place(s) {
      const siblings = [...s.wrap.parentElement.children];
      const header =
        s.wrap.parentElement.querySelector(
          ":scope > .inventory-catalog-filter > .catalog-filter-head, :scope > .price-catalog-filter > .catalog-filter-head",
        ) ||
        s.wrap
          .closest(".category-list,.provider-list")
          ?.querySelector(":scope > .catalog-filter > .catalog-filter-head") ||
        siblings
          .slice(0, siblings.indexOf(s.wrap))
          .find((el) =>
            el.matches(".print-policy-header,.toolbar,.agents-unified-toolbar"),
          );
      if (header) {
        if (s.top.parentElement !== header) header.append(s.top);
        s.top.classList.add("table-pagination-inline");
      } else {
        if (s.top.nextElementSibling !== s.wrap) s.wrap.before(s.top);
        s.top.classList.remove("table-pagination-inline");
      }
    }
    function render(s) {
      s.pages = Math.max(1, s.size ? Math.ceil(s.rows.length / s.size) : 1);
      s.page = Math.min(s.page, s.pages);
      const start = s.size ? (s.page - 1) * s.size : 0,
        end = s.size ? start + s.size : s.rows.length;
      s.rows.forEach((r, i) =>
        r.classList.toggle("table-page-hidden", i < start || i >= end),
      );
      s.count.textContent = s.rows.length
        ? `${start + 1}–${Math.min(end, s.rows.length)} ${tr("من")} ${s.rows.length}`
        : tr("لا توجد سجلات");
      s.status.textContent = `${tr("الصفحة")} ${s.page} ${tr("من")} ${s.pages}`;
      s.previous.disabled = s.page <= 1;
      s.next.disabled = s.page >= s.pages;
      s.top.hidden = s.bottom.hidden = !s.rows.length;
    }
    function refresh() {
      raf = 0;
      for (const [table, s] of states)
        if (!table.isConnected) {
          destroy(s);
          states.delete(table);
        }
      for (const table of document.querySelectorAll(
        "#app .tablewrap > table",
      )) {
        let s = states.get(table);
        if (!s) {
          s = create(table);
          if (!s) continue;
          states.set(table, s);
        }
        const rows = [...table.tBodies]
          .flatMap((b) => [...b.rows])
          .filter(
            (r) =>
              !r.querySelector("td.empty") &&
              !(
                r.cells.length === 1 &&
                r.cells[0].colSpan > 1 &&
                /لا توجد|لا يوجد/.test(r.textContent)
              ),
          );
        const sig = rows.map((r) => r.textContent).join("\u001f");
        if (sig !== s.signature) {
          s.page = 1;
          s.signature = sig;
        }
        s.rows.forEach((r) => {
          if (!rows.includes(r)) r.classList.remove("table-page-hidden");
        });
        s.rows = rows;
        place(s);
        render(s);
      }
    }
    function schedule(records) {
      if (
        records?.every((r) =>
          r.target.closest?.(
            ".table-pagination-tools,.table-pagination-footer",
          ),
        )
      )
        return;
      if (!raf) raf = requestAnimationFrame(refresh);
    }
    const observer = new MutationObserver(schedule);
    observer.observe(document.getElementById("app"), {
      childList: true,
      subtree: true,
      characterData: true,
    });
    schedule();
    return () => {
      observer.disconnect();
      cancelAnimationFrame(raf);
      states.forEach(destroy);
      states.clear();
    };
  }
  root.MasalTablePagination = {
    install(o) {
      const mount = o.mounted,
        unmount = o.beforeUnmount;
      o.mounted = function () {
        mount?.call(this);
        this._stopTablePagination = setup(this);
      };
      o.beforeUnmount = function () {
        this._stopTablePagination?.();
        unmount?.call(this);
      };
    },
  };
})(globalThis);
