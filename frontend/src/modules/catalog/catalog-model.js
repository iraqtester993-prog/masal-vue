export const catalogKey = Symbol("masal.catalog");
export const tr = (value) =>
  ({
    المزود: "الشركة",
    "المنتجات والفئات": "الفئات",
    "الشركات والمزودون": "الشركات",
  })[value] ?? String(value ?? "");
export const money = (value) =>
  new Intl.NumberFormat("en-US", { maximumFractionDigits: 2 }).format(
    Number(value) || 0,
  );
export const columns = [
  ["importCodes", "معرّفات الفئة في ملفات الطلبيات"],
  ["image", "الصورة"],
  ["name", "الفئة"],
  ["kind", "النوع"],
  ["provider", "الشركة"],
  ["face", "القيمة الاسمية"],
  ["currency", "عملة القيمة الاسمية"],
  ["min", "أقل سعر بيع مسموح"],
  ["dailyQty", "حد الكمية اليومي"],
  ["dailyAmount", "الحد المالي اليومي"],
  ["field_pin", "رمز الشحن / التفعيل"],
  ["field_expiry", "تاريخ الانتهاء"],
  ["field_serial", "الرقم التسلسلي"],
  ["field_cvc", "رمز التحقق"],
  ["field_reference", "الرقم المرجعي"],
  ["allowedCities", "المحافظات المسموحة"],
  ["receiptWidth", "عرض الوصل"],
  ["receiptHeader", "النص أعلى البطاقة"],
  ["receiptFooter", "النص أسفل البطاقة"],
  ["order", "الترتيب"],
  ["active", "الحالة"],
  ["actions", "الإجراءات"],
];
export const cardFields = [
  { key: "pin", label: "رمز الشحن / التفعيل — PIN Code", fixed: true },
  { key: "expiry", label: "تاريخ الانتهاء — Expiry Date", fixed: true },
  { key: "serial", label: "الرقم التسلسلي — Serial Number" },
  { key: "cvc", label: "رمز التحقق — CVC / CVV" },
  { key: "reference", label: "الرقم المرجعي — Reference" },
];
export const productFields = [
  { key: "name", label: "اسم الفئة" },
  { key: "provider", label: "المزود", options: "providers" },
  { key: "kind", label: "النوع", options: ["محلية", "عالمية"] },
  { key: "face", label: "القيمة الاسمية", type: "number", min: 1 },
  { key: "min", label: "أقل سعر بيع مسموح • د.ع", type: "number", min: 1 },
  {
    key: "fieldPolicy",
    label: "بيانات البطاقة المطلوبة عند الاستيراد",
    type: "cardFields",
  },
  { key: "order", label: "ترتيب الظهور", type: "number", min: 0 },
];
export const providerFields = [
  { key: "name", label: "اسم الشركة" },
  { key: "supplier", label: "الجهة المجهزة" },
  { key: "connection", label: "نوع الربط", options: ["ملفات", "API"] },
];
export const previewFields = [
  ["importCodes", "معرّفات الفئة في ملفات الطلبيات"],
  ["provider", "الشركة"],
  ["kind", "النوع"],
  ["face", "القيمة الاسمية"],
  ["currency", "عملة القيمة الاسمية"],
  ["min", "أقل سعر بيع مسموح • د.ع"],
  ["dailyQty", "حد الكمية اليومي"],
  ["dailyAmount", "الحد المالي اليومي • د.ع"],
  ["order", "الترتيب"],
  ["receiptLanguage", "لغة الوصل"],
  ["receiptWidth", "عرض الوصل"],
  ["receiptHeader", "النص أعلى البطاقة"],
  ["receiptFooter", "النص أسفل البطاقة"],
  ["fields", "بيانات البطاقة"],
  ["allowedCities", "المحافظات المسموحة"],
];
export function productFromApi(row) {
  return {
    ...row,
    provider: row.provider_id,
    face: row.face_value,
    min: row.minimum_price,
    dailyLimitType: row.daily_limit_type,
    dailyQty: row.daily_quantity,
    dailyAmount: row.daily_amount,
    fieldPolicy: row.field_policy,
    extraFields: row.extra_fields ?? [],
    order: row.display_order,
    importCodes: (row.import_codes ?? []).join(", "),
    receiptLanguage: row.receipt_language ?? "",
    receiptWidth: row.receipt_width ?? 80,
    receiptHeader: row.receipt_header ?? "",
    receiptFooter: row.receipt_footer ?? "",
    allowedCities: row.allowed_cities ?? [],
    image: row.image_url ?? "",
    active: row.status === "active",
  };
}
export function providerFromApi(row) {
  return { ...row, logo: row.logo_url ?? "", active: row.status === "active" };
}
export function newProduct() {
  return {
    name: "",
    provider: "",
    kind: "محلية",
    face: "",
    currency: "IQD",
    min: "",
    dailyLimitType: "quantity",
    dailyQty: 100,
    dailyAmount: null,
    fieldPolicy: {
      pin: "required",
      expiry: "required",
      serial: "required",
      cvc: "unused",
      reference: "unused",
    },
    extraFields: [],
    order: 1,
    importCodes: "",
    receiptLanguage: "",
    receiptWidth: 80,
    receiptHeader: "",
    receiptFooter: "",
    allowedCities: [],
    image: "",
  };
}
const decimal = (value) =>
  String(value ?? "")
    .replace(/[,\s]/g, "")
    .replace(/[٠-٩]/g, (digit) => String("٠١٢٣٤٥٦٧٨٩".indexOf(digit)))
    .replace(/[۰-۹]/g, (digit) => String("۰۱۲۳۴۵۶۷۸۹".indexOf(digit)))
    .replace(/٫/g, ".")
    .replace(/٬/g, "");
