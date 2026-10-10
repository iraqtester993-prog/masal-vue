import test from "node:test";
import assert from "node:assert/strict";
import {
  newProduct,
  productFromApi,
  productPayload,
} from "../src/modules/catalog/catalog-model.js";
import { createCatalogApi } from "../src/modules/catalog/catalog-api.js";

test("catalog payload preserves exact monetary digits, Arabic input and one daily limit", () => {
  const form = {
    ...newProduct(),
    name: "فئة",
    provider: 3,
    face: "١٢٬٣٤٥٫٦٧",
    min: "9,876.54",
    dailyLimitType: "amount",
    dailyAmount: " ١٠٬٠٠٠٫٢٥ ",
    dailyQty: 100,
  };
  const result = productPayload(form, false);
  assert.equal(result.face_value, "12345.67");
  assert.equal(result.minimum_price, "9876.54");
  assert.equal(result.daily_amount, "10000.25");
  assert.equal(result.daily_quantity, null);
});
test("editing a reference category preserves unknown values until the owner configures them", () => {
  const row = productFromApi({
    id: 8,
    version: 2,
    name: "5 GIGA weekly",
    provider_id: 1,
    face_value: null,
    minimum_price: null,
    daily_limit_type: "",
    daily_quantity: null,
    daily_amount: null,
    status: "active",
    field_policy: {
      pin: "required",
      expiry: "required",
      serial: "required",
      cvc: "unused",
      reference: "unused",
    },
    import_codes: ["EVD1"],
  });
  assert.equal(row.face, null);
  assert.equal(row.min, null);
  assert.equal(row.dailyQty, null);
  assert.equal(row.importCodes, "EVD1");
});
test("specific province selection cannot silently become all provinces and import codes retain newlines", () => {
  const form = {
    ...newProduct(),
    provider: 1,
    face: "5000",
    min: "4500",
    importCodes: "ev5\nEV10،ev5",
  };
  assert.throws(() => productPayload(form, true), /محافظة/);
  assert.deepEqual(productPayload(form, false).import_codes, ["EV5", "EV10"]);
});
test("catalog image update uses PHP multipart method override without submitting the record id as a field", async () => {
  let received;
  const api = createCatalogApi({
    mutate: async (...args) => {
      received = args;
      return { data: {} };
    },
  });
  const picture = new File(["image fixture"], "photo.png", {
    type: "image/png",
  });
  await api.save(
    "providers",
    7,
    { name: "شركة", supplier: "مجهز", connection: "ملفات", version: 2 },
    picture,
    false,
  );
  assert.equal(received[0], "/catalog/providers/7");
  assert.equal(received[1], "POST");
  assert.equal(received[2].get("_method"), "PATCH");
  assert.equal(received[2].get("image"), picture);
  const payload = JSON.parse(received[2].get("payload"));
  assert.equal(payload.version, 2);
  assert.equal(Object.hasOwn(payload, "id"), false);
});
