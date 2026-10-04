(function (root) {
  "use strict";
  // A small offline OOXML writer. All text is inline text, never executable formulas.
  const enc = new TextEncoder(),
    escape = (x) =>
      String(x ?? "")
        .replace(/[\x00-\x08\x0b\x0c\x0e-\x1f]/g, "")
        .replaceAll("&", "&amp;")
        .replaceAll("<", "&lt;")
        .replaceAll(">", "&gt;")
        .replaceAll('"', "&quot;");
  function crc(bytes) {
    let c = 0xffffffff;
    for (const b of bytes) {
      c ^= b;
      for (let j = 0; j < 8; j++) c = (c >>> 1) ^ (c & 1 ? 0xedb88320 : 0);
    }
    return (c ^ 0xffffffff) >>> 0;
  }
  function zip(files) {
    const parts = [],
      dirs = [];
    let offset = 0;
    for (const [name, text] of Object.entries(files)) {
      const n = enc.encode(name),
        v = enc.encode(text),
        sum = crc(v),
        h = new Uint8Array(30 + n.length),
        d = new DataView(h.buffer);
      d.setUint32(0, 0x04034b50, true);
      d.setUint16(4, 20, true);
      d.setUint32(14, sum, true);
      d.setUint32(18, v.length, true);
      d.setUint32(22, v.length, true);
      d.setUint16(26, n.length, true);
      h.set(n, 30);
      parts.push(h, v);
      const c = new Uint8Array(46 + n.length),
        q = new DataView(c.buffer);
      q.setUint32(0, 0x02014b50, true);
      q.setUint16(4, 20, true);
      q.setUint16(6, 20, true);
      q.setUint32(16, sum, true);
      q.setUint32(20, v.length, true);
      q.setUint32(24, v.length, true);
      q.setUint16(28, n.length, true);
      q.setUint32(42, offset, true);
      c.set(n, 46);
      dirs.push(c);
      offset += h.length + v.length;
    }
    const e = new Uint8Array(22),
      q = new DataView(e.buffer);
    q.setUint32(0, 0x06054b50, true);
    q.setUint16(8, dirs.length, true);
    q.setUint16(10, dirs.length, true);
    q.setUint32(
      12,
      dirs.reduce((n, v) => n + v.length, 0),
      true,
    );
    q.setUint32(16, offset, true);
    return new Blob([...parts, ...dirs, e], {
      type: "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet",
    });
  }
  function column(i) {
    let s = "";
    for (i++; i; i = Math.floor((i - 1) / 26))
      s = String.fromCharCode(65 + ((i - 1) % 26)) + s;
    return s;
  }
  function workbook(rows) {
    if (!rows.length) throw Error("لا توجد بيانات للتصدير");
    const headers = Object.keys(rows[0]),
      values = [headers, ...rows.map((r) => headers.map((h) => r[h]))],
      ns = "http://schemas.openxmlformats.org/spreadsheetml/2006/main";
    const sheet = `<worksheet xmlns="${ns}"><sheetViews><sheetView workbookViewId="0" rightToLeft="1"><pane ySplit="1" topLeftCell="A2" state="frozen"/></sheetView></sheetViews><cols>${headers.map((h, i) => `<col min="${i + 1}" max="${i + 1}" width="24" customWidth="1"/>`).join("")}</cols><sheetData>${values
      .map(
        (row, r) =>
          `<row r="${r + 1}">${row
            .map((v, c) => {
              const ref = column(c) + (r + 1);
              return typeof v === "number" && Number.isFinite(v)
                ? `<c r="${ref}" s="${r ? 0 : 1}"><v>${v}</v></c>`
                : `<c r="${ref}" s="${r ? 0 : 1}" t="inlineStr"><is><t xml:space="preserve">${escape(v)}</t></is></c>`;
            })
            .join("")}</row>`,
      )
      .join(
        "",
      )}</sheetData><autoFilter ref="A1:${column(headers.length - 1)}${values.length}"/></worksheet>`;
    return zip({
      "[Content_Types].xml":
        '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>',
      "_rels/.rels":
        '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>',
      "xl/workbook.xml": `<workbook xmlns="${ns}" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="ماسال" sheetId="1" r:id="rId1"/></sheets></workbook>`,
      "xl/_rels/workbook.xml.rels":
        '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>',
      "xl/styles.xml": `<styleSheet xmlns="${ns}"><fonts count="2"><font><sz val="11"/><name val="Arial"/></font><font><b/><color rgb="FFFFFFFF"/><sz val="11"/><name val="Arial"/></font></fonts><fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF0898B5"/><bgColor indexed="64"/></patternFill></fill></fills><borders count="1"><border/></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFill="1" applyFont="1"/></cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>`,
      "xl/worksheets/sheet1.xml": sheet,
    });
  }

  function reportWorkbook(sections, { rtl = true } = {}) {
    const ns = "http://schemas.openxmlformats.org/spreadsheetml/2006/main",
      rels = "http://schemas.openxmlformats.org/package/2006/relationships",
      used = new Set(),
      names = [];
    if (!sections.length) throw Error("لا توجد أقسام للتصدير");
    const files = {},
      sheetDefs = [],
      links = [],
      types = [];
    const cell = (value, r, c, style = 0) =>
      typeof value === "number" && Number.isFinite(value)
        ? `<c r="${column(c)}${r}" s="${style}"><v>${value}</v></c>`
        : `<c r="${column(c)}${r}" s="${style}" t="inlineStr"><is><t xml:space="preserve">${escape(value)}</t></is></c>`;
    sections.forEach((section, index) => {
      const base =
        String(section.name || "Sheet")
          .replace(/[\\/?*\[\]:]/g, " ")
          .replace(/^'+|'+$/g, "")
          .trim()
          .slice(0, 31) || "Sheet";
      let name = base,
        n = 1;
      while (used.has(name.toLowerCase())) {
        const suffix = " (" + ++n + ")";
        name = base.slice(0, 31 - suffix.length) + suffix;
      }
      used.add(name.toLowerCase());
      names.push(name);
      const headers = section.headers,
        rows = section.rows;
      if (!headers.length) throw Error("أعمدة التقرير غير موجودة");
      const last = column(headers.length - 1),
        lastRow = rows.length + 3;
      files[`xl/worksheets/sheet${index + 1}.xml`] =
        `<worksheet xmlns="${ns}"><sheetViews><sheetView workbookViewId="0" rightToLeft="${rtl ? 1 : 0}"><pane ySplit="3" topLeftCell="A4" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews><cols>${headers.map((h, i) => `<col min="${i + 1}" max="${i + 1}" width="${i === 0 ? 28 : 24}" customWidth="1"/>`).join("")}</cols><sheetData><row r="1" ht="30" customHeight="1">${cell(section.title || section.name, 1, 0, 1)}</row><row r="2" ht="44" customHeight="1">${cell(section.note || "", 2, 0)}</row><row r="3" ht="28" customHeight="1">${headers.map((h, i) => cell(h, 3, i, 1)).join("")}</row>${rows.map((row, r) => `<row r="${r + 4}" ht="28" customHeight="1">${headers.map((h, c) => cell(row[c], r + 4, c, typeof row[c] === "number" ? (section.formats?.[c] === "percent" ? 3 : 2) : 0)).join("")}</row>`).join("")}${!rows.length && section.emptyMessage ? `<row r="4">${cell(section.emptyMessage, 4, 0)}</row>` : ""}</sheetData><autoFilter ref="A3:${last}${Math.max(3, lastRow)}"/>${headers.length > 1 ? `<mergeCells count="2"><mergeCell ref="A1:${last}1"/><mergeCell ref="A2:${last}2"/></mergeCells>` : ""}<pageMargins left="0.3" right="0.3" top="0.5" bottom="0.5" header="0.2" footer="0.2"/></worksheet>`;
      sheetDefs.push(
        `<sheet name="${escape(name)}" sheetId="${index + 1}" r:id="rId${index + 1}"/>`,
      );
      links.push(
        `<Relationship Id="rId${index + 1}" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet${index + 1}.xml"/>`,
      );
      types.push(
        `<Override PartName="/xl/worksheets/sheet${index + 1}.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>`,
      );
    });
    files["[Content_Types].xml"] =
      `<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>${types.join("")}</Types>`;
    files["_rels/.rels"] =
      `<Relationships xmlns="${rels}"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>`;
    files["xl/workbook.xml"] =
      `<workbook xmlns="${ns}" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>${sheetDefs.join("")}</sheets></workbook>`;
    files["xl/_rels/workbook.xml.rels"] =
      `<Relationships xmlns="${rels}">${links.join("")}<Relationship Id="rId${sections.length + 1}" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>`;
    files["xl/styles.xml"] =
      `<styleSheet xmlns="${ns}"><numFmts count="2"><numFmt numFmtId="165" formatCode="#,##0.##"/><numFmt numFmtId="164" formatCode="#,##0.##&quot;%&quot;"/></numFmts><fonts count="2"><font><sz val="11"/><name val="Arial"/></font><font><b/><color rgb="FFFFFFFF"/><sz val="11"/><name val="Arial"/></font></fonts><fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF0898B5"/><bgColor indexed="64"/></patternFill></fill></fills><borders count="1"><border/></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="4"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0" applyAlignment="1"><alignment vertical="center" wrapText="1"/></xf><xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFill="1" applyFont="1"/><xf numFmtId="165" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/><xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/></cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>`;
    return zip(files);
  }

  root.MasalExcel = { workbook, reportWorkbook };
})(globalThis);