export function productPayload(form, specificCities) {
  if (specificCities && !form.allowedCities.length)
    throw new Error("اختر محافظة واحدة على الأقل أو اختر كل المحافظات");
  const data = {
    name: form.name,
    provider_id: Number(form.provider),
    kind: form.kind,
    face_value: decimal(form.face),
    currency: "IQD",
    minimum_price: decimal(form.min),
    daily_limit_type: form.dailyLimitType,
    daily_quantity:
      form.dailyLimitType === "quantity" ? Number(form.dailyQty) : null,
    daily_amount:
      form.dailyLimitType === "amount" ? decimal(form.dailyAmount) : null,
    field_policy: form.fieldPolicy,
    extra_fields: form.extraFields ?? [],
    display_order: Number(form.order),
    import_codes: [
      ...new Set(
        String(form.importCodes ?? "")
          .split(/[,،\n]/)
          .map((v) => v.trim().toUpperCase())
          .filter(Boolean),
      ),
    ],
    receipt_language: form.receiptLanguage ?? "",
    receipt_width: Number(form.receiptWidth),
    receipt_header: form.receiptHeader ?? "",
    receipt_footer: form.receiptFooter ?? "",
    allowed_cities: specificCities ? form.allowedCities : [],
  };
  if (form.id) data.version = form.version;
  return data;
}
export function catalogCell(vm, row, key) {
  if (key === "provider")
    return row.provider_name ?? vm.nameOf("providers", row.provider);
  if (key === "importCodes") return row.importCodes || "—";
  if (key === "allowedCities") return row.allowedCities?.join("، ") || "الكل";
  if (key === "receiptLanguage") return row.receiptLanguage || "لغة الوكيل";
  if (key === "receiptWidth") return (row.receiptWidth || 80) + " mm";
  if (key === "fields")
    return cardFields
      .filter((f) => row.fieldPolicy?.[f.key] === "required")
      .map((f) => f.label)
      .join("، ");
  if (key.startsWith("field_"))
    return row.fieldPolicy?.[key.slice(6)] === "required"
      ? "مطلوب"
      : "غير مستخدم";
  if (["dailyQty", "dailyAmount"].includes(key) && !(Number(row[key]) > 0))
    return "—";
  if (["face", "min", "dailyAmount", "dailyQty"].includes(key))
    return (
      money(row[key]) +
      (["min", "dailyAmount"].includes(key)
        ? " د.ع"
        : key === "dailyQty"
          ? " بطاقة"
          : "")
    );
  if (["receiptHeader", "receiptFooter"].includes(key)) return row[key] || "—";
  return row[key] ?? "—";
}
