(function (root) {
  "use strict";
  const clean = (v) =>
    String(v ?? "")
      .trim()
      .replace(/[\u200e\u200f\u202a-\u202e]/g, "");
  const code = (v) =>
    clean(v)
      .toUpperCase()
      .replace(/^(EVS|EVD)-/, "");
  const codes = (v) => [
    ...new Set(
      (Array.isArray(v) ? v : String(v || "").split(/[,،;\s]+/))
        .map(code)
        .filter(Boolean),
    ),
  ];
  // Category dictionary supplied by the user; amounts are face values, never costs.
  const reference = [
    ["5,000", "E5K EV5", 5000],
    ["10,000", "E10K EV10", 10000],
    ["15,000", "E15K EV15", 15000],
    ["25,000", "E25K EV25", 25000],
    ["40,000", "E40K EV40", 40000],
    ["100,000", "E100K EV1H", 100000],
    ["5 GIGA weekly", "EVD1"],
    ["10 GIGA monthly", "EVD2"],
    ["1,000", "EV1", 1000],
    ["3,000", "EV3", 3000],
    ["2,000", "EV2", 2000],
    ["50,000", "EV50", 50000],
    ["500", "EV5C", 500],
    ["750", "EV7C", 750],
    ["35,000", "EV35", 35000],
    ["MAX25", "EB25"],
    ["MAX35", "EB35"],
    ["6,000", "EV6", 6000],
    ["18,000", "EV18", 18000],
    ["MAX6", "EB6"],
    ["MAX12", "EB12"],
    ["MAX55", "EB55"],
    ["50,000 unlimited", "EU50K EVU5"],
    ["30,000", "EV30", 30000],
    ["70,000", "E70K EV70", 70000],
    ["150,000", "E150K EV150", 150000],
    ["200,000", "EV200", 200000],
    ["500,000", "EV500", 500000],
  ].map(([label, aliases, face]) => ({
    label,
    codes: aliases.split(" "),
    face,
  }));
  const referenceOf = (value) =>
    reference.find((r) => r.codes.includes(code(value)));
  const normal = (v) =>
    clean(v)
      .toLowerCase()
      .replace(/[,،\s._-]/g, "");
  function referenceMatches(product, entry) {
    if (!entry) return false;
    const name = normal(product.name),
      label = normal(entry.label);
    if (entry.face)
      return (
        Number(product.face) === entry.face &&
        (!product.currency || product.currency === "IQD") &&
        !/giga|جيجا|غيغا|gb|max|ماكس|unlimited|محدود|انترنت|إنترنت|باقة|باقه/i.test(
          product.name,
        )
      );
    return (
      name === label || name.endsWith("•" + label) || name.endsWith("·" + label)
    );
  }
  const header = (v) =>
    clean(v)
      .toLowerCase()
      .replace(/[\s_\-]/g, "");
  const aliases = {
    serial: [
      "serial",
      "serialnumber",
      "sn",
      "سيريال",
      "سيريل",
      "الرقمالتسلسلي",
    ],
    pin: ["pin", "pincode", "code", "hrn", "رمز", "رمزالبطاقة", "رمزالشحن"],
    expiry: [
      "expiry",
      "expirydate",
      "expiration",
      "expirationdate",
      "expiredate",
      "تاريخالانتهاء",
    ],
    cvc: ["cvc", "cvv"],
    reference: ["reference", "ref", "مرجع", "الرقمالمرجعي"],
    categoryCode: [
      "category",
      "categorycode",
      "productcode",
      "categoryid",
      "productid",
      "معرفالفئة",
      "معرّفالفئة",
      "معرفالفئةفيملفالطلبية",
      "رمزالفئة",
    ],
  };
  function date(v) {
    const s = clean(v);
    let m;
    if ((m = s.match(/^(\d{4})[-/](\d{1,2})[-/](\d{1,2})$/)))
      return `${m[1]}-${m[2].padStart(2, "0")}-${m[3].padStart(2, "0")}`;
    if ((m = s.match(/^(\d{1,2})[-/](\d{1,2})[-/](\d{4})$/)))
      return `${m[3]}-${m[2].padStart(2, "0")}-${m[1].padStart(2, "0")}`;
    return s;
  }
  function parseSheets(sheets) {
    const out = [];
    for (const sheet of sheets) {
      const rows = sheet.rows
        .map((r, i) => ({ cells: r.map(clean), line: i + 1 }))
        .filter((r) => r.cells.some(Boolean));
      if (!rows.length) continue;
      const first = rows[0].cells,
        head = first.map(header),
        mapping = {};
      for (const [key, names] of Object.entries(aliases)) {
        const i = head.findIndex((h) => names.includes(h));
        if (i >= 0) mapping[key] = i;
      }
      const supplier =
        first.length >= 5 &&
        /^\d+$/.test(first[1]) &&
        /^E[A-Z0-9-]+$/i.test(first[2]);
      const tabular =
        mapping.pin !== undefined ||
        mapping.serial !== undefined ||
        mapping.expiry !== undefined;
      const sms = rows.some((r) => /\bHRN\s+SN\b/i.test(r.cells.join(" ")));
      if (!supplier && !tabular && !sms) {
        out.push({
          name: sheet.name,
          format: "أعمدة غير معرّفة",
          categoryCode: "",
          declared: null,
          rawRows: rows.map((r) => ({ sourceRow: r.line, cells: r.cells })),
          rows: rows.map((r) => ({
            sourceRow: r.line,
            parseError: "حدد أعمدة بيانات البطاقات قبل الفحص",
          })),
        });
        continue;
      }
      const result = {
        name: sheet.name,
        format: supplier ? "ملف مجهز" : sms ? "رسائل البطاقات" : "أعمدة",
        declared: supplier ? Number(first[1]) : null,
        rows: [],
      };
      for (const r of rows.slice(supplier || tabular ? 1 : 0)) {
        let card = { sourceRow: r.line };
        if (supplier) {
          Object.assign(card, {
            serial: r.cells[0],
            pin: r.cells[1],
            expiry: r.cells[2],
            categoryCode: code(first[2]),
          });
          if (r.cells.length !== 3)
            card.parseError = "عدد الحقول لا يطابق صيغة المجهز";
        } else if (tabular) {
          for (const [key, i] of Object.entries(mapping))
            card[key] = r.cells[i] || "";
          for (let i = 0; i < first.length; i++)
            if (!Object.values(mapping).includes(i) && first[i])
              card[first[i]] = r.cells[i] || "";
        } else {
          const m = r.cells
            .join(" ")
            .match(
              /\b([A-Z][A-Z0-9-]*)\s*:\s*HRN\s+SN\s+(\d+)\s+(\d+)\s+.*?(\d{1,2}[-/]\d{1,2}[-/]\d{4})(?:.*?:\s*([\w-]+))?/i,
            );
          if (m)
            Object.assign(card, {
              categoryCode: code(m[1]),
              pin: m[2],
              serial: m[3],
              expiry: m[4],
              reference: m[5] || "",
            });
          else card.parseError = "رسالة بطاقة غير مفهومة";
        }
        card.expiry = date(card.expiry);
        card.categoryCode = code(card.categoryCode);
        result.rows.push(card);
      }
      if (!result.rows.length) throw Error("الملف لا يحتوي بطاقات");
      if (result.declared !== null && result.declared !== result.rows.length)
        throw Error("عدد بطاقات الملف لا يطابق العدد المعلن في رأس الملف");
      const distinct = [
        ...new Set(result.rows.map((r) => r.categoryCode).filter(Boolean)),
      ];
      if (distinct.length > 1)
        throw Error(
          "الورقة تحتوي أكثر من رمز فئة؛ افصل كل فئة في ورقة أو ملف مستقل",
        );
      result.categoryCode = distinct[0] || "";
      out.push(result);
    }
    if (!out.length) throw Error("الملف فارغ");
    if (out.reduce((n, f) => n + f.rows.length, 0) > 50000)
      throw Error("الحد الأقصى 50,000 بطاقة لكل رفع");
    return out;
  }
  root.MasalOrderParser = {
    code,
    codes,
    date,
    reference,
    referenceOf,
    referenceMatches,
    parseSheets,
    async read(file) {
      return parseSheets(await root.MasalImportReader.read(file));
    },
    parseText(text) {
      return parseSheets([
        { name: "نص ملصق", rows: root.MasalImportReader.delimited(text) },
      ]);
    },
  };
})(globalThis);
