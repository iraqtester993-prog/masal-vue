(function (root) {
  "use strict";
  if (
    location.protocol !== "file:" &&
    !["127.0.0.1", "localhost"].includes(location.hostname)
  )
    return;
  const call = async (path, payload) => {
    const response = await fetch("http://127.0.0.1:5180/" + path, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(payload),
    });
    const result = await response.json();
    if (!response.ok) throw Error(result.error || "تعذر الاتصال بالسيرفر");
    return result;
  };
  root.MasalDigitalServer = Object.fromEntries(
    ["catalog", "configure", "inventory", "submit", "verify", "receipt"].map(
      (name) => [name, (p) => call(name, p)],
    ),
  );
})(globalThis);
