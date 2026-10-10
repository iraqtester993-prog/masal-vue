(function (root) {
  "use strict";
  function delimited(text) {
    text = text.replace(/^\uFEFF/, "");
    const first = text.split(/\r?\n/)[0],
      delimiter = first.includes("\t") ? "\t" : first.includes(";") ? ";" : ",";
    const rows = [];
    let row = [],
      cell = "",
      quoted = false;
    for (let i = 0; i < text.length; i++) {
      const c = text[i];
      if (c === '"') {
        if (quoted && text[i + 1] === '"') {
          cell += '"';
          i++;
        } else quoted = !quoted;
      } else if (c === delimiter && !quoted) {
        row.push(cell.trim());
        cell = "";
      } else if ((c === "\r" || c === "\n") && !quoted) {
        if (c === "\r" && text[i + 1] === "\n") i++;
        row.push(cell.trim());
        if (row.some(Boolean)) rows.push(row);
        row = [];
        cell = "";
      } else cell += c;
    }
    if (quoted) throw Error("علامة اقتباس غير مغلقة");
    row.push(cell.trim());
    if (row.some(Boolean)) rows.push(row);
    return rows;
  }
  async function unzip(buffer) {
    const view = new DataView(buffer),
      bytes = new Uint8Array(buffer);
    let end = bytes.length - 22;
    for (; end >= Math.max(0, bytes.length - 65557); end--)
      if (view.getUint32(end, true) === 0x06054b50) break;
    if (end < 0) throw Error("ملف Excel غير صالح");
    const count = view.getUint16(end + 10, true);
    let cursor = view.getUint32(end + 16, true),
      total = 0;
    const files = {};
    if (count > 3000) throw Error("ملف Excel كبير جدًا");
    for (let i = 0; i < count; i++) {
      if (view.getUint32(cursor, true) !== 0x02014b50)
        throw Error("فهرس الملف غير صالح");
      const method = view.getUint16(cursor + 10, true),
        size = view.getUint32(cursor + 20, true),
        uncompressed = view.getUint32(cursor + 24, true),
        n = view.getUint16(cursor + 28, true),
        extra = view.getUint16(cursor + 30, true),
        comment = view.getUint16(cursor + 32, true),
        local = view.getUint32(cursor + 42, true),
        name = new TextDecoder().decode(
          bytes.slice(cursor + 46, cursor + 46 + n),
        );
      total += uncompressed;
      if (total > 40000000) throw Error("المحتوى أكبر من حد الاستيراد المحلي");
      if (
        /^xl\/(worksheets\/sheet\d+|sharedStrings|styles|workbook)\.xml$/.test(
          name,
        )
      ) {
        const start =
            local +
            30 +
            view.getUint16(local + 26, true) +
            view.getUint16(local + 28, true),
          raw = bytes.slice(start, start + size);
        if (method === 0) files[name] = new TextDecoder().decode(raw);
        else if (method === 8) {
          const stream = new Blob([raw])
            .stream()
            .pipeThrough(new DecompressionStream("deflate-raw"));
          files[name] = await new Response(stream).text();
        } else throw Error("ضغط Excel غير مدعوم");
      }
      cursor += 46 + n + extra + comment;
    }
    return files;
  }
  const xml = (text) => {
    const doc = new DOMParser().parseFromString(text, "application/xml");
    if (doc.querySelector("parsererror")) throw Error("بنية Excel غير صالحة");
    return doc;
  };
  async function read(file) {
    if (file.size > 15000000) throw Error("الحد الأقصى للملف 15 ميغابايت");
    if (!/\.xlsx$/i.test(file.name))
      return [{ name: file.name, rows: delimited(await file.text()) }];
    const files = await unzip(await file.arrayBuffer()),
      strings = files["xl/sharedStrings.xml"]
        ? [...xml(files["xl/sharedStrings.xml"]).querySelectorAll("si")].map(
            (x) => x.textContent,
          )
        : [];
    const styles = files["xl/styles.xml"] ? xml(files["xl/styles.xml"]) : null;
    const formats = {};
    styles
      ?.querySelectorAll("numFmt")
      .forEach(
        (x) =>
          (formats[x.getAttribute("numFmtId")] = x.getAttribute("formatCode")),
      );
    const dateStyles = [
      ...(styles?.querySelector("cellXfs")?.children || []),
    ].map((x) => {
      const id = +x.getAttribute("numFmtId");
      return (id >= 14 && id <= 22) || /[yd]/i.test(formats[id] || "");
    });
    const result = [];
    for (const [name, content] of Object.entries(files).filter(([k]) =>
      k.startsWith("xl/worksheets/"),
    )) {
      const rows = [...xml(content).querySelectorAll("sheetData row")]
        .map((row) => {
          const cells = [];
          row.querySelectorAll("c").forEach((c) => {
            let index = 0;
            for (const x of (c.getAttribute("r") || "A").replace(/\d/g, ""))
              index = index * 26 + x.charCodeAt(0) - 64;
            const type = c.getAttribute("t"),
              raw = c.querySelector("v")?.textContent || "",
              style = +c.getAttribute("s");
            let value =
              type === "s"
                ? strings[+raw]
                : type === "inlineStr"
                  ? c.querySelector("is")?.textContent
                  : raw;
            if (
              type !== "s" &&
              type !== "inlineStr" &&
              dateStyles[style] &&
              raw
            )
              value = new Date(Date.UTC(1899, 11, 30) + Number(raw) * 86400000)
                .toISOString()
                .slice(0, 10);
            if (c.querySelector("f"))
              throw Error("استبدل المعادلات بقيم ثابتة قبل الاستيراد");
            if (!type && /^\d{16,}$/.test(raw))
              throw Error(
                "الأرقام الطويلة يجب حفظها كنص في Excel لحماية رموز البطاقات",
              );
            cells[index - 1] = String(value ?? "");
          });
          return Array.from({ length: cells.length }, (_, i) => cells[i] || "");
        })
        .filter((r) => r.some(Boolean));
      result.push({ name: file.name + " / " + name.split("/").pop(), rows });
    }
    return result;
  }
  root.MasalImportReader = { read, delimited };
})(globalThis);
