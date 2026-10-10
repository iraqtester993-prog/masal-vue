export function createCatalogApi(api) {
  const query = (values) =>
    new URLSearchParams(
      Object.entries(values).filter(
        ([, v]) => v !== "" && v !== null && v !== undefined,
      ),
    ).toString();
  return {
    options: (signal) => api.request("/catalog/options", { signal }),
    list: (kind, filters, signal) =>
      api.request(`/catalog/${kind}?${query(filters)}`, { signal }),
    save: (kind, id, payload, file, removeImage, signal) => {
      const body = new FormData();
      body.set(
        "payload",
        JSON.stringify({
          ...payload,
          ...(removeImage ? { remove_image: true } : {}),
        }),
      );
      if (id) body.set("_method", "PATCH");
      if (file) body.set("image", file);
      return api.mutate(
        `/catalog/${kind}${id ? "/" + id : ""}`,
        "POST",
        body,
        signal,
      );
    },
    status: (kind, row, signal) =>
      api.mutate(
        `/catalog/${kind}/${row.id}/status`,
        "PATCH",
        { version: row.version, status: row.active ? "disabled" : "active" },
        signal,
      ),
    move: (row, direction, signal) =>
      api.mutate(
        `/catalog/products/${row.id}/move`,
        "PATCH",
        { version: row.version, direction: direction < 0 ? "up" : "down" },
        signal,
      ),
    export: (kind, filters, signal) =>
      api.request(`/catalog/${kind}/export?${query(filters)}`, { signal }),
    categories: (id, signal) =>
      api.request(`/accounts/${id}/categories`, { signal }),
    saveCategories: (id, payload, signal) =>
      api.mutate(`/accounts/${id}/categories`, "PUT", payload, signal),
    preferences: (signal) => api.request("/catalog/preferences", { signal }),
    savePreferences: (hidden_columns, signal) =>
      api.mutate("/catalog/preferences", "PUT", { hidden_columns }, signal),
  };
}
