/* global AVK_SETTINGS */

(() => {
  "use strict";

  /** @typedef {{kod:string, namn:string, farligt:boolean, beskrivning?:string, lagrum?:{förordning?:string, bilaga?:string}, krav?:{transporttillstånd?:boolean, rapportering_avfallsregister?:boolean, transportdokument?:boolean}}} AvfallKod */

  const state = {
    /** @type {Promise<AvfallKod[]> | null} */
    dataPromise: null,
    /** @type {number | null} */
    timer: null,
    /** @type {{activeIndex:number, items:Array<{kod:string, benamning:string}>}} */
    suggest: { activeIndex: -1, items: [] },
  };

  function normalizeCode(input) {
    return String(input || "")
      .toLowerCase()
      .replace(/\s+/g, "")
      .replace(/[^0-9*]/g, "");
  }

  function normalizeText(input) {
    return String(input || "")
      .toLowerCase()
      .trim();
  }

  function getSettings() {
    const s = typeof AVK_SETTINGS === "object" && AVK_SETTINGS ? AVK_SETTINGS : {};
    const i18n = s.i18n || {};
    return {
      restUrl: typeof s.restUrl === "string" ? s.restUrl : "",
      nonce: typeof s.nonce === "string" ? s.nonce : "",
      dataOk: s.dataOk !== false,
      dataLoadError: typeof s.dataLoadError === "string" ? s.dataLoadError : "",
      i18n: {
        searchPlaceholder: i18n.searchPlaceholder || "Sök på kod eller avfallstyp…",
        hazardOnly: i18n.hazardOnly || "Endast farligt avfall",
        noResults: i18n.noResults || "Inga träffar.",
        loadError: i18n.loadError || "Tekniskt fel: kunde inte ladda avfallskoder. Försök igen senare.",
        prompt: i18n.prompt || 'Skriv för att söka (t.ex. "asfalt" eller "17 03 01*").',
        hazardBadge: i18n.hazardBadge || "FARLIGT AVFALL",
        legalMore: i18n.legalMore || "Mer juridik",
        suggestionsLabel: i18n.suggestionsLabel || "Förslag",
      },
    };
  }

  function fetchSearch(q) {
    const { restUrl, nonce } = getSettings();
    if (!restUrl) {
      return Promise.reject(new Error("Missing restUrl"));
    }

    const url = new URL(restUrl, window.location.origin);
    url.searchParams.set("q", String(q || ""));

    const headers = {};
    if (nonce) {
      headers["X-WP-Nonce"] = nonce;
    }

    return fetch(url.toString(), {
      method: "GET",
      credentials: "same-origin",
      headers,
    }).then((res) => {
      if (!res.ok) {
        throw new Error("REST request failed");
      }
      return res.json();
    });
  }

  function el(tag, className) {
    const node = document.createElement(tag);
    if (className) node.className = className;
    return node;
  }

  function setText(node, text) {
    node.textContent = text == null ? "" : String(text);
  }

  function renderMessage(resultsEl, message) {
    resultsEl.innerHTML = "";
    const msg = el("div", "avk-empty avfall-empty");
    setText(msg, message);
    resultsEl.appendChild(msg);
  }

  function renderError(resultsEl, message) {
    resultsEl.innerHTML = "";
    const msg = el("div", "avk-empty avfall-error");
    setText(msg, message);
    resultsEl.appendChild(msg);
  }

  function clearSuggestions(suggestEl) {
    state.suggest.activeIndex = -1;
    state.suggest.items = [];
    if (suggestEl) {
      suggestEl.innerHTML = "";
      suggestEl.hidden = true;
    }
  }

  function renderSuggestions(suggestEl, items, labelText) {
    if (!(suggestEl instanceof HTMLElement)) return;
    suggestEl.innerHTML = "";
    state.suggest.items = items;
    state.suggest.activeIndex = -1;

    if (!items.length) {
      suggestEl.hidden = true;
      return;
    }

    const label = el("div", "avk-suggest-label");
    setText(label, labelText);
    suggestEl.appendChild(label);

    items.forEach((it, idx) => {
      const btn = el("button", "avk-suggest-item");
      btn.type = "button";
      btn.setAttribute("role", "option");
      btn.setAttribute("data-index", String(idx));
      btn.setAttribute("aria-selected", "false");
      setText(btn, `${it.kod} — ${it.benamning}`);
      btn.addEventListener("click", () => {
        const widget = suggestEl.closest(".avk-widget");
        const input = widget ? widget.querySelector(".avk-input") : null;
        if (input instanceof HTMLInputElement) {
          input.value = it.kod;
          input.dispatchEvent(new Event("input", { bubbles: true }));
          input.focus();
        }
        clearSuggestions(suggestEl);
      });
      suggestEl.appendChild(btn);
    });

    suggestEl.hidden = false;
  }

  function buildLegalLine(item) {
    if (typeof item.lagrum === "string" && item.lagrum.trim()) {
      return item.lagrum.trim();
    }
    const forordning = item.lagrum && item.lagrum["förordning"] ? item.lagrum["förordning"] : "Avfallsförordningen (2020:614)";
    const bilaga = item.lagrum && item.lagrum["bilaga"] ? item.lagrum["bilaga"] : "Bilaga 3";
    return `${forordning}, ${bilaga}`;
  }

  function buildLegalTooltip(item) {
    const parts = [];
    parts.push(buildLegalLine(item));

    if (item.krav) {
      const krav = [];
      if (item.krav.transporttillstånd) krav.push("Transporttillstånd");
      if (item.krav.rapportering_avfallsregister) krav.push("Rapportering (avfallsregister) ");
      if (item.krav.transportdokument) krav.push("Transportdokument");
      if (krav.length) parts.push(`Krav: ${krav.join(", ")}`);
    }

    return parts.join("\n");
  }

  function getLegalUrl(item) {
    const text = buildLegalLine(item);
    const t = normalizeText(text);

    function buildTextFragmentTarget() {
      const raw = item && item.kod ? String(item.kod) : "";
      const digits = raw.replace(/[^0-9]/g, "");
      if (digits.length >= 6) {
        const d = digits.slice(0, 6);
        return `${d.slice(0, 2)} ${d.slice(2, 4)} ${d.slice(4, 6)}`;
      }
      if (raw.trim()) return raw.trim();
      return "Bilaga 3";
    }

    // Prefer official sources.
    if (t.includes("2020:614")) {
      const base = "https://www.riksdagen.se/sv/dokument-lagar/dokument/svensk-forfattningssamling/avfallsforordning-2020614_sfs-2020-614";
      const target = buildTextFragmentTarget();
      return `${base}/#:~:text=${encodeURIComponent(target)}`;
    }

    if (t.includes("1998:808")) {
      return "https://www.riksdagen.se/sv/dokument-lagar/dokument/svensk-forfattningssamling/miljobalk-1998808_sfs-1998-808";
    }

    // Fallback: search on riksdagen.
    if (text && String(text).trim()) {
      return `https://www.riksdagen.se/sv/sok/?q=${encodeURIComponent(String(text).trim())}`;
    }

    return "";
  }

  function openInNewTab(url) {
    try {
      const w = window.open(url, "_blank", "noopener,noreferrer");
      if (w) w.opener = null;
    } catch {
      window.location.href = url;
    }
  }

  function renderResults(resultsEl, items, hazardBadgeText, legalMoreText) {
    resultsEl.innerHTML = "";

    const frag = document.createDocumentFragment();

    items.forEach((item) => {
      const card = el("article", "avk-card");

      const code = el("div", "avk-code");
      setText(code, item.kod || "");
      card.appendChild(code);

      const name = el("div", "avk-name");
      setText(name, item.benamning || item.namn || "");
      card.appendChild(name);

      if (item.farligt) {
        const badge = el("div", "avk-badge avk-badge--hazard");
        setText(badge, hazardBadgeText);
        card.appendChild(badge);
      }

      const legalWrap = el("div", "avk-legal");

      const legalLine1 = el("div", "avk-legal-line");
      setText(legalLine1, buildLegalLine(item));
      legalWrap.appendChild(legalLine1);

      const legalLine2 = el("div", "avk-legal-line");
      setText(legalLine2, (item.lagrum && item.lagrum["bilaga"]) || "");
      legalWrap.appendChild(legalLine2);

      const url = getLegalUrl(item);

      let legalMore;
      if (url) {
        legalMore = el("a", "avk-legal-more");
        legalMore.href = url;
        legalMore.target = "_blank";
        legalMore.rel = "noopener noreferrer";
      } else {
        legalMore = el("span", "avk-legal-more");
        legalMore.setAttribute("aria-disabled", "true");
      }

      legalMore.setAttribute("aria-label", legalMoreText);
      legalMore.title = buildLegalTooltip(item);
      setText(legalMore, legalMoreText);
      legalWrap.appendChild(legalMore);

      card.appendChild(legalWrap);

      frag.appendChild(card);
    });

    resultsEl.appendChild(frag);
  }

  function matches(item, qText, qCode, hazardOnly) {
    if (hazardOnly && !item.farligt) return false;

    if (!qText && !qCode) return false;

    const name = normalizeText(item.benamning || item.namn);
    const desc = normalizeText(item.beskrivning);
    const code = normalizeCode(item.kod);

    if (qCode) {
      if (code.includes(qCode)) return true;
      // Om användaren råkar skriva utan stjärna men det är farligt
      if (qCode.endsWith("*") === false && code.includes(qCode + "*")) return true;
    }

    if (qText) {
      if (name.includes(qText)) return true;
      if (desc.includes(qText)) return true;
      if (Array.isArray(item.nyckelord)) {
        for (const kw of item.nyckelord) {
          if (normalizeText(kw).includes(qText)) return true;
        }
      }
    }

    return false;
  }

  function initWidget(root) {
    const { i18n, dataOk, dataLoadError } = getSettings();

    const input = root.querySelector(".avk-input");
    const checkbox = root.querySelector(".avk-checkbox");
    const resultsEl = root.querySelector(".avk-results");
    const suggestEl = root.querySelector(".avk-suggest");

    if (!(input instanceof HTMLInputElement) || !(resultsEl instanceof HTMLElement)) {
      return;
    }

    input.placeholder = i18n.searchPlaceholder;

    if (!dataOk) {
      renderError(resultsEl, dataLoadError || i18n.loadError);
      return;
    }

    const run = () => {
      const raw = input.value || "";
      const qText = normalizeText(raw);
      const qCode = /\d/.test(raw) ? normalizeCode(raw) : "";
      const hazardOnly = checkbox instanceof HTMLInputElement ? !!checkbox.checked : false;

      if (!qText && !qCode) {
        clearSuggestions(suggestEl);
        renderMessage(resultsEl, i18n.prompt);
        return;
      }

      if (state.timer) {
        window.clearTimeout(state.timer);
      }

      state.timer = window.setTimeout(() => {
        resultsEl.setAttribute("aria-busy", "true");

        fetchSearch(raw)
          .then((payload) => {
            if (!payload || payload.ok !== true) {
              throw new Error("REST error");
            }

            const all = Array.isArray(payload.results) ? payload.results : [];
            const suggestions = Array.isArray(payload.suggestions) ? payload.suggestions : [];

            const filtered = all
              .filter((item) => matches(item, qText, qCode, hazardOnly))
              .slice(0, 100);

            const suggestItems = suggestions
              .map((s) => ({ kod: String(s.kod || ""), benamning: String(s.benamning || s.namn || "") }))
              .filter((s) => s.kod && s.benamning)
              .slice(0, 5);

            renderSuggestions(suggestEl, suggestItems, i18n.suggestionsLabel);

            if (!filtered.length) {
              renderMessage(resultsEl, i18n.noResults);
            } else {
              renderResults(resultsEl, filtered, i18n.hazardBadge, i18n.legalMore);
            }

            resultsEl.setAttribute("aria-busy", "false");
          })
          .catch(() => {
            clearSuggestions(suggestEl);
            renderError(resultsEl, i18n.loadError);
            resultsEl.setAttribute("aria-busy", "false");
          });
      }, 300);
    };

    input.addEventListener("keydown", (e) => {
      if (!(suggestEl instanceof HTMLElement) || suggestEl.hidden) return;
      const items = Array.from(suggestEl.querySelectorAll(".avk-suggest-item"));
      if (!items.length) return;

      if (e.key === "ArrowDown") {
        e.preventDefault();
        state.suggest.activeIndex = Math.min(state.suggest.activeIndex + 1, items.length - 1);
      } else if (e.key === "ArrowUp") {
        e.preventDefault();
        state.suggest.activeIndex = Math.max(state.suggest.activeIndex - 1, 0);
      } else if (e.key === "Enter") {
        if (state.suggest.activeIndex >= 0 && state.suggest.activeIndex < items.length) {
          e.preventDefault();
          items[state.suggest.activeIndex].click();
        }
        return;
      } else if (e.key === "Escape") {
        clearSuggestions(suggestEl);
        return;
      } else {
        return;
      }

      items.forEach((btn, idx) => {
        btn.setAttribute("aria-selected", idx === state.suggest.activeIndex ? "true" : "false");
      });
    });

    input.addEventListener("input", run);
    if (checkbox) checkbox.addEventListener("change", run);

    renderMessage(resultsEl, i18n.prompt);
  }

  function initAll() {
    const roots = document.querySelectorAll(".avk-widget[data-avk-widget='1']");
    roots.forEach((root) => initWidget(root));
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", initAll);
  } else {
    initAll();
  }
})();
