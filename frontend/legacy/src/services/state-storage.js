(function (root) {
  "use strict";
  const key = "masal-v1",
    prefix = "MASAL-LZW1:",
    reset = 65535;
  // Lossless UTF-16 dictionary encoding; existing plain JSON remains readable.
  function compress(text) {
    if (!text) return prefix + "0:";
    const alphabet = [...new Set(text.split(""))].join("");
    if (alphabet.length >= reset) throw Error("تعذر ضغط بيانات النظام");
    let dictionary, next;
    const initialize = () => {
      dictionary = new Map(alphabet.split("").map((c, i) => [c, i]));
      next = alphabet.length;
    };
    initialize();
    const output = [];
    let phrase = "";
    for (let i = 0; i < text.length; i++) {
      const c = text[i],
        combined = phrase + c;
      if (dictionary.has(combined)) {
        phrase = combined;
        continue;
      }
      output.push(String.fromCharCode(dictionary.get(phrase)));
      if (next < reset) dictionary.set(combined, next++);
      else {
        output.push(String.fromCharCode(reset));
        initialize();
      }
      phrase = c;
    }
    if (phrase) output.push(String.fromCharCode(dictionary.get(phrase)));
    return prefix + alphabet.length + ":" + alphabet + output.join("");
  }
  function decompress(value) {
    if (!value?.startsWith(prefix)) return value;
    const delimiter = value.indexOf(":", prefix.length),
      count = Number(value.slice(prefix.length, delimiter));
    if (
      delimiter < 0 ||
      !Number.isInteger(count) ||
      count < 0 ||
      count >= reset
    )
      throw Error("بيانات التخزين المضغوطة غير صالحة");
    const alphabet = value.slice(delimiter + 1, delimiter + 1 + count),
      encoded = value.slice(delimiter + 1 + count);
    if (alphabet.length !== count) throw Error("بيانات التخزين المضغوطة ناقصة");
    let dictionary,
      next,
      previous = "";
    const initialize = () => {
      dictionary = alphabet.split("");
      next = count;
      previous = "";
    };
    initialize();
    const output = [];
    for (let i = 0; i < encoded.length; i++) {
      const code = encoded.charCodeAt(i);
      if (code === reset) {
        initialize();
        continue;
      }
      const phrase =
        dictionary[code] ??
        (code === next && previous ? previous + previous[0] : null);
      if (phrase === null) throw Error("بيانات التخزين المضغوطة تالفة");
      output.push(phrase);
      if (previous && next < reset) dictionary[next++] = previous + phrase[0];
      previous = phrase;
    }
    return output.join("");
  }
  let compressed = false;
  function read() {
    const value = localStorage.getItem(key);
    compressed = !!value?.startsWith(prefix);
    return decompress(value);
  }
  function write(state) {
    const json = typeof state === "string" ? state : JSON.stringify(state);
    try {
      if (compressed) {
        localStorage.setItem(key, compress(json));
        return;
      }
      try {
        localStorage.setItem(key, json);
      } catch (error) {
        if (
          error?.name !== "QuotaExceededError" &&
          error?.code !== 22 &&
          error?.code !== 1014
        )
          throw error;
        localStorage.setItem(key, compress(json));
        compressed = true;
      }
    } catch {
      throw Error(
        "تعذر حفظ البيانات في المتصفح؛ لم يكتمل الإجراء. مساحة التخزين غير كافية أو غير متاحة",
      );
    }
  }
  root.MasalStateStorage = { read, write, compress, decompress };
})(globalThis);
