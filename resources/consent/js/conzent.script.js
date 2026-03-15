window._cnzVersion = "12.05";

if (typeof window.is_consent_loaded === "undefined") {
  /**
   * ── Early Iframe/Script Blocker ──────────────────────────────────────
   * Runs immediately during HTML parsing (before <body> is fully parsed).
   * Intercepts iframes and third-party scripts as the parser adds them to
   * the DOM, BEFORE the browser fetches their src. No HTML changes needed.
   *
   * Flow:
   *   1. MutationObserver catches elements as parser adds them
   *   2. iframe src → saved to data-cnz-src, src set to about:blank, hidden
   *   3. third-party scripts → type changed to text/plain (prevents execution)
   *   4. After consent, Conzent_Blocker.runScripts() restores them
   */
  window._cnzBlockedEls = [];
  window._cnzConsentGiven = false;

  (function () {
    var ownOrigin = location.hostname;
    var ownScript = document.currentScript ? document.currentScript.src : "";

    // Google tag scripts must NOT be blocked — GCM consent defaults
    // handle data collection. Blocking gtag prevents GCM from working.
    var _cnzGoogleHosts = [
      "www.googletagmanager.com",
      "googletagmanager.com",
      "www.google-analytics.com",
      "google-analytics.com",
      "www.googleadservices.com",
      "googleads.g.doubleclick.net",
      "pagead2.googlesyndication.com",
    ];

    function isThirdParty(src) {
      if (!src || src === "" || src === "about:blank") return false;
      try {
        var url = new URL(src, location.href);
        // Same origin = not third-party
        if (url.hostname === ownOrigin || url.hostname === "") return false;
        // Don't block the OCI script itself
        if (ownScript && src.indexOf(ownScript) !== -1) return false;
        // Allow Google tag scripts — GCM consent state controls their behavior
        for (var g = 0; g < _cnzGoogleHosts.length; g++) {
          if (url.hostname === _cnzGoogleHosts[g]) return false;
        }
        return true;
      } catch (e) {
        return false;
      }
    }

    var observer = new MutationObserver(function (mutations) {
      if (window._cnzConsentGiven) return;
      for (var i = 0; i < mutations.length; i++) {
        var nodes = mutations[i].addedNodes;
        for (var j = 0; j < nodes.length; j++) {
          var el = nodes[j];
          if (!el.tagName) continue;
          var tag = el.tagName.toLowerCase();

          // Block iframes with third-party src
          if (tag === "iframe") {
            var src = el.getAttribute("src") || "";
            if (src && src !== "about:blank" && isThirdParty(src)) {
              // Capture dimensions from HTML attributes or inline style before hiding
              var iw = el.getAttribute("width") || el.style.width || "";
              var ih = el.getAttribute("height") || el.style.height || "";
              el.setAttribute("data-cnz-src", src);
              el.setAttribute("data-cnz-blocked", "pre-consent");
              el.setAttribute("data-blocked", "yes");
              if (iw) el.setAttribute("data-cnz-width", iw);
              if (ih) el.setAttribute("data-cnz-height", ih);
              if (!el.hasAttribute("data-consent")) {
                el.setAttribute("data-consent", "marketing");
              }
              el.removeAttribute("src");
              el.style.display = "none";
              window._cnzBlockedEls.push(el);
            }
          }

          // Block third-party scripts (not type=application/json, not oci script)
          if (tag === "script") {
            var scriptSrc = el.getAttribute("src") || "";
            if (scriptSrc && isThirdParty(scriptSrc)) {
              var scriptType = (el.getAttribute("type") || "").toLowerCase();
              if (
                scriptType !== "application/json" &&
                scriptType !== "application/ld+json" &&
                scriptType !== "text/plain" &&
                scriptType !== "javascript/blocked"
              ) {
                el.setAttribute("data-cnz-src", scriptSrc);
                el.setAttribute("data-cnz-blocked", "pre-consent");
                el.setAttribute("data-blocked", "yes");
                if (!el.hasAttribute("data-consent")) {
                  el.setAttribute("data-consent", "marketing");
                }
                el.type = "text/plain";
                window._cnzBlockedEls.push(el);
              }
            }
          }
        }
      }
    });

    // Start observing immediately — catches elements as the HTML parser adds them
    observer.observe(document.documentElement, {
      childList: true,
      subtree: true,
    });

    // Store observer reference so it can be disconnected after consent
    window._cnzEarlyObserver = observer;
  })();
}

// ── Cookie-name blocker ────────────────────────────────────────────
// Intercepts document.cookie writes to prevent known tracking cookies
// (e.g. _fbp, _ga) from being set by inline scripts before consent.
// Blocked writes are queued and replayed after consent if the category
// was granted.
if (typeof window._cnzCookieBlockerLoaded === "undefined") {
  window._cnzCookieBlockerLoaded = true;
  window._cnzBlockedCookies = [];

  var _cnzBlockedCookiePatterns = [BLOCKED_COOKIE_PATTERNS];

  if (_cnzBlockedCookiePatterns.length > 0) {
    var _cnzOrigCookieDesc =
      Object.getOwnPropertyDescriptor(Document.prototype, "cookie") ||
      Object.getOwnPropertyDescriptor(HTMLDocument.prototype, "cookie");

    if (_cnzOrigCookieDesc && _cnzOrigCookieDesc.set) {
      Object.defineProperty(document, "cookie", {
        get: function () {
          return _cnzOrigCookieDesc.get.call(this);
        },
        set: function (val) {
          if (!window._cnzConsentGiven) {
            var name = val.split("=")[0].trim();
            for (var i = 0; i < _cnzBlockedCookiePatterns.length; i++) {
              try {
                if (
                  new RegExp(_cnzBlockedCookiePatterns[i].p).test(name)
                ) {
                  window._cnzBlockedCookies.push({
                    v: val,
                    c: _cnzBlockedCookiePatterns[i].c,
                  });
                  return; // silently block
                }
              } catch (e) {
                // invalid regex — skip
              }
            }
          }
          _cnzOrigCookieDesc.set.call(this, val);
        },
        configurable: true,
      });

      // Replay blocked cookies after consent — called from cnz._AcceptAll / cnz._Accept
      window._cnzReplayBlockedCookies = function (grantedCategories) {
        if (!window._cnzBlockedCookies.length) return;
        var remaining = [];
        for (var i = 0; i < window._cnzBlockedCookies.length; i++) {
          var entry = window._cnzBlockedCookies[i];
          if (grantedCategories.indexOf(entry.c) !== -1) {
            _cnzOrigCookieDesc.set.call(document, entry.v);
          } else {
            remaining.push(entry);
          }
        }
        window._cnzBlockedCookies = remaining;
      };
    }
  }
}
// ── End cookie-name blocker ────────────────────────────────────────

// ── End early blocker guard ──────────────────────────────────────
// Main consent script runs regardless of whether the early blocker ran
// (the CMP loader sets is_consent_loaded before this script loads)

if (typeof window._cnzMainLoaded === "undefined") {
  window._cnzMainLoaded = true;

  [IAB2_STUB](() => {
    [IAB2_SCRIPT];

    window.is_consent_loaded = true;

    const CNZ_config = {
      settings: [SETTINGS],

      displayBanner: "[DISPLAY_BANNER]",

      currentLang: document.documentElement.lang || navigator.language || "",

      gpcStatus: !!navigator.globalPrivacyControl,

      user_site: [USER_SITE],

      currentTarget: "[GEO_TARGET]",

      in_eu: false,

      allowed_domains: [ALLOWED_DOMAINS],

      providers_to_block: [PROVIDERS_BLOCKED],

      cookiesCategories: [COOKIES_CATEGORIES],

      cookies: [SITE_COOKIES_LIST],

      publisherCountry: "[PUBLISHER_COUNTRY]",

      placeholder_trans: [PLACEHOLDER_TRANS],

      placeholderText: "[PLACEHOLDER_TEXT]",

      prefered_langs: [PREFERED_LANGS],

      default_lang: "[DEFAULT_LANG]",

      main_lang: "[MAIN_LANG]",

      ccpa_setting: [CCPA_SETTINGS],
      debug_mode: [DEBUG_MODE],
      blockall_iframe: [BLOCK_IFRAMES],
      cross_domain: [CROSS_DOMAINS],
    };

    // Ensure conzent_id cookie exists — used for consent tracking + A/B variant selection
    var _cnzCid =
      (document.cookie.match(/(?:^|;\s*)conzent_id=([^;]*)/) || [])[1] || "";
    if (!_cnzCid) {
      _cnzCid =
        "cnz_" +
        Math.random().toString(36).substr(2, 9) +
        Date.now().toString(36);
      document.cookie =
        "conzent_id=" + _cnzCid + ";path=/;max-age=31536000;SameSite=Lax";
    }

    // A/B variant selection — deterministic based on conzent_id hash
    var _cnzAbVariants = [AB_VARIANTS];
    window._cnzVariantId = "";
    if (_cnzAbVariants.length > 1) {
      // djb2 hash
      var _h = 5381;
      for (var _i = 0; _i < _cnzCid.length; _i++) {
        _h = ((_h << 5) + _h + _cnzCid.charCodeAt(_i)) >>> 0;
      }
      var _tw = 0;
      for (var _j = 0; _j < _cnzAbVariants.length; _j++) {
        _tw += _cnzAbVariants[_j].w;
      }
      var _pick = _h % _tw,
        _acc = 0;
      for (var _k = 0; _k < _cnzAbVariants.length; _k++) {
        _acc += _cnzAbVariants[_k].w;
        if (_pick < _acc) {
          var _v = _cnzAbVariants[_k];
          window._cnzVariantId = _v.id;
          // Merge pre-rendered variant config over defaults
          if (_v.cfg && typeof CNZ_config.settings === "object") {
            var _s = CNZ_config.settings;
            for (var _p in _v.cfg) {
              if (_v.cfg.hasOwnProperty(_p)) _s[_p] = _v.cfg[_p];
            }
          }
          break;
        }
      }
    }

    window.conzent = window.conzent || {};

    var cnz = window.conzent;

    cnz._Store = {
      _backupNodes: [],

      _providersToBlock: CNZ_config.providers_to_block,

      _categories: CNZ_config.cookiesCategories,

      _bannerConfig: CNZ_config.settings,
    };

    try {
      ((cnz._CreateElementBackup = document.createElement),
        (document.createElement = function () {
          for (var t, e = arguments.length, r = new Array(e), n = 0; n < e; n++)
            r[n] = arguments[n];

          var o = (t = cnz._CreateElementBackup).call.apply(
            t,
            [document].concat(r),
          );

          if ("script" !== o.nodeName.toLowerCase()) return o;

          var s = o.setAttribute.bind(o);

          return (
            Object.defineProperties(o, {
              src: {
                get: function () {
                  return o.getAttribute("src") || "";
                },

                set: function (t) {
                  return (
                    c_cn(o, t) && s("type", "javascript/blocked"),
                    s("src", t),
                    !0
                  );
                },

                configurable: !0,
              },

              type: {
                get: function () {
                  return o.getAttribute("type") || "";
                },

                set: function (t) {
                  return (
                    (t = c_cn(o) ? "javascript/blocked" : t),
                    s("type", t),
                    !0
                  );
                },

                configurable: !0,
              },
            }),
            (o.setAttribute = function (t, e) {
              if ("type" === t || "src" === t) return (o[t] = e);

              (s(t, e),
                "data-consent" !== t ||
                  c_cn(o) ||
                  s("type", "text/javascript"));
            }),
            o
          );
        }));
    } catch (t) {
      showConsoleError(t);
    }

    function c_cn(t, e) {
      return (
        (t.hasAttribute("data-consent") &&
          cnz._categoryToBlocked(
            t.getAttribute("data-consent").replace("conzent-", ""),
          )) ||
        cnz._BlockProvider(e || t.src)
      );
    }

    function cnzEvent(t, e) {
      var r = new CustomEvent(t, {
        detail: e,
      });

      document.dispatchEvent(r);
    }

    ((cnz._EscapeRegex = function (t) {
      return t.replace(/[.*+?^${}()[\]\\]/g, "\\$&");
    }),
      (cnz._ReplaceAll = function (t, e, r) {
        return t.replace(new RegExp(cnz._EscapeRegex(e), "g"), r);
      }),
      (cnz._StartsWith = function (t, e) {
        return t.slice(0, e.length) === e;
      }),
      (cnz._categoryToBlocked = function (t) {
        // Check in-memory allowed_categories first (updated immediately on consent)
        if (CNZ_config.settings.allowed_categories.indexOf(t) !== -1) {
          return false; // Category is allowed, not blocked
        }
        var e = cnz._getSavedConsent(t);

        return (
          "" === e ||
          (!e &&
            cnz._Store._categories.some(function (e) {
              return e.slug === t && e.slug != "necessary";
            }))
        );
      }),
      (cnz._BlockProvider = function (t) {
        var e = cnz._Store._providersToBlock.find(function (e) {
          var r = e.url;

          return new RegExp(cnz._EscapeRegex(r)).test(t);
        });

        return (
          e &&
          e.categories.some(function (t) {
            return cnz._categoryToBlocked(t);
          })
        );
      }),
      (cnz._getSavedConsent = function (field) {
        var preferences_val = accessCookie("conzentConsentPrefs"),
          preferences_item = preferences_val ? JSON.parse(preferences_val) : null;

        if (preferences_item) {
          return preferences_item.includes(field) ? field : "";
        }

        return "";
      }));

    const revisitCnzConsent = () => {
      var b_action = !cookieExists("conzentConsent");

      conzentOpenBox();
    };

    const cookieIcon = () => {
      if (CNZ_config.settings.logoType == 2) {
        if (CNZ_config.settings.logoEmbedded != "") {
          return (
            "<span id='cookieIcon'>" +
            CNZ_config.settings.logoEmbedded +
            "</span>"
          );
        } else {
          return (
            "<img id='cookieIcon' alt='' src='" +
            CNZ_config.settings.default_logo +
            "' width='40'>"
          );
        }
      } else {
        if (
          CNZ_config.settings.logoUrl == "" ||
          CNZ_config.settings.logoUrl == "#"
        ) {
          return (
            "<img id='cookieIcon' alt='' src='" +
            CNZ_config.settings.default_logo +
            "' width='40'>"
          );
        } else {
          return (
            "<img id='cookieIcon' alt='' src='" +
            CNZ_config.settings.logoUrl +
            "' width='40'>"
          );
        }
      }
    };

    const revisitcookieIcon = () => {
      if (
        CNZ_config.settings.revisit_logo == "" ||
        CNZ_config.settings.revisit_logo == "#"
      ) {
        return (
          "<img id='cookieIcon' alt='' src='" +
          CNZ_config.settings.default_logo +
          "' width='30'>"
        );
      } else {
        return (
          "<img id='cookieIcon' alt='' src='" +
          CNZ_config.settings.revisit_logo +
          "' width='30'>"
        );
      }
    };

    /**

			 * Check if cookie exists

			 * @param {string} cookieName

			 */

    const cookieExists = (cookieName) => {
      if (document.cookie.indexOf(cookieName) > -1) {
        return true;
      }

      return false;
    };

    /**

			 * Create the cookie and hide the banner

			 * @param {string} value

			 * @param {string} expiryDays

			 */

    const hideCookieBanner = (value, expiryDays) => {
      Conzent_Cookie.set("conzentConsent", value, expiryDays, 1);

      fadeOutEffect();
    };

    /**

			 * Set Cookie

			 * @param {string} name -  Cookie Name

			 * @param {string} value - Cookie Value

			 * @param {string} expiryDays - Expiry Date of cookie

			 */

    const createCookie = (name, value, options = {}) => {
      document.cookie = `${name}=${value}${Object.keys(options)

        .reduce((acc, key) => {
          return (
            acc +
            `;${key.replace(/([A-Z])/g, ($1) => "-" + $1.toLowerCase())}=${
              options[key]
            }`
          );
        }, "")}`;
    };

    /**

			 * Converts Days Into UTC String

			 * @param {number} days - Name of the cookie

			 * @return {string} UTC date string

			 */

    const daysToUTC = (days) => {
      const newDate = new Date();

      newDate.setTime(newDate.getTime() + days * 24 * 60 * 60 * 1000);

      return newDate.toUTCString();
    };

    /**

			 * Get Cookie By Name

			 * @param {string} name - Name of the cookie

			 * @return {string(number|Array)} Value of the cookie

			 */

    const accessCookie = (name) => {
      const cookies = document.cookie.split(";").reduce((acc, cookieString) => {
        const [key, value] = cookieString.split("=").map((s) => s.trim());

        if (key && value) {
          acc[key] = decodeURIComponent(value);
        }

        return acc;
      }, {});

      return name ? cookies[name] || "" : cookies;
    };
    const _getThirdpartyUrls = function () {
      const elementsWithUrls = document.querySelectorAll(
        "script[src], iframe[src]",
      );
      const currentOrigin = window.location.origin;
      const thirdPartyUrls = [];
      elementsWithUrls.forEach((element) => {
        let url;
        if (element.hasAttribute("href")) {
          url = element.getAttribute("href");
        } else if (element.hasAttribute("src")) {
          url = element.getAttribute("src");
        }

        // Ensure the URL is not empty and is an absolute URL
        if (url && url.startsWith("http")) {
          try {
            const urlObject = new URL(url);
            if (urlObject.origin !== currentOrigin) {
              if (!thirdPartyUrls.includes(urlObject.hostname)) {
                thirdPartyUrls.push(urlObject.hostname);
              }
            }
          } catch (error) {
            // Handle invalid URLs if necessary
            //console.error("Invalid URL encountered:", url, error);
          }
        }
      });

      return thirdPartyUrls;
    };
    const cnzPageViewLog = function (t) {
      var e =
        CNZ_config.settings.banner_id > 1 ? CNZ_config.settings.banner_id : "";
      const jsCookiesList = parseDocumentCookie(document.cookie || "");
      const th_urls = _getThirdpartyUrls();
      try {
        var r = {
            consent_session_id: accessCookie("conzent_id") || _cnzCid,
            banner_id: e,
            url: window.location.href.split("?")[0],
            cookies: jsCookiesList,
            thirdparty: th_urls,
            consent_phase: accessCookie("conzentConsent")
              ? "post_consent"
              : "pre_consent",
          },
          n = new FormData();

        (n.append("key", "[WEBSITE_KEY]"),
          n.append("request_type", t),
          n.append("log_time", Math.round(Date.now() / 1e3)),
          n.append("payload", JSON.stringify(r)),
          navigator.sendBeacon("[API_PATH]/log", n));
      } catch (t) {
        //console.error(t)
        showConsoleError(t);
      }
    };

    if (
      !(function () {
        try {
          var domain_check = getRootDomain();

          for (
            var t = domain_check.replace(/^www\./, ""),
              e = window.location.hostname.replace(/^www\./, "").split("."),
              r = 0;
            r < e.length;
            r++
          ) {
            if (e.slice(r).join(".") === t) return !0;
          }

          return !1;
        } catch (t) {
          return !1;
        }
      })()
    )
      throw new Error(
        showConsoleError(
          "Looks like your website URL has changed. To ensure the proper functioning of your banner, update the registered URL on your Conzent App account. Then, reload this page to retry. If the issue persists, please contact us at https://conzent.net/installation-guide/.",
        ),
      );

    _getLang();

    CNZ_config.placeholderText = CNZ_config.placeholderText.replaceAll(
      "[conzent_alt_text_blocked_content]",
      CNZ_config.placeholder_trans[CNZ_config.currentLang],
    );

    cnz._observer = new MutationObserver((mutationList) =>
      mutationList
        .filter((m) => m.type === "childList")
        .forEach((m) => {
          // Skip blocking when consent has been given
          if (window._cnzConsentGiven) return;
          var nodes = m.addedNodes;

          if (nodes.length > 0) {
            var elem = nodes[0];

            if (
              !elem.src ||
              !elem.nodeName ||
              !["figure", "script", "iframe"].includes(
                elem.nodeName.toLowerCase(),
              )
            )
              return 0;

            // Never block scripts injected by Conzent itself (GTM auto-inject)
            if (elem.id && elem.id.indexOf("cnz-") === 0) return 0;

            var e = cnz._StartsWith(elem.src, "//")
                ? "".concat(window.location.protocol).concat(elem.src)
                : elem.src,
              r = new URL(e),
              n = r.hostname,
              s = r.pathname,
              a = "".concat(n).concat(s).replace(/^www./, "");

            //if (checkProvider(elem,a),!cnz._BlockProvider(a)) return 0;

            if (a != "blank") {
              if ((checkProvider(elem, a), !cnz._BlockProvider(a))) return 0;
            } else {
              if (elem.nodeName.toLowerCase() === "iframe") {
                var n =
                  elem.hasAttribute("data-consent") &&
                  elem.getAttribute("data-consent");

                if (!n) {
                  elem.setAttribute("data-consent", "marketing");

                  n = "marketing";
                }

                var rt_val = cnz._Store._categories.some(function (e) {
                  if (e.slug === n && e.slug != "necessary") return 1;
                });

                if (rt_val == 1) {
                  return 0;
                } else {
                  if (cookieExists("conzentConsent") == true) {
                    if (_checkCookieCategory(n) == "yes") {
                      return 0;
                    }
                  }
                }
              }
            }

            var f = conzentUniqueKey(16);

            if (
              elem.nodeName.toLowerCase() === "figure" ||
              elem.nodeName.toLowerCase() === "iframe"
            ) {
              if (elem.innerHTML !== "undefined") {
                var htmlElemWidth = elem.offsetWidth;

                var htmlElemHeight = elem.offsetHeight;

                var vId = "";

                if (0 !== htmlElemWidth && 0 !== htmlElemHeight) {
                  let VID_REGEX =
                    /(?:youtube(?:-nocookie)?\.com\/(?:[^\/\n\s]+\/\S+\/|(?:v|e(?:mbed)?)\/|\S*?[?&]v=)|youtu\.be\/)([a-zA-Z0-9_-]{11})/;

                  if (elem.nodeName.toLowerCase() === "iframe") {
                    var img = "";

                    if (CNZ_config.settings.logoEmbedded != "") {
                      img =
                        "<span id='cookieIcon'>" +
                        CNZ_config.settings.logoEmbedded +
                        "</span>";
                    } else {
                      img =
                        "<img id='cookieIcon' alt='' src='" +
                        CNZ_config.settings.default_logo +
                        "' width='40'>";
                    }

                    //elem.remove();

                    var htmlElemType = CNZ_config.placeholderText;

                    var pixelPattern = /px/;

                    htmlElemWidth = pixelPattern.test(htmlElemWidth)
                      ? htmlElemWidth
                      : htmlElemWidth + "px";

                    htmlElemHeight = pixelPattern.test(htmlElemHeight)
                      ? htmlElemHeight
                      : htmlElemHeight + "px";

                    var addPlaceholder = "";

                    var show_placeholder = "";

                    if (elem.hasAttribute("src")) {
                      if (
                        elem.getAttribute("src") == "about:blank" ||
                        htmlElemWidth == "0px" ||
                        htmlElemHeight == "0px"
                      ) {
                        show_placeholder = " cnz-hide ";
                      } else {
                        if (elem.getAttribute("src") != "") {
                          if (elem.getAttribute("src").match(VID_REGEX)) {
                            vId = elem.getAttribute("src").match(VID_REGEX)[1];
                          }
                        } else {
                          show_placeholder = " cnz-hide ";
                        }
                      }

                      if (vId != "") {
                        addPlaceholder =
                          '<div id="' +
                          f +
                          '" style="width:' +
                          htmlElemWidth +
                          "; height:" +
                          htmlElemHeight +
                          ";background-image:linear-gradient(rgba(76, 72, 72, 0.7), rgba(76, 72, 72, 0.7)), url(https://img.youtube.com/vi/" +
                          vId +
                          '/maxresdefault.jpg);"  class="cnz-iframe-placeholder"><div class="cnz-inner-text">' +
                          htmlElemType +
                          "</div></div>";
                      } else {
                        if (
                          CNZ_config.blockall_iframe == 1 &&
                          elem.nodeName.toLowerCase() === "iframe"
                        ) {
                          addPlaceholder =
                            '<div id="' +
                            f +
                            '" style="width:' +
                            htmlElemWidth +
                            "; height:" +
                            htmlElemHeight +
                            ';" class="cnz-iframe-placeholder ' +
                            show_placeholder +
                            '"><div class="cnz-inner-text">' +
                            htmlElemType +
                            "</div></div>";
                        } else {
                          return 0;
                        }
                      }

                      if (elem.getAttribute("src") != "null") {
                        //elem.setAttribute( 'data-cnz-src',elem.getAttribute('src') );
                      }
                    } else {
                      show_placeholder = " cnz-hide ";

                      if (htmlElemWidth == "0px" || htmlElemHeight == "0px") {
                        show_placeholder = " cnz-hide ";
                      }

                      addPlaceholder =
                        '<div id="' +
                        f +
                        '" style="width:' +
                        htmlElemWidth +
                        "; height:" +
                        htmlElemHeight +
                        ';" class="cnz-iframe-placeholder ' +
                        show_placeholder +
                        '"><div class="cnz-inner-text">' +
                        htmlElemType +
                        "</div></div>";
                    }

                    elem.insertAdjacentHTML("afterend", addPlaceholder);

                    //elem.removeAttribute('src');

                    elem.style.display = "none";
                  }
                }
              }
            } else {
              if (elem.src.includes("[WEBSITE_KEY]/script.js")) {
                return 0;
              }

              elem.type = "javascript/blocked";

              elem.addEventListener("beforescriptexecute", function e(r) {
                (r.preventDefault(),
                  elem.removeEventListener("beforescriptexecute", e));
              });
            }

            var l =
              document.head.compareDocumentPosition(elem) &
              Node.DOCUMENT_POSITION_CONTAINED_BY
                ? "head"
                : "body";

            (elem.remove(),
              cnz._Store._backupNodes.push({
                position: l,

                node: elem.cloneNode(),

                uniqueID: f,
              }));
          }
        }),
    );

    cnz._observer.observe(document, { childList: true, subtree: true });

    function blockItems(allnodes) {
      allnodes.forEach(function (script_tag) {
        var nodes = script_tag;

        if (nodes) {
          var elem = nodes;

          if (
            !elem.src ||
            !elem.nodeName ||
            !["figure", "script", "iframe"].includes(
              elem.nodeName.toLowerCase(),
            )
          )
            return 0;

          // Never block scripts injected by Conzent itself (GTM auto-inject)
          if (elem.id && elem.id.indexOf("cnz-") === 0) return 0;

          var e = cnz._StartsWith(elem.src, "//")
              ? "".concat(window.location.protocol).concat(elem.src)
              : elem.src,
            r = new URL(e),
            n = r.hostname,
            s = r.pathname,
            a = "".concat(n).concat(s).replace(/^www./, "");

          //if (checkProvider(elem,a),!cnz._BlockProvider(a)) return 0;

          if (a != "blank") {
            if ((checkProvider(elem, a), !cnz._BlockProvider(a))) return 0;
          } else {
            if (elem.nodeName.toLowerCase() === "iframe") {
              var n =
                elem.hasAttribute("data-consent") &&
                elem.getAttribute("data-consent");

              if (!n) {
                elem.setAttribute("data-consent", "marketing");

                n = "marketing";
              }

              var rt_val = cnz._Store._categories.some(function (e) {
                if (e.slug === n && e.slug != "necessary") return 1;
              });

              if (rt_val == 1) {
                return 0;
              } else {
                if (cookieExists("conzentConsent") == true) {
                  if (_checkCookieCategory(n) == "yes") {
                    return 0;
                  }
                }
              }
            }
          }

          var f = conzentUniqueKey(16);

          if (
            elem.nodeName.toLowerCase() === "figure" ||
            elem.nodeName.toLowerCase() === "iframe"
          ) {
            if (elem.innerHTML !== "undefined") {
              var htmlElemWidth = elem.offsetWidth;

              var htmlElemHeight = elem.offsetHeight;

              var vId = "";

              if (0 !== htmlElemWidth && 0 !== htmlElemHeight) {
                let VID_REGEX =
                  /(?:youtube(?:-nocookie)?\.com\/(?:[^\/\n\s]+\/\S+\/|(?:v|e(?:mbed)?)\/|\S*?[?&]v=)|youtu\.be\/)([a-zA-Z0-9_-]{11})/;

                if (elem.nodeName.toLowerCase() === "iframe") {
                  var img = "";

                  if (CNZ_config.settings.logoEmbedded != "") {
                    img =
                      "<span id='cookieIcon'>" +
                      CNZ_config.settings.logoEmbedded +
                      "</span>";
                  } else {
                    img =
                      "<img id='cookieIcon' alt='' src='" +
                      CNZ_config.settings.default_logo +
                      "' width='40'>";
                  }

                  //elem.remove();

                  var htmlElemType = CNZ_config.placeholderText;

                  var pixelPattern = /px/;

                  htmlElemWidth = pixelPattern.test(htmlElemWidth)
                    ? htmlElemWidth
                    : htmlElemWidth + "px";

                  htmlElemHeight = pixelPattern.test(htmlElemHeight)
                    ? htmlElemHeight
                    : htmlElemHeight + "px";

                  var addPlaceholder = "";

                  var show_placeholder = "";

                  if (elem.hasAttribute("src")) {
                    if (
                      elem.getAttribute("src") == "about:blank" ||
                      htmlElemWidth == "0px" ||
                      htmlElemHeight == "0px"
                    ) {
                      show_placeholder = " cnz-hide ";
                    } else {
                      if (elem.getAttribute("src") != "") {
                        if (elem.getAttribute("src").match(VID_REGEX)) {
                          vId = elem.getAttribute("src").match(VID_REGEX)[1];
                        }
                      } else {
                        show_placeholder = " cnz-hide ";
                      }
                    }

                    if (vId != "") {
                      addPlaceholder =
                        '<div id="' +
                        f +
                        '" style="width:' +
                        htmlElemWidth +
                        "; height:" +
                        htmlElemHeight +
                        ";background-image:linear-gradient(rgba(76, 72, 72, 0.7), rgba(76, 72, 72, 0.7)), url(http://img.youtube.com/vi/" +
                        vId +
                        '/maxresdefault.jpg);"  class="cnz-iframe-placeholder"><div class="cnz-inner-text">' +
                        htmlElemType +
                        "</div></div>";
                    } else {
                      addPlaceholder =
                        '<div id="' +
                        f +
                        '" style="width:' +
                        htmlElemWidth +
                        "; height:" +
                        htmlElemHeight +
                        ';" class="cnz-iframe-placeholder ' +
                        show_placeholder +
                        '"><div class="cnz-inner-text">' +
                        htmlElemType +
                        "</div></div>";
                    }

                    if (elem.getAttribute("src") != "null") {
                      //elem.setAttribute( 'data-cnz-src',elem.getAttribute('src') );
                    }
                  } else {
                    show_placeholder = " cnz-hide ";

                    if (htmlElemWidth == "0px" || htmlElemHeight == "0px") {
                      show_placeholder = " cnz-hide ";
                    }

                    addPlaceholder =
                      '<div id="' +
                      f +
                      '" style="width:' +
                      htmlElemWidth +
                      "; height:" +
                      htmlElemHeight +
                      ';" class="cnz-iframe-placeholder ' +
                      show_placeholder +
                      '"><div class="cnz-inner-text">' +
                      htmlElemType +
                      "</div></div>";
                  }

                  elem.insertAdjacentHTML("beforebegin", addPlaceholder);

                  //elem.removeAttribute('src');

                  elem.style.display = "none";
                }
              }
            }
          } else {
            if (elem.src.includes("[WEBSITE_KEY]/script.js")) {
              return 0;
            }

            elem.type = "javascript/blocked";

            elem.addEventListener("beforescriptexecute", function e(r) {
              (r.preventDefault(),
                elem.removeEventListener("beforescriptexecute", e));
            });
          }

          var l =
            document.head.compareDocumentPosition(elem) &
            Node.DOCUMENT_POSITION_CONTAINED_BY
              ? "head"
              : "body";

          (elem.remove(),
            cnz._Store._backupNodes.push({
              position: l,

              node: elem.cloneNode(),

              uniqueID: f,
            }));
        }
      });
    }

    function runCall() {
      if (1 !== navigator.doNotTrack) {
        var t = accessCookie("conzentConsent");

        ("gdpr" !== CNZ_config.displayBanner ||
          (t && "true" === t) ||
          !cnz._Store._categories.every(function (t) {
            return t.slug == "necessary" || "" === _checkCookieCategory(t.slug);
          })) &&
          (cnz._Store._backupNodes = cnz._Store._backupNodes.filter(
            function (t) {
              var e = t.position,
                r = t.node,
                n = t.uniqueID;

              try {
                if (cnz._BlockProvider(r.src)) return !0;

                if ("script" === r.nodeName.toLowerCase()) {
                  // Use _CreateElementBackup to bypass the blocking override
                  var o = cnz._CreateElementBackup.call(document, "script");

                  (o.setAttribute("src", r.src),
                    o.setAttribute("type", "text/javascript"),
                    o.setAttribute("data-blocked", "no"),
                    o.setAttribute("data-cnz-restored", "true"),
                    document[e].appendChild(o));
                } else {
                  var i = document.getElementById(n);

                  if (!i) return !1;

                  var c = cnz._CreateElementBackup.call(document, "iframe");

                  ((c.src = r.src),
                    (c.width = i.offsetWidth),
                    (c.height = i.offsetHeight),
                    i.parentNode.insertBefore(c, i),
                    i.parentNode.removeChild(i));
                }

                return !1;
              } catch (t) {
                //return console.error(t), !1
                return (showConsoleError(t), !1);
              }
            },
          ));
      }
    }

    function checkProvider(e, r) {
      var n = e.hasAttribute("data-consent") && e.getAttribute("data-consent");

      if (n) {
        var o,
          s = n;

        cnz._Store._categories.some(function (e) {
          if (e.slug == "necessary" && e.slug === s) return;
        });

        var c = cnz._Store._providersToBlock.find(function (t) {
          return t.url === r;
        });

        c
          ? c.isOverriden
            ? c.categories.includes(s) || c.categories.push(s)
            : ((c.categories = [s]), (c.isOverriden = !0))
          : cnz._Store._providersToBlock.push({
              url: r,

              categories: [s],
            });
      }
    }

    function _QE(el) {
      return document.querySelector(el);
    }

    function _QEA(el) {
      return document.querySelectorAll(el);
    }

    function _getLang() {
      var currentLang = CNZ_config.currentLang;

      if (CNZ_config.prefered_langs.indexOf(currentLang) !== -1) {
        currentLang = CNZ_config.currentLang;
      } else {
        var langPart = currentLang.split("_");

        langPart = langPart[0].split("-");

        if (CNZ_config.prefered_langs.indexOf(langPart[0]) !== -1) {
          currentLang = langPart[0];
        } else {
          currentLang = CNZ_config.default_lang;
        }
      }

      CNZ_config.currentLang = currentLang;

      return currentLang;
    }

    function getCnzConsent() {
      var e = {};

      e["necessary"] = true;

      e["analytics"] =
        checkAllowedCookie("analytics") == "granted" ? true : false;

      e["advertisement"] =
        checkAllowedCookie("marketing") == "granted" ? true : false;

      e["functional"] =
        checkAllowedCookie("functional") == "granted" ? true : false;

      e["preferences"] =
        checkAllowedCookie("preferences") == "granted" ? true : false;

      e["performance"] =
        checkAllowedCookie("performance") == "granted" ? true : false;

      e["unclassified"] =
        checkAllowedCookie("unclassified") == "granted" ? true : false;

      e["meta"] =
        typeof fbq === "function" &&
        checkAllowedCookie("marketing") == "granted"
          ? "grant"
          : "revoke";

      return e;
    }

    const Conzent_Cookie = {
      set: function (name, value, days) {
        var same_site = "";

        if (days) {
          var expires = "; expires=" + daysToUTC(days);
        } else {
          var expires = "";
        }

        /*if(opt == 1){

						same_site = ";domain="+CNZ_config.settings._root_domain+"; SameSite=Strict;secure";

					}

					*/

        //same_site = ";domain=;SameSite=Strict;secure";

        var n_domain =
            arguments.length > 4 && void 0 !== arguments[4]
              ? ""
              : CNZ_config.user_site.consent_sharing == 1
                ? "." + getRootDomain()
                : "",
          secureAttribute =
            "https:" === window.location.protocol
              ? ";SameSite=Strict;secure"
              : "";

        //same_site = ";domain="+CNZ_config.settings._root_domain+"; SameSite=Strict"+secureAttribute;

        same_site = ";domain=" + n_domain + "" + secureAttribute;

        document.cookie = name + "=" + value + expires + "; path=/" + same_site;
      },

      read: function (name) {
        const cookie_item = document.cookie
          .split(";")
          .reduce((acc, cookieString) => {
            const [key, value] = cookieString.split("=").map((s) => s.trim());

            if (key && value) {
              acc[key] = decodeURIComponent(value);
            }

            return acc;
          }, {});

        return name ? cookie_item[name] || "" : cookie_item;
      },

      exists: function (name) {
        return !!this.read(name);
      },

      getallcookies: function () {
        var pairs = document.cookie.split(";");

        var cookieslist = {};

        var pairs_length = pairs.length;

        for (var i = 0; i < pairs_length; i++) {
          var pair = pairs[i].split("=");

          cookieslist[(pair[0] + "").trim()] = unescape(pair[1]);
        }

        return cookieslist;
      },

      clearallCookies: function () {
        let allcookies = this.getallcookies();

        var newt = allcookies;
        var fetched_cookie = CNZ_config.settings.cookiesList;

        for (var i = 0; i < Object.keys(allcookies).length; i++) {
          cookie_name = Object.keys(allcookies)[i].split("=")[0];

          if (Object.keys(fetched_cookie).length > 0) {
            for (var jk = 0; jk < Object.keys(fetched_cookie).length; jk++) {
              var cc_list = Object.keys(fetched_cookie)[jk];

              if (fetched_cookie[cc_list].length > 0) {
                for (var jm = 0; jm < fetched_cookie[cc_list].length; jm++) {
                  if (cookie_name == fetched_cookie[cc_list][jm].name) {
                    if (
                      CNZ_config.settings.allowed_cookies.indexOf(
                        cookie_name,
                      ) !== -1
                    ) {
                    } else {
                      this.erase(cookie_name, "");
                    }
                  }
                }
              }
            }
          }
        }

        //localStorage.clear();

        //sessionStorage.clear();
      },

      blockMissedCookie: function () {
        let allcookies = this.getallcookies();

        var newt = allcookies;

        var fetched_cookie = CNZ_config.settings.cookiesList;

        for (var i = 0; i < Object.keys(allcookies).length; i++) {
          var cookie_name = Object.keys(allcookies)[i].split("=")[0],
            cookie_found = 2;

          if (Object.keys(fetched_cookie).length > 0) {
            for (var jk = 0; jk < Object.keys(fetched_cookie).length; jk++) {
              var cc_list = Object.keys(fetched_cookie)[jk];

              if (fetched_cookie[cc_list].length > 0) {
                for (var jm = 0; jm < fetched_cookie[cc_list].length; jm++) {
                  if (cookie_name == fetched_cookie[cc_list][jm].name) {
                    cookie_found = 1;
                  }
                }
              }
            }
          }

          if (cookie_found == 2) {
            if (
              CNZ_config.settings.allowed_cookies.indexOf(cookie_name) !== -1
            ) {
            } else {
              //this.erase(cookie_name);
            }
          }
        }
      },

      erase: function (name) {
        //this.set( name, "", -365,opt);

        //document.cookie = name + '=; Max-Age=0'

        var same_site = "",
          expires = "; expires=" + daysToUTC(-365) + "; Max-Age=-99999999",
          value = "",
          c = window.location.host,
          a = c.replace("www", ""),
          n =
            arguments.length > 1 && void 0 !== arguments[1]
              ? arguments[1]
              : CNZ_config.user_site.consent_sharing == 1
                ? getRootDomain()
                : "",
          SecureAttribute =
            "https:" === window.location.protocol ? "; secure" : "";

        //SecureAttribute ='';

        if (arguments.length == 1) {
          //n = a;
        }

        if (n != "") {
          n = "; domain=" + n;
        }

        // same_site = n+"; SameSite=Strict"+SecureAttribute;

        same_site = n + "" + SecureAttribute;

        document.cookie = name + "=" + value + expires + "; path=/" + same_site;

        if (n != "") {
          var i_dt = window.location.hostname.split(".");

          if (i_dt.length >= 2) {
            var ak = i_dt[i_dt.length - 2] + "." + i_dt[i_dt.length - 1];

            var same_site2 = "; domain=" + ak + "" + SecureAttribute;

            document.cookie =
              name + "=" + value + expires + "; path=/" + same_site2;

            var same_site2 = "; domain=." + ak + "" + SecureAttribute;

            document.cookie =
              name + "=" + value + expires + "; path=/" + same_site2;
          }
        }

        var x_val = localStorage.getItem(name);

        x_val && localStorage.removeItem(x_val);

        var u_val = localStorage.getItem(name);

        u_val && sessionStorage.removeItem(u_val);
      },
    };

    const consentFun = {
      onConsentAccept: () => {
        // It will inject scripts in head if cookie preferences menu(showSettingsBtn) is enabled

        //CNZ_config.settings.showSettingsBtn? injectScripts() : null;

        injectScripts();
      },

      onConsentSaved: () => {
        // It will inject scripts in head if cookie preferences menu(showSettingsBtn) is enabled

        //CNZ_config.settings.showSettingsBtn? injectScripts() : null;

        injectScripts();
      },

      onConsentReject: () => {
        // This code will run on cookie reject/decline

        // remove all cookies before reloading the page

        var same_site = "https:" === window.location.protocol ? ";secure" : "";
        var wp_cookies = [
          "wp_consent_functional",
          "wp_consent_marketing",
          "wp_consent_preferences",
          "wp_consent_statistics",
          "wp_consent_statistics-anonymous",
        ];
        var cnz_cookies = [
          "conzentConsent",
          "conzentConsentPrefs",
          "conzent_id",
          "lastRenewedDate",
        ];
        document.cookie.split(";").forEach(function (c) {
          var ck_name = c.replace(/^ +/, "").split("=")[0];
          if (
            wp_cookies.includes(ck_name) == false &&
            cnz_cookies.includes(ck_name) == false
          ) {
            document.cookie = c
              .replace(/^ +/, "")
              .replace(
                /=.*/,
                "=;expires=" + new Date().toUTCString() + ";path=/" + same_site,
              );
          }
        });

        //localStorage.clear();

        //sessionStorage.clear();

        //console.log("Consent Rejected!");

        injectScripts();
      },
    };

    const blockScriptIframe = {
      scripts: function () {
        //const allscript = _QEA('script[data-consent]');

        const allscript = _QEA("script");

        allscript.forEach(function (script_tag) {
          //			  	console.log(script_tag.getAttribute('src'));

          if (script_tag.getAttribute("type") == "") {
            script_tag.setAttribute("type", "text/javascript");
          }

          if (script_tag.getAttribute("src") != null) {
            for (
              var ij = 0;
              ij < CNZ_config.settings.allowed_scripts.length;
              ij++
            ) {
              var newsrc = script_tag.getAttribute("src");

              if (
                newsrc.indexOf(CNZ_config.settings.allowed_scripts[ij]) !== -1
              ) {
                script_tag.setAttribute("data-consent", "necessary");

                script_tag.setAttribute("data-blocked", "no");

                script_tag.setAttribute("type", "text/javascript");
              } else {
                script_tag.addEventListener(
                  "beforescriptexecute",
                  function e(r) {
                    (r.preventDefault(),
                      script_tag.removeEventListener("beforescriptexecute", e));
                  },
                );
              }
            }
          }

          CNZ_config.settings.beaconsList.forEach(function (script_src) {
            if (script_src.url.indexOf(script_tag.getAttribute("src")) !== -1) {
              if (script_src.category == "unclassified") {
                if (CNZ_config.settings.blockUnspecifiedBeacons == true) {
                  script_tag.setAttribute("data-consent", script_src.category);

                  //script_tag.setAttribute('data-blocked','yes');

                  //script_tag.setAttribute('type',"text/plain");
                } else {
                  script_tag.setAttribute("data-consent", script_src.category);
                }
              } else {
                if (script_src.category == "necessary") {
                  script_tag.setAttribute("data-blocked", "no");

                  script_tag.setAttribute("type", "text/javascript");
                }

                script_tag.setAttribute("data-consent", script_src.category);
              }
            }
          });

          if (
            CNZ_config.settings.allowed_categories.indexOf(
              script_tag.getAttribute("data-consent"),
            ) !== -1
          ) {
            script_tag.setAttribute("type", "text/javascript");
          } else {
            //console.log(script_tag.getAttribute('data-consent'));

            //script_tag.setAttribute('type',"text/plain");

            script_tag.setAttribute("data-consent", "unclassified");
          }

          if (!script_tag.hasOwnProperty("data-consent")) {
            for (
              var ij = 0;
              ij < CNZ_config.settings.allowed_scripts.length;
              ij++
            ) {
              var newsrc = script_tag.getAttribute("src");

              if (newsrc) {
                if (
                  newsrc.indexOf(CNZ_config.settings.allowed_scripts[ij]) !== -1
                ) {
                  script_tag.setAttribute("data-consent", "necessary");

                  script_tag.setAttribute("data-blocked", "no");

                  script_tag.setAttribute("type", "text/javascript");
                } else {
                  script_tag.setAttribute("data-consent", "unclassified");
                }
              } else {
                script_tag.setAttribute("data-consent", "unclassified");
              }
            }
          }

          if (script_tag.getAttribute("data-consent") !== "necessary") {
            if (
              script_tag.getAttribute("type") == "text/javascript" ||
              script_tag.getAttribute("type") == "application/javascript"
            ) {
              //script_tag.setAttribute('type',"text/plain");

              if (
                CNZ_config.settings.allowed_categories.indexOf(
                  script_tag.getAttribute("data-consent"),
                ) !== -1
              ) {
                //script_tag.setAttribute('type',"text/javascript");
              } else {
                //console.log(3);

                /*script_tag.setAttribute('type',"text/plain");*/

                script_tag.addEventListener(
                  "beforescriptexecute",
                  function e(r) {
                    (r.preventDefault(),
                      script_tag.removeEventListener("beforescriptexecute", e));
                  },
                );
              }
            }
          }

          if (script_tag.getAttribute("data-consent") == "necessary") {
            script_tag.setAttribute("type", "text/javascript");

            script_tag.setAttribute("data-blocked", "no");
          }
        });
      },

      iframes: function () {
        const alliframe = _QEA("iframe");

        alliframe.forEach(function (script_tag) {
          if (
            script_tag.getAttribute("data-consent") !== "necessary" ||
            !script_tag.hasOwnProperty("data-consent")
          ) {
            //script_tag.setAttribute('data-blocked',"yes");

            script_tag.setAttribute(
              "data-cnz-placeholder",
              CNZ_config.placeholderText,
            );

            script_tag.setAttribute("data-consent", "marketing");

            if (script_tag.hasAttribute("src")) {
              script_tag.setAttribute(
                "data-cnz-src",
                script_tag.getAttribute("src"),
              );
            }
          }
        });
      },
    };

    const ConzentFN = function (event) {
      var domain_name = window.location.host;

      var url_param = window.location.href;

      var current_settings = CNZ_config.settings;

      var currentLang = _getLang();

      _QE("head").insertAdjacentHTML("beforeend", current_settings.css_content);

      CNZ_config.placeholderText = CNZ_config.placeholder_trans[currentLang];
      //

      Conzent_Blocker.retriveCookies();

      changeRootVariables();

      let conzentConsentExists = cookieExists("conzentConsent");

      let cookiePrefsValue = accessCookie("conzentConsentPrefs");

      // If consent is not accepted

      var preferences = cookiePrefsValue ? JSON.parse(cookiePrefsValue) : null;

      if (url_param.includes("?cnz_preview=true")) {
      }

      let cookieTypes = '<ul class="cnz_banner_cookie_list">';

      current_settings.cookieTypes.forEach((field) => {
        if (field.type[currentLang] !== "" && field.value[currentLang] !== "") {
          let cookieTypeDescription = "";

          if (field.description[currentLang] !== false) {
            //cookieTypeDescription = ' title="' + field.description[currentLang] + '"';
          }

          if (field.value == "necessary") {
            cookieTypes +=
              '<li><div class="conzent-switch"><input type="checkbox" id="PrefItem' +
              field.value +
              '" checked="checked" disabled="disabled" name="gdprPrefItem" value="' +
              field.value +
              '" data-compulsory="on" class="sliding-switch"> <label for="PrefItem' +
              field.value +
              '"' +
              cookieTypeDescription +
              ">" +
              field.type[currentLang] +
              "</label></div></li>";
          } else {
            cookieTypes +=
              '<li><div class="conzent-switch"><input type="checkbox" id="PrefItem' +
              field.value +
              '" name="gdprPrefItem" value="' +
              field.value +
              '" data-compulsory="off" class="sliding-switch"> <label for="PrefItem' +
              field.value +
              '"' +
              cookieTypeDescription +
              ">" +
              field.type[currentLang] +
              "</label></div></li>";
          }
        }
      });

      cookieTypes += "</ul>";

      let cookieNotice = current_settings.html;

      let audit_html = current_settings.cookie_audit_table;

      [IAB_REPLACE_TAGS];

      for (var k in current_settings.shortCodes) {
        if (
          typeof current_settings.shortCodes[k][currentLang] === "undefined"
        ) {
          cookieNotice = cookieNotice.replaceAll(
            "[" + k + "]",
            current_settings.shortCodes[k][CNZ_config.main_lang],
          );

          audit_html = audit_html.replaceAll(
            "[" + k + "]",
            current_settings.shortCodes[k][CNZ_config.main_lang],
          );
        } else {
          cookieNotice = cookieNotice.replaceAll(
            "[" + k + "]",
            current_settings.shortCodes[k][currentLang],
          );

          audit_html = audit_html.replaceAll(
            "[" + k + "]",
            current_settings.shortCodes[k][currentLang],
          );
        }
      }

      [IAB_REPLACE_COUNT];

      //show_cookie_on_banner

      cookieNotice = cookieNotice.replaceAll(
        "[banner_cookie_list]",
        cookieTypes,
      );

      cookieNotice = cookieNotice.replaceAll(
        "[conzent_close_button]",
        closeIcon,
      );

      cookieNotice = cookieNotice.replaceAll(
        "[conzent_revisit_icon]",
        revisitcookieIcon(),
      );

      cookieNotice = cookieNotice.replaceAll("[conzent_logo]", cookieIcon());

      cookieNotice = cookieNotice.replaceAll(
        "[banner_type]",
        current_settings.banner_type,
      );

      cookieNotice = cookieNotice.replaceAll(
        "[display_position]",
        current_settings.banner_position,
      );

      cookieNotice = cookieNotice.replaceAll(
        "[preference_type]",
        current_settings.preference_type,
      );

      cookieNotice = cookieNotice.replaceAll(
        "[preference_position]",
        current_settings.preference_position,
      );

      var overview_content = "";

      var txt_width = window.innerWidth < 376 ? 150 : 300;

      if (
        current_settings.default_laws == "gdpr" ||
        current_settings.default_laws == "gdpr_ccpa"
      ) {
        current_settings.show_more_button =
          current_settings.show_more_button.replaceAll(
            "[conzent_preference_center_show_more_button]",
            current_settings.shortCodes[
              "conzent_preference_center_show_more_button"
            ][currentLang],
          );

        current_settings.show_less_button =
          current_settings.show_less_button.replaceAll(
            "[conzent_preference_center_show_less_button]",
            current_settings.shortCodes[
              "conzent_preference_center_show_less_button"
            ][currentLang],
          );

        overview_content =
          current_settings.shortCodes["conzent_preference_center_overview"][
            currentLang
          ];
      }

      if (
        current_settings.default_laws == "ccpa" ||
        current_settings.default_laws == "gdpr_ccpa"
      ) {
        current_settings.show_more_button =
          current_settings.show_more_button.replaceAll(
            "[conzent_opt_out_center_show_more_button]",
            current_settings.shortCodes[
              "conzent_opt_out_center_show_more_button"
            ][currentLang],
          );

        current_settings.show_less_button =
          current_settings.show_less_button.replaceAll(
            "[conzent_opt_out_center_show_less_button]",
            current_settings.shortCodes[
              "conzent_opt_out_center_show_less_button"
            ][currentLang],
          );

        overview_content =
          current_settings.shortCodes["conzent_opt_out_center_overview"][
            currentLang
          ];
      }

      const cnzSaveConsent = function (b_action, law_type) {
        var log_vars = [];

        let cnzConsentExists = accessCookie("conzentConsent");

        let cnzPrefsValue = accessCookie("conzentConsentPrefs");

        var preferences_val = cnzPrefsValue ? JSON.parse(cnzPrefsValue) : null;

        var conzentConsentStatus = cnzConsentExists;

        if (conzentConsentStatus == true || b_action != "reject") {
          conzentConsentStatus = "yes";
        } else {
          conzentConsentStatus = "no";
        }

        CNZ_config.settings.cookieTypes.forEach((field) => {
          if (preferences_val && preferences_val.indexOf(field.value) !== -1) {
            log_vars.push(`${field.value}:yes`);
          } else {
            log_vars.push(`${field.value}:no`);
          }
        });

        [SAVE_TCF];

        log_vars.push(`conzentConsent:${conzentConsentStatus}`);

        var ndate = new Date();

        var consent_time =
          ndate.getFullYear() +
          "-" +
          (ndate.getMonth() + 1) +
          "-" +
          ndate.getDate() +
          " " +
          ndate.getHours() +
          ":" +
          ndate.getMinutes() +
          ":" +
          ndate.getSeconds();

        var params_form = new FormData();

        var conzent_id = accessCookie("conzent_id");
        if (!conzent_id || conzent_id === "false") {
          conzent_id = conzentRandomKey();
          Conzent_Cookie.set("conzent_id", conzent_id, CNZ_config.settings.expires, 1);
        }

        params_form.append("conzent_id", conzent_id);

        params_form.append("key", "[WEBSITE_KEY]");

        params_form.append("log", JSON.stringify(log_vars));

        params_form.append("consented_domain", window.location.host);

        params_form.append("cookie_list_version", "10");

        params_form.append("language", CNZ_config.currentLang);

        params_form.append("country", CNZ_config.currentTarget);

        params_form.append("consent_time", consent_time);

        [APPEND_FIELD_TCF];

        if (window._cnzVariantId) {
          params_form.append("variant_id", window._cnzVariantId);
        }

        navigator.sendBeacon("[API_PATH]/consent", params_form);

        // Re-observe cookies after consent to capture post-consent cookies (e.g. _ga, _fbp)
        setTimeout(function () {
          cnzPageViewLog("consent_cookies");
        }, 3000);

        setup_gcm();
        setup_meta_consent();
        setup_msconsent();
        setup_clarity_consent();
        setup_amazon_consent();
        syncConzentWithShoplift();
        cnzEvent("conzentck_consent_update", getCnzConsent());

        if (CNZ_config.settings.reload_on != 1) {
          const alliframe = _QEA("iframe");

          const allscripts = _QEA("script");

          blockItems(alliframe);

          blockItems(allscripts);

          // Show placeholders for early-blocked iframes
          if (window._cnzBlockedEls && window._cnzBlockedEls.length > 0) {
            window._cnzBlockedEls.forEach(function (el) {
              if (
                el.tagName.toLowerCase() === "iframe" &&
                el.getAttribute("data-cnz-blocked") === "pre-consent"
              ) {
                // Temporarily show iframe to measure CSS-applied dimensions
                el.style.display = "";
                el.style.visibility = "hidden";
                el.style.position = "absolute";
                var rect = el.getBoundingClientRect();
                var w =
                  rect.width > 0
                    ? rect.width + "px"
                    : el.getAttribute("data-cnz-width") || "100%";
                var h =
                  rect.height > 0
                    ? rect.height + "px"
                    : el.getAttribute("data-cnz-height") || "315";
                // Copy computed styles while visible
                var cs = window.getComputedStyle
                  ? window.getComputedStyle(el)
                  : null;
                var extraStyle = "";
                if (cs) {
                  if (cs.borderRadius && cs.borderRadius !== "0px")
                    extraStyle += "border-radius:" + cs.borderRadius + ";";
                  if (cs.marginTop && cs.marginTop !== "0px")
                    extraStyle += "margin-top:" + cs.marginTop + ";";
                  if (cs.marginBottom && cs.marginBottom !== "0px")
                    extraStyle += "margin-bottom:" + cs.marginBottom + ";";
                  // Preserve CSS width/height if set (e.g. iframe { width:100% })
                  if (cs.width && cs.width !== "0px" && cs.width !== "auto")
                    w = cs.width;
                  if (cs.height && cs.height !== "0px" && cs.height !== "auto")
                    h = cs.height;
                }
                el.style.display = "none";
                el.style.visibility = "";
                el.style.position = "";

                var pxTest = /px|%|vw|vh|em|rem/;
                w = pxTest.test(w) ? w : w + "px";
                h = pxTest.test(h) ? h : h + "px";

                var origSrc = el.getAttribute("data-cnz-src") || "";
                var VID_RE =
                  /(?:youtube(?:-nocookie)?\.com\/(?:[^\/\n\s]+\/\S+\/|(?:v|e(?:mbed)?)\/|\S*?[?&]v=)|youtu\.be\/)([a-zA-Z0-9_-]{11})/;
                var vMatch = origSrc.match(VID_RE);
                var bgStyle = "background-color:rgba(30,30,30,0.85);";
                if (vMatch) {
                  bgStyle =
                    "background-image:linear-gradient(rgba(30,30,30,0.75),rgba(30,30,30,0.75)),url(https://img.youtube.com/vi/" +
                    vMatch[1] +
                    "/maxresdefault.jpg);background-size:cover;background-position:center;";
                }

                var placeholder = document.createElement("div");
                placeholder.className = "cnz-iframe-placeholder";
                placeholder.setAttribute("data-cnz-for", origSrc);
                placeholder.style.cssText =
                  "width:" +
                  w +
                  ";height:" +
                  h +
                  ";display:flex;align-items:center;justify-content:center;position:relative;box-sizing:border-box;overflow:hidden;" +
                  bgStyle +
                  extraStyle;
                placeholder.innerHTML =
                  '<div class="cnz-inner-text" style="text-align:center;padding:16px;color:#fff;cursor:pointer;">' +
                  CNZ_config.placeholderText +
                  "</div>";

                // Click on placeholder opens the preference center
                placeholder.addEventListener("click", function () {
                  if (_QE(".conzent-modal")) {
                    _QE(".conzent-modal").classList.add("cnz-modal-open");
                  }
                  if (_QE(".conzent-overlay")) {
                    _QE(".conzent-overlay").classList.remove("cnz-hide");
                  }
                });

                el.parentNode.insertBefore(placeholder, el);
              }
            });
          }

          runCall();

          // Restore scripts/iframes blocked by the CMP loader's early observer
          Conzent_Blocker.runScripts();
        }
      };
      setTimeout(() => {
        _QE("body").insertAdjacentHTML("beforeend", cookieNotice);

        // Consent ID display — small shield icon in banner footer
        (function () {
          var cid = accessCookie("conzent_id") || "";
          if (!cid || cid.length < 8) return;
          var short = (
            cid.substring(0, 4) +
            "-" +
            cid.substring(4, 8)
          ).toUpperCase();
          var el = document.createElement("div");
          el.className = "cnz-consent-id";
          el.setAttribute(
            "style",
            "position:fixed;bottom:8px;left:8px;z-index:2147483646;opacity:0.6;cursor:pointer;font-size:10px;font-family:monospace;color:#888;background:rgba(255,255,255,0.9);padding:2px 6px;border-radius:3px;border:1px solid #ddd;transition:opacity 0.2s;",
          );
          el.textContent = "\uD83D\uDEE1 " + short;
          el.title = "Your consent reference ID";
          el.addEventListener("mouseenter", function () {
            el.style.opacity = "1";
          });
          el.addEventListener("mouseleave", function () {
            el.style.opacity = "0.6";
          });
          el.addEventListener("click", function () {
            var tip = document.createElement("div");
            tip.setAttribute(
              "style",
              "position:fixed;bottom:32px;left:8px;z-index:2147483647;background:#fff;border:1px solid #ccc;border-radius:6px;padding:12px 16px;font-family:sans-serif;font-size:12px;color:#333;box-shadow:0 2px 8px rgba(0,0,0,0.15);max-width:280px;",
            );
            tip.innerHTML =
              '<div style="font-weight:600;margin-bottom:4px;">Consent ID: <span style="font-family:monospace;user-select:all;">' +
              short +
              '</span></div><div style="color:#666;font-size:11px;line-height:1.4;">This is your consent reference. You can use it to verify or withdraw your consent choice. <button style="margin-top:6px;padding:3px 10px;font-size:11px;cursor:pointer;border:1px solid #ccc;border-radius:3px;background:#f5f5f5;">Copy</button></div>';
            tip.querySelector("button").addEventListener("click", function () {
              if (navigator.clipboard) {
                navigator.clipboard.writeText(short);
                this.textContent = "Copied!";
              }
            });
            document.body.appendChild(tip);
            setTimeout(function () {
              if (tip.parentNode) tip.parentNode.removeChild(tip);
            }, 8000);
            tip.addEventListener("click", function (e) {
              e.stopPropagation();
            });
            document.addEventListener(
              "click",
              function rm() {
                if (tip.parentNode) tip.parentNode.removeChild(tip);
                document.removeEventListener("click", rm);
              },
              { once: true },
            );
          });
          document.body.appendChild(el);
        })();

        // Create placeholders for iframes blocked by the early CMP loader
        if (window._cnzBlockedEls && window._cnzBlockedEls.length > 0) {
          window._cnzBlockedEls.forEach(function (el) {
            if (el.tagName.toLowerCase() !== "iframe") return;
            if (
              (el.parentNode && !el.previousElementSibling) ||
              !el.previousElementSibling.classList.contains(
                "cnz-iframe-placeholder",
              )
            ) {
              var w =
                el.getAttribute("data-cnz-width") ||
                el.getAttribute("width") ||
                "100%";
              var h =
                el.getAttribute("data-cnz-height") ||
                el.getAttribute("height") ||
                "300px";
              var pxTest = /px|%|vw|vh|em|rem/;
              w = pxTest.test(w) ? w : w + "px";
              h = pxTest.test(h) ? h : h + "px";
              var origSrc = el.getAttribute("data-cnz-src") || "";
              var VID_RE =
                /(?:youtube(?:-nocookie)?\.com\/(?:[^\/\n\s]+\/\S+\/|(?:v|e(?:mbed)?)\/|\S*?[?&]v=)|youtu\.be\/)([a-zA-Z0-9_-]{11})/;
              var vMatch = origSrc.match(VID_RE);
              var bgStyle = "background-color:rgba(30,30,30,0.85);";
              if (vMatch) {
                bgStyle =
                  "background-image:linear-gradient(rgba(30,30,30,0.75),rgba(30,30,30,0.75)),url(https://img.youtube.com/vi/" +
                  vMatch[1] +
                  "/maxresdefault.jpg);background-size:cover;background-position:center;";
              }
              var placeholder = document.createElement("div");
              placeholder.className = "cnz-iframe-placeholder";
              placeholder.setAttribute("data-cnz-for", origSrc);
              placeholder.style.cssText =
                "width:" +
                w +
                ";height:" +
                h +
                ";display:flex;align-items:center;justify-content:center;position:relative;box-sizing:border-box;overflow:hidden;border-radius:6px;" +
                bgStyle;
              placeholder.innerHTML =
                '<div class="cnz-inner-text" style="text-align:center;padding:16px;color:#fff;cursor:pointer;">' +
                (CNZ_config.placeholderText ||
                  "This content requires cookies. Click to manage your preferences.") +
                "</div>";
              placeholder.addEventListener("click", function () {
                if (_QE(".conzent-modal")) {
                  _QE(".conzent-modal").classList.add("cnz-modal-open");
                }
                if (_QE(".conzent-overlay")) {
                  _QE(".conzent-overlay").classList.remove("cnz-hide");
                }
              });
              if (el.parentNode) {
                el.parentNode.insertBefore(placeholder, el);
              }
            }
          });
        }

        //var current_domain = window.location.hostname.replace(/^www\./, "");
        var current_domain = window.location.hostname;

        if (current_domain != "[SITE_DOMAIN]") {
          if (current_domain.replace(/^www\./, "") != "[SITE_DOMAIN]") {
            if (current_settings.policy_list[current_domain] != "") {
              if (_QE(".cnz-privacy-policy") != null) {
                _QE(".cnz-privacy-policy").setAttribute(
                  "href",
                  current_settings.policy_list[current_domain],
                );
              }
            }
          }
        }

        [IAB_LOADELEMENT];

        if (current_settings.show_more_button != "") {
          var s = window.innerWidth < 376 ? 150 : 300,
            a = _QE(".overview_text");

          if (!(a.textContent.length < s)) {
            var u = a.innerHTML,
              l = new DOMParser()
                .parseFromString(u, "text/html")
                .querySelectorAll("body > p");

            if (!(l.length <= 1)) {
              for (var p = "", d = 0; d < l.length; d++) {
                if (d === l.length - 1) break;

                var v = l[d];

                if (
                  ("".concat(p).concat(v.outerHTML).length > s &&
                    v.insertAdjacentHTML(
                      "beforeend",
                      "...&nbsp;".concat(current_settings.show_more_button),
                    ),
                  (p = "".concat(p).concat(v.outerHTML)).length > s)
                )
                  break;
              }

              cnzShowMore();
            }
          }
        }

        _QEA(".cnz-tab li").forEach((ele) => {
          ele.addEventListener("click", function () {
            _QEA(".cnz-tabcontent").forEach((eleTab) => {
              eleTab.classList.add("hide-tab");
            });

            var selEle = this.getAttribute("id");

            _QEA(".cnz-tab li").forEach((eleLi) => {
              if (selEle == eleLi.getAttribute("id")) {
                eleLi.classList.add("tab-active");
              } else {
                eleLi.classList.remove("tab-active");
              }
            });

            if (_QE("#" + selEle + "Section")) {
              _QE("#" + selEle + "Section").classList.remove("hide-tab");
            }
          });
        });

        if (
          parseInt(current_settings.category_on_first_layer) == 1 &&
          current_settings.preference_type == "push_down"
        ) {
          if (_QE(".notice_button_wrap #cookieAccept")) {
            _QE(".notice_button_wrap #cookieAccept").remove();
          }

          if (_QE(".notice_button_wrap #cookieReject")) {
            _QE(".notice_button_wrap #cookieReject").remove();
          }

          if (_QE(".conzent-prefrence-btn-wrapper #cookieSavePreferences")) {
            _QE(
              ".conzent-prefrence-btn-wrapper #cookieSavePreferences",
            ).remove();
          }

          if (_QE(".notice_button_wrap")) {
            _QE(".notice_button_wrap").style.display = "flex";
          }
        } else {
          if (_QE(".notice_button_wrap")) {
            _QE(".notice_button_wrap").remove();
          }
        }

        if (current_settings.preference_type == "push_down") {
          var box_style = _QE(
            ".conzent-modal .conzent-preference-center",
          ).getAttribute("style");

          var newItem = document.createElement("div");

          newItem.className = "conzent-preference-center-wrapper";

          newItem.appendChild(
            _QE(".conzent-preference-center .conzent-preference-header"),
          );

          newItem.appendChild(
            _QE(".conzent-preference-center .conzent-preference-body-wrapper"),
          );

          newItem.innerHTML =
            "<div class='conzent-preference-center'><div class='conzent-preference'>" +
            newItem.innerHTML +
            "</div></div>";

          _QE("#Conzent").appendChild(newItem);

          _QE(".conzent-preference-center").appendChild(
            _QE(".conzent-footer-wrapper"),
          );

          _QE("#preCloseBtn").remove();

          _QE(".conzent-modal").remove();

          _QE("#Conzent").classList.add("push_down");

          _QE(".conzent-preference-center").setAttribute("style", box_style);
        }

        current_settings.cookieTypes.forEach((field) => {
          if (_QE("#cnzCategory" + field.value + " .conzent-cookie-table")) {
            if (current_settings.cookiesList.hasOwnProperty(field.value)) {
              var cookieTbl = "";

              current_settings.cookiesList[field.value].forEach((item) => {
                cookieTbl +=
                  '<ul class="cookie-items"><li><div class="item-name">' +
                  current_settings.shortCodes["conzent_cookie_list_cookie"][
                    currentLang
                  ] +
                  '</div><div class="item-value">' +
                  item.name +
                  '</div></li><li><div class="item-name">' +
                  current_settings.shortCodes[
                    "conzent_cookie_list_description"
                  ][currentLang] +
                  '</div><div class="item-value">' +
                  item.description[currentLang] +
                  '</div></li><li><div class="item-name">' +
                  current_settings.shortCodes["conzent_cookie_list_duration"][
                    currentLang
                  ] +
                  '</div><div class="item-value">' +
                  item.duration[currentLang] +
                  "</div></li></ul>";
              });

              _QE(
                "#cnzCategory" + field.value + " .conzent-cookie-table",
              ).innerHTML = cookieTbl;
            } else {
              _QE(
                "#cnzCategory" + field.value + " .conzent-cookie-table",
              ).innerHTML =
                "<p class='no-cookie-display'>" +
                current_settings.shortCodes[
                  "conzent_cookie_list_no_cookie_to_display_text"
                ][currentLang] +
                "</p>";
            }
          }
        });

        if (current_settings.show_cookie_on_banner !== 1) {
          if (_QE(".cnz-category-table .conzent-accordion-arrow")) {
            _QEA(".cnz-category-table .conzent-accordion-arrow").forEach(
              (ele) => {
                ele.remove();
              },
            );
          }

          if (_QEA(".cnz-category-table .conzent-cookie-table")) {
            _QEA(".cnz-category-table .conzent-cookie-table").forEach((ele) => {
              ele.remove();
            });
          }
        }

        if (_QE(".cnz-cookie-table")) {
          _QE(".cnz-cookie-table").innerHTML = audit_html;

          current_settings.cookieTypes.forEach((field) => {
            if (
              _QE(
                ".cnz-cookie-table #cnzCategory" +
                  field.value +
                  " .conzent-cookie-table",
              )
            ) {
              if (current_settings.cookiesList.hasOwnProperty(field.value)) {
                var cookieTbl = "";

                current_settings.cookiesList[field.value].forEach((item) => {
                  cookieTbl +=
                    '<ul class="cookie-items"><li><div class="item-name">' +
                    current_settings.shortCodes["conzent_cookie_list_cookie"][
                      currentLang
                    ] +
                    '</div><div class="item-value">' +
                    item.name +
                    '</div></li><li><div class="item-name">' +
                    current_settings.shortCodes[
                      "conzent_cookie_list_description"
                    ][currentLang] +
                    '</div><div class="item-value">' +
                    item.description[currentLang] +
                    '</div></li><li><div class="item-name">' +
                    current_settings.shortCodes["conzent_cookie_list_duration"][
                      currentLang
                    ] +
                    '</div><div class="item-value">' +
                    item.duration[currentLang] +
                    "</div></li></ul>";
                });

                _QE(
                  ".cnz-cookie-table #cnzCategory" +
                    field.value +
                    " .conzent-cookie-table",
                ).innerHTML = cookieTbl;
              } else {
                _QE(
                  ".cnz-cookie-table #cnzCategory" +
                    field.value +
                    " .conzent-cookie-table",
                ).innerHTML =
                  "<p class='no-cookie-display'>" +
                  current_settings.shortCodes[
                    "conzent_cookie_list_no_cookie_to_display_text"
                  ][currentLang] +
                  "</p>";
              }
            }
          });
        }

        // ── Policy content injection ──
        if (_QE(".cnz-cookie-policy")) {
          _QE(".cnz-cookie-policy").innerHTML = current_settings.cookie_policy_html || '';
        }
        if (_QE(".cnz-audit-privacy-policy")) {
          _QE(".cnz-audit-privacy-policy").innerHTML = current_settings.privacy_policy_html || '';
        }

        //_QE("#Conzent").style.opacity =0;

        //fadeInEffect();

        _QEA(".btn-cookieAccept").forEach((ele) => {
          ele.addEventListener("click", function () {
            hideCookieBanner(true, current_settings.expires);

            if (_QE(".conzent-modal")) {
              if (
                _QE(".conzent-modal").classList.contains("cnz-modal-open") ===
                true
              ) {
                _QE(".conzent-modal").classList.remove("cnz-modal-open");
              }
            } else {
              if (current_settings.preference_type == "push_down") {
                if (
                  _QE(
                    ".conzent-preference-center-wrapper .conzent-preference-center",
                  )
                ) {
                  var elm_box = _QE(
                    ".conzent-preference-center-wrapper .conzent-preference-center",
                  );

                  elm_box.style.display = "none";
                }

                _QE("#Conzent").classList.remove("expand-box");
              }
            }

            conzentCloseBox();

            let prefs = [];

            if (_QE('input[name="gdprPrefItem"]')) {
              _QEA('input[name="gdprPrefItem"]').forEach((field) => {
                field.checked = true;
              });

              _QEA('input[name="gdprPrefItem"]:checked').forEach((field) => {
                if (!prefs.includes(field.value)) {
                  prefs.push(field.value);
                }

                if (
                  !current_settings.allowed_categories.includes(field.value)
                ) {
                  current_settings.allowed_categories.push(field.value);
                }
              });
            } else {
              current_settings.cookieTypes.forEach((field) => {
                if (!prefs.includes(field.value)) {
                  prefs.push(field.value);
                }

                if (
                  !current_settings.allowed_categories.includes(field.value)
                ) {
                  current_settings.allowed_categories.push(field.value);
                }
              });
            }

            Conzent_Cookie.set(
              "conzentConsentPrefs",
              encodeURIComponent(JSON.stringify(prefs)),
              CNZ_config.settings.expires,
              1,
            );

            /*createCookie("conzentConsentPrefs", encodeURIComponent(JSON.stringify(prefs)), {

								expires: daysToUTC(CNZ_config.settings.expires),

								path: "/",

								domain: CNZ_config.user_site.domain,

								SameSite: "Strict",

								//secure : ""

								});*/

            cnzSaveConsent("all", CNZ_config.settings.default_laws);

            //Conzent_Cookie.clearallCookies();

            consentFun.onConsentAccept.call(this);

            if (current_settings.reload_on == 1) {
              window.location.reload();
            }
          });
        });

        if (_QE("#cookieOptSavePreferences")) {
          _QE("#cookieOptSavePreferences").addEventListener(
            "click",
            function () {
              hideCookieBanner(true, current_settings.expires);

              if (
                _QE(".conzent-modal").classList.contains("cnz-modal-open") ===
                true
              ) {
                _QE(".conzent-modal").classList.remove("cnz-modal-open");
              }

              conzentCloseBox();

              let prefs = [];

              if (_QE("#donotsell_checkbox").checked == true) {
                current_settings.cookieTypes.forEach((field) => {
                  if (!prefs.includes(field.value)) {
                    prefs.push(field.value);
                  }

                  if (
                    !current_settings.allowed_categories.includes(field.value)
                  ) {
                    current_settings.allowed_categories.push(field.value);
                  }
                });
              }

              Conzent_Cookie.set(
                "conzentConsentPrefs",
                encodeURIComponent(JSON.stringify(prefs)),
                CNZ_config.settings.expires,
                1,
              );

              /*createCookie("conzentConsentPrefs", encodeURIComponent(JSON.stringify(prefs)), {

								expires: daysToUTC(CNZ_config.settings.expires),

								path: "/",

								domain: CNZ_config.user_site.domain,

								SameSite: "Strict",

								//secure : ""

								});*/

              cnzSaveConsent("custom", CNZ_config.settings.default_laws);

              consentFun.onConsentSaved.call(this);

              Conzent_Cookie.blockMissedCookie();

              if (current_settings.reload_on == 1) {
                window.location.reload();
              }
            },
          );
        }

        if (_QE("#cookieSavePreferences")) {
          _QE("#cookieSavePreferences").addEventListener("click", function () {
            if (_QE(".conzent-modal")) {
              hideCookieBanner(true, current_settings.expires);

              if (
                _QE(".conzent-modal").classList.contains("cnz-modal-open") ===
                true
              ) {
                _QE(".conzent-modal").classList.remove("cnz-modal-open");
              }

              conzentCloseBox();
            } else {
              if (current_settings.preference_type == "push_down") {
                if (
                  _QE(
                    ".conzent-preference-center-wrapper .conzent-preference-center",
                  )
                ) {
                  var elm_box = _QE(
                    ".conzent-preference-center-wrapper .conzent-preference-center",
                  );

                  elm_box.style.display = "none";
                }

                _QE("#Conzent").classList.remove("expand-box");
              } else {
                hideCookieBanner(true, current_settings.expires);

                conzentCloseBox();
              }
            }

            let prefs = [];

            if (_QE('input[name="gdprPrefItem"]')) {
              _QEA('input[name="gdprPrefItem"]:checked').forEach((field) => {
                if (!prefs.includes(field.value)) {
                  prefs.push(field.value);
                }

                if (
                  !current_settings.allowed_categories.includes(field.value)
                ) {
                  current_settings.allowed_categories.push(field.value);
                }
              });
            } else {
              current_settings.cookieTypes.forEach((field) => {
                if (!prefs.includes(field.value)) {
                  prefs.push(field.value);
                }

                if (
                  !current_settings.allowed_categories.includes(field.value)
                ) {
                  current_settings.allowed_categories.push(field.value);
                }
              });
            }

            Conzent_Cookie.set(
              "conzentConsentPrefs",
              encodeURIComponent(JSON.stringify(prefs)),
              CNZ_config.settings.expires,
              1,
            );

            /*createCookie("conzentConsentPrefs", encodeURIComponent(JSON.stringify(prefs)), {

								expires: daysToUTC(CNZ_config.settings.expires),

								path: "/",

								domain: CNZ_config.user_site.domain,

								SameSite: "Strict",

								//secure : ""

								});*/

            cnzSaveConsent("custom", CNZ_config.settings.default_laws);

            //Conzent_Cookie.clearallCookies();

            consentFun.onConsentSaved.call(this);

            if (current_settings.reload_on == 1) {
              window.location.reload();
            }
          });
        }

        if (_QE("#donotselllink")) {
          _QE("#donotselllink").addEventListener("click", function (event) {
            _QE("#Conzent").classList.add("cnz-hide");

            _QE(".conzent-modal").classList.add("cnz-modal-open");
          });
        }

        if (_QE(".cnz_banner_cookie_list")) {
          _QEA(".cnz_banner_cookie_list .sliding-switch").forEach((ele) => {
            ele.addEventListener("click", function (event) {
              var selEle = this.getAttribute("id");

              if (_QE("#gdpr" + selEle)) {
                _QE("#gdpr" + selEle).checked = this.checked;
              }
            });
          });
        }

        if (_QE(".cnz-category-table .conzent-accordion-item")) {
          _QEA(
            ".cnz-category-table .conzent-accordion-item .sliding-switch",
          ).forEach((ele) => {
            ele.addEventListener("click", function (event) {
              var selEle = this.getAttribute("id").replace("gdpr", "");

              if (_QE("#" + selEle)) {
                _QE("#" + selEle).checked = this.checked;
              }
            });
          });
        }

        if (_QE("#cookieSettings")) {
          _QE("#cookieSettings").addEventListener("click", function (event) {
            if (current_settings.preference_type == "push_down") {
              _QE("#Conzent").classList.add("expand-box");

              if (
                _QE(
                  ".conzent-preference-center-wrapper .conzent-preference-center",
                )
              ) {
                var elm_box = _QE(
                  ".conzent-preference-center-wrapper .conzent-preference-center",
                );

                if (elm_box.style.display == "block") {
                  elm_box.style.display = "none";
                } else {
                  elm_box.style.display = "block";
                }
              }
            } else {
              _QE("#Conzent").classList.add("cnz-hide");

              _QE(".conzent-modal").classList.add("cnz-modal-open");
            }

            if (event === "open") {
              const classbtns = _QEA(
                'input[name="gdprPrefItem"]:not(:disabled)',
              );

              classbtns.forEach(function (btn) {
                if (btn.getAttribute("data-compulsory") == "off") {
                  //btn.checked = false;
                }
              });

              //_QE('input[name="gdprPrefItem"]:not(:disabled)').getAttribute("data-compulsory", "off").checked = false;
            } else {
              const classbtns = _QEA(
                'input[name="gdprPrefItem"]:not(:disabled)',
              );

              classbtns.forEach(function (btn) {
                if (btn.getAttribute("data-compulsory") == "off") {
                  //btn.checked = current_settings.allCheckboxesChecked;
                }
              });

              //_QE('input[name="gdprPrefItem"]:not(:disabled)').getAttribute("data-compulsory", "off").checked = current_settings.allCheckboxesChecked;
            }
          });
        }

        if (_QE("#closeIcon")) {
          _QE("#closeIcon").addEventListener("click", function (event) {
            if (current_settings.preference_type == "push_down") {
              _QE("#Conzent").classList.add("expand-box");

              if (
                _QE(
                  ".conzent-preference-center-wrapper .conzent-preference-center",
                )
              ) {
                _QE(
                  ".conzent-preference-center-wrapper .conzent-preference-center",
                ).style.display = "none";
              }
            }

            conzentCloseBox();
          });
        }

        if (_QE("#revisitBtn")) {
          _QE("#revisitBtn").addEventListener("click", function (event) {
            var b_action = !cookieExists("conzentConsent");

            [UPDATE_TCF];

            conzentOpenBox();
          });
        }

        // Handle ".conzent-revisit" links in policy pages (manage preferences)
        // Uses event delegation since these links may be injected after init
        document.addEventListener("click", function (event) {
          var target = event.target.closest(".conzent-revisit");
          if (target) {
            event.preventDefault();
            revisitCnzConsent();
          }
        });

        if (_QE("#preCloseBtn")) {
          _QE("#preCloseBtn").addEventListener("click", function (event) {
            conzentOpenBox();

            _QE(".conzent-modal").classList.remove("cnz-modal-open");
          });
        }

        if (_QE(".conzent-accordion-btn")) {
          _QEA(".conzent-accordion-btn").forEach((ele) => {
            ele.addEventListener("click", function (event) {
              var selCat = this.getAttribute("data-cookie-category");

              if (selCat) {
                var cookieTable = _QE("#cnzCategory" + selCat);

                cookieTable.classList.toggle("cnz-active");
              } else {
                var selCat = this.getAttribute("data-tab");

                if (selCat) {
                  var cookieTable = _QE("#" + selCat);

                  cookieTable.classList.toggle("cnz-active");
                }
              }
            });
          });
        }

        if (_QE("#cookieReject")) {
          _QEA(".btn-cookieReject").forEach((ele) => {
            ele.addEventListener("click", function (event) {
              if (_QE(".conzent-modal")) {
                if (
                  _QE(".conzent-modal").classList.contains("cnz-modal-open") ===
                  true
                ) {
                  _QE(".conzent-modal").classList.remove("cnz-modal-open");
                }
              }

              if (
                _QE(
                  ".conzent-preference-center-wrapper .conzent-preference-center",
                )
              ) {
                _QE(
                  ".conzent-preference-center-wrapper .conzent-preference-center",
                ).style.display = "none";
              }

              hideCookieBanner(false, current_settings.expires);

              conzentCloseBox();

              if (_QE('input[name="gdprPrefItem"]')) {
                _QEA('input[name="gdprPrefItem"]:checked').forEach((field) => {
                  if (field.value != "necessary") {
                    field.checked = false;
                  }
                });
              }

              current_settings.allowed_categories = ["necessary"];

              let prefs_new = ["necessary"];

              Conzent_Cookie.set(
                "conzentConsentPrefs",
                encodeURIComponent(JSON.stringify(prefs_new)),
                CNZ_config.settings.expires,
                1,
              );

              //current_settings.allowed_categories.push('necessary');

              // Delete prefs cookie from brower on reject

              //Conzent_Cookie.erase("conzentConsentPrefs");

              /*createCookie("conzentConsentPrefs", "", {

								expires: daysToUTC(-365),

								path: "/"

							});*/

              cnzSaveConsent("reject", CNZ_config.settings.default_laws);

              // Always set GCM so security_storage is granted even on reject
              setup_gcm();
              setup_meta_consent();
              setup_clarity_consent();
              setup_amazon_consent();

              consentFun.onConsentReject.call(this);

              Conzent_Cookie.clearallCookies();

              //Conzent_Cookie.blockMissedCookie();

              if (current_settings.reload_on == 1) {
                window.location.reload(true);
              }
            });
          });
        }

        if (preferences) {
          preferences.forEach((field) => {
            if (field != "necessary") {
              if (!current_settings.allowed_categories.includes(field)) {
                current_settings.allowed_categories.push(field);
              }

              if (_QE("input#gdprPrefItem" + field)) {
                _QE("input#gdprPrefItem" + field).checked = true;
              }

              if (_QE("input#PrefItem" + field)) {
                _QE("input#PrefItem" + field).checked = true;
              }
            }
          });

          setup_gcm();
          setup_meta_consent();
          setup_clarity_consent();
          setup_amazon_consent();
        }

        Conzent_Blocker.runScripts();

        if (_QE(".cnz-inner-text")) {
          _QE(".cnz-inner-text").addEventListener("click", function (event) {
            if (current_settings.preference_type == "push_down") {
              _QE("#Conzent").classList.remove("expand-box");

              if (
                _QE(
                  ".conzent-preference-center-wrapper .conzent-preference-center",
                )
              ) {
                _QE(
                  ".conzent-preference-center-wrapper .conzent-preference-center",
                ).style.display = "none";
              }
            }

            conzentOpenBox();
          });
        }

        if (conzentConsentExists == true || event == "open") {
          if (conzentConsentExists == true) {
            if (_QE("#Conzent")) {
              _QE("#Conzent").classList.add("cnz-hide");
            }

            if (_QE(".cnz-btn-revisit-wrapper")) {
              _QE(".cnz-btn-revisit-wrapper").classList.remove(
                "cnz-revisit-hide",
              );
            }
          } else {
            conzentOpenBox();
          }
        }

        // If already consent is accepted, inject preferences
        else {
          if (preferences) {
            preferences.forEach((field) => {
              if (field != "necessary") {
                if (!current_settings.allowed_categories.includes(field)) {
                  current_settings.allowed_categories.push(field);
                }
              }
            });
          }

          if (preferences) {
            if (_QE("#Conzent")) {
              _QE("#Conzent").classList.add("cnz-hide");
            }

            if (_QE(".cnz-btn-revisit-wrapper")) {
              _QE(".cnz-btn-revisit-wrapper").classList.remove(
                "cnz-revisit-hide",
              );
            }
          }

          //setup_gcm();

          injectScripts();

          //conzentCloseBox();
        }

        cnzPageViewLog("banner_view");
        _QEA("a[href='javascript:void(0);']").forEach((ele) => {
          ele.removeAttribute("target");
          ele.addEventListener("click", function (ev) {
            ev.preventDefault();
          });
        });
      }, current_settings.delay);

      const cnzAuditTable = function () {
        var new_setting = CNZ_config.settings;
        var new_lang = _getLang();
        if (_QE(".cnz-cookie-table")) {
          if (_QE(".cnz-cookie-table").innerHTML.trim() === "" || _QE(".cnz-cookie-table").innerHTML.trim() === "&nbsp;") {
            if (_QE(".cnz-category-table")) {
              _QE(".cnz-cookie-table").innerHTML = _QE(
                ".cnz-category-table",
              ).innerHTML;

              if (_QE(".conzent-accordion-btn")) {
                _QEA(".conzent-accordion-btn").forEach((ele) => {
                  ele.addEventListener("click", function (event) {
                    var selCat = this.getAttribute("data-cookie-category");

                    if (selCat) {
                      var cookieTable = _QE("#cnzCategory" + selCat);

                      cookieTable.classList.toggle("cnz-active");
                    } else {
                      var selCat = this.getAttribute("data-tab");

                      if (selCat) {
                        var cookieTable = _QE("#" + selCat);

                        cookieTable.classList.toggle("cnz-active");
                      }
                    }
                  });
                });
              }
            }
            setTimeout(function () {
              cnzAuditTable();
            }, 1000);
          }
        }
      };
      const targetNode = document.documentElement;
      const observer_new = new MutationObserver(function (
        mutationsList,
        observer,
      ) {
        for (const mutation of mutationsList) {
          if (mutation.type === "childList") {
            cnzAuditTable();
          }
        }
      });
      const config_new = {
        attributes: true,
        childList: true,
        subtree: true,
        characterData: true,
      };
      observer_new.observe(targetNode, config_new);
      cnzAuditTable();

      // ── Policy content injection (deferred) ──
      if (_QE(".cnz-cookie-policy") && _QE(".cnz-cookie-policy").innerHTML === "") {
        _QE(".cnz-cookie-policy").innerHTML = CNZ_config.settings.cookie_policy_html || '';
      }
      if (_QE(".cnz-audit-privacy-policy") && _QE(".cnz-audit-privacy-policy").innerHTML === "") {
        _QE(".cnz-audit-privacy-policy").innerHTML = CNZ_config.settings.privacy_policy_html || '';
      }
    };

    const Conzent = {
      init: () => {
        ConzentFN("");

        //Conzent_Blocker.runScripts();
      },

      /**

					 * Reopens the cookie notice banner

					 */

      reinit: () => {
        ConzentFN("open");
      },

      /**

					 * Returns true if consent is given else false

					 */

      isAccepted: () => {
        let consent = accessCookie("conzentConsent");

        return consent ? JSON.parse(consent) : false;
      },

      /**

					 * Returns the value of the conzentConsentPrefs cookie

					 */

      getPreferences: () => {
        let preferences = accessCookie("conzentConsentPrefs");

        return preferences ? JSON.parse(preferences) : null;
      },

      /**

					 * Check if a particular preference is accepted

					 * @param {string} cookieName

					 */

      isPreferenceAccepted: (cookieTypeValue) => {
        let consent = accessCookie("conzentConsent");

        let preferences = accessCookie("conzentConsentPrefs");

        preferences = preferences ? JSON.parse(preferences) : null;

        if (!consent) {
          return false;
        }

        if (
          preferences === false ||
          preferences.indexOf(cookieTypeValue) === -1
        ) {
          return false;
        }

        return true;
      },
    };

    const changeRootVariables = () => {
      _QE(":root").style["--ConzentLight"] =
        CNZ_config.settings.themeSettings.lightColor;

      _QE(":root").style["--ConzentDark"] =
        CNZ_config.settings.themeSettings.darkColor;
    };

    // IMPORTANT: If you are not showing cookie preferences selection menu,

    // then you can remove the below function

    const injectScripts = () => {
      // Example: Google Analytics

      if (Conzent.isPreferenceAccepted("analytics") === true) {
        // console.log("Analytics Scripts Running....");
      }

      // Example: Google Adwords cookie, DoubleClick, Remarketing pixels, Social Media cookies

      if (Conzent.isPreferenceAccepted("marketing") === true) {
        // console.log("Marketing Scripts Running....");
      }

      // Example: Remember password, language, etc

      if (Conzent.isPreferenceAccepted("preferences") === true) {
        //console.log("Preferences Scripts Running....");
      }

      if (Conzent.isPreferenceAccepted("unclassified") === true) {
        //console.log("Unclassified Scripts Running....");
      }

      setup_gcm();
      setup_meta_consent();
      setup_clarity_consent();
      setup_amazon_consent();

      Conzent_Blocker.runScripts();
    };

    const settingsIcon =
      '<?xml version="1.0" ?><svg height="16px" version="1.1" viewBox="0 0 20 20" width="16px" xmlns="http://www.w3.org/2000/svg" xmlns:sketch="" xmlns:xlink="http://www.w3.org/1999/xlink"><title/><desc/><defs/><g fill="none" fill-rule="evenodd" id="Page-1" stroke="none" stroke-width="1"><g fill="#bfb9b9" id="Core" transform="translate(-464.000000, -380.000000)"><g id="settings" transform="translate(464.000000, 380.000000)"><path d="M17.4,11 C17.4,10.7 17.5,10.4 17.5,10 C17.5,9.6 17.5,9.3 17.4,9 L19.5,7.3 C19.7,7.1 19.7,6.9 19.6,6.7 L17.6,3.2 C17.5,3.1 17.3,3 17,3.1 L14.5,4.1 C14,3.7 13.4,3.4 12.8,3.1 L12.4,0.5 C12.5,0.2 12.2,0 12,0 L8,0 C7.8,0 7.5,0.2 7.5,0.4 L7.1,3.1 C6.5,3.3 6,3.7 5.4,4.1 L3,3.1 C2.7,3 2.5,3.1 2.3,3.3 L0.3,6.8 C0.2,6.9 0.3,7.2 0.5,7.4 L2.6,9 C2.6,9.3 2.5,9.6 2.5,10 C2.5,10.4 2.5,10.7 2.6,11 L0.5,12.7 C0.3,12.9 0.3,13.1 0.4,13.3 L2.4,16.8 C2.5,16.9 2.7,17 3,16.9 L5.5,15.9 C6,16.3 6.6,16.6 7.2,16.9 L7.6,19.5 C7.6,19.7 7.8,19.9 8.1,19.9 L12.1,19.9 C12.3,19.9 12.6,19.7 12.6,19.5 L13,16.9 C13.6,16.6 14.2,16.3 14.7,15.9 L17.2,16.9 C17.4,17 17.7,16.9 17.8,16.7 L19.8,13.2 C19.9,13 19.9,12.7 19.7,12.6 L17.4,11 L17.4,11 Z M10,13.5 C8.1,13.5 6.5,11.9 6.5,10 C6.5,8.1 8.1,6.5 10,6.5 C11.9,6.5 13.5,8.1 13.5,10 C13.5,11.9 11.9,13.5 10,13.5 L10,13.5 Z" id="Shape"/></g></g></g></svg>';

    //const defaultCookieIcon = "<img id='cookieIcon' src='"+CNZ_config.settings.default_logo+"' width='40'>";

    const closeIcon =
      '<?xml version="1.0" ?><svg viewBox="0 0 96 96" xmlns="http://www.w3.org/2000/svg"><title/><g fill="#bfb9b9"><path d="M48,0A48,48,0,1,0,96,48,48.0512,48.0512,0,0,0,48,0Zm0,84A36,36,0,1,1,84,48,36.0393,36.0393,0,0,1,48,84Z"/><path d="M64.2422,31.7578a5.9979,5.9979,0,0,0-8.4844,0L48,39.5156l-7.7578-7.7578a5.9994,5.9994,0,0,0-8.4844,8.4844L39.5156,48l-7.7578,7.7578a5.9994,5.9994,0,1,0,8.4844,8.4844L48,56.4844l7.7578,7.7578a5.9994,5.9994,0,0,0,8.4844-8.4844L56.4844,48l7.7578-7.7578A5.9979,5.9979,0,0,0,64.2422,31.7578Z"/></g></svg>';

    const Conzent_Blocker = {
      blockingStatus: true,

      scriptsLoaded: false,

      set: function (args) {
        if (typeof JSON.parse !== "function") {
          showConsoleLog(
            "ConzentCookieConsent requires JSON.parse but your browser doesn't support it",
          );

          return;
        }

        this.cookies = JSON.parse(JSON.stringify(args.cookies));
      },

      retriveCookies: function () {
        const allscript = _QEA("script");

        let consentExists = 1;

        if (Conzent_Cookie.read("conzentConsent") == "true") {
          consentExists = 2;
        }

        allscript.forEach(function (script_tag) {
          if (script_tag.getAttribute("type") == "") {
            script_tag.setAttribute("type", "text/javascript");
          }

          if (script_tag.getAttribute("src") !== "") {
            //script_tag.setAttribute('data-blocked',"yes");

            //script_tag.setAttribute('type',"text/plain");

            //

            if (!script_tag.hasOwnProperty("data-consent")) {
              //console.log(script_tag.getAttribute('data-consent'));

              if (script_tag.getAttribute("data-consent") == "") {
                script_tag.setAttribute("data-consent", "unclassified");
              }
            }

            if (script_tag.getAttribute("src") != null) {
              for (
                var ij = 0;
                ij < CNZ_config.settings.allowed_scripts.length;
                ij++
              ) {
                var newsrc = script_tag.getAttribute("src");

                if (
                  newsrc.indexOf(CNZ_config.settings.allowed_scripts[ij]) !== -1
                ) {
                  script_tag.setAttribute("data-consent", "necessary");

                  script_tag.setAttribute("data-blocked", "no");

                  script_tag.setAttribute("type", "text/javascript");
                } else {
                  /*if(script_tag.getAttribute('data-consent') == 'necessary'){

														script_tag.setAttribute('data-blocked',"no");

														script_tag.setAttribute('type',"text/javascript");

													}

													*/
                  //script_tag.setAttribute('data-consent','necessary');
                  //script_tag.setAttribute('data-blocked',"no");
                  //script_tag.setAttribute('type',"text/javascript");
                  /*if(consentExists == 1){

													if(script_tag.getAttribute('data-consent') == 'necessary'){

														script_tag.setAttribute('data-blocked',"no");

														script_tag.setAttribute('type',"text/javascript");

													}else{

													script_tag.setAttribute('data-blocked',"yes");

													script_tag.setAttribute('type',"text/plain");	

													}

													

												}*/
                }
              }
            }

            if (consentExists == 1) {
              if (
                script_tag.getAttribute("type") == "text/javascript" ||
                script_tag.getAttribute("type") == "application/javascript"
              ) {
                if (
                  script_tag.hasOwnProperty("data-consent") &&
                  script_tag.getAttribute("data-consent") == "necessary"
                ) {
                  script_tag.setAttribute("data-blocked", "no");

                  script_tag.setAttribute("type", "text/javascript");
                } else {
                  if (script_tag.getAttribute("data-blocked") == "yes") {
                    script_tag.setAttribute("data-blocked", "no");

                    script_tag.setAttribute("type", "text/javascript");
                  } else {
                    //script_tag.setAttribute('data-blocked',"yes");
                    //script_tag.setAttribute('type',"text/plain");
                  }
                }
              } else {
                if (script_tag.getAttribute("type") == "") {
                }
              }
            } else {
              script_tag.setAttribute("data-blocked", "no");

              //script_tag.setAttribute('type',"text/javascript");
            }
            CNZ_config.settings.beaconsList.forEach(function (script_src) {
              if (
                script_src.url.indexOf(script_tag.getAttribute("src")) !== -1
              ) {
                if (script_src.category == "unclassified") {
                  if (CNZ_config.settings.blockUnspecifiedBeacons == true) {
                    script_tag.setAttribute(
                      "data-consent",
                      script_src.category,
                    );

                    //script_tag.setAttribute('data-blocked','yes');

                    //script_tag.setAttribute('type',"text/plain");
                  } else {
                    script_tag.setAttribute(
                      "data-consent",
                      script_src.category,
                    );
                  }
                } else {
                  if (script_src.category == "necessary") {
                    script_tag.setAttribute("data-blocked", "no");

                    script_tag.setAttribute("type", "text/javascript");
                  }

                  script_tag.setAttribute("data-consent", script_src.category);
                }
              }
            });
          }
        });

        Conzent_Blocker.removeCookieByCategory();
      },

      removeCookieByCategory: function () {
        // If consent is not accepted
        let cookiePrefsValue_new = accessCookie("conzentConsentPrefs");
        var preferences_new = cookiePrefsValue_new ? JSON.parse(cookiePrefsValue_new) : null;
        if (preferences_new) {
          preferences_new.forEach((field) => {
            if (field != "necessary") {
              if (!CNZ_config.settings.allowed_categories.includes(field)) {
                CNZ_config.settings.allowed_categories.push(field);
              }
            }
          });
        }
        CNZ_config.settings.cookieTypes.forEach((field) => {
          if (
            CNZ_config.settings.allowed_categories.indexOf(field.value) !== -1
          ) {
            if (field.value != "necessary") {
              var clear_cookie = false;

              if (
                CNZ_config.settings.default_laws == "ccpa" ||
                1 == navigator.doNotTrack
              ) {
                if (
                  (CNZ_config.settings.allow_gpc == 1 &&
                    CNZ_config.gpcStatus == 1) ||
                  1 == navigator.doNotTrack
                ) {
                  clear_cookie = true;
                }
              }

              if (clear_cookie == true) {
                if (
                  CNZ_config.settings.cookiesList.hasOwnProperty(field.value)
                ) {
                  for (
                    var jk = 0;
                    jk < CNZ_config.settings.cookiesList[field.value].length;
                    jk++
                  ) {
                    var gk =
                      CNZ_config.settings.cookiesList[field.value][jk].name;

                    var ck_domain =
                      CNZ_config.settings.cookiesList[field.value][jk].domain;
                    if (ck_domain != "") {
                      Conzent_Cookie.erase(gk, ck_domain);
                    } else {
                      Conzent_Cookie.erase(gk, "");
                    }
                  }
                }

                Conzent_Cookie.blockMissedCookie();
              }
            }
          } else {
            if (CNZ_config.settings.cookiesList.hasOwnProperty(field.value)) {
              for (
                var jk = 0;
                jk < CNZ_config.settings.cookiesList[field.value].length;
                jk++
              ) {
                var gk = CNZ_config.settings.cookiesList[field.value][jk].name;

                var ck_domain =
                  CNZ_config.settings.cookiesList[field.value][jk].domain;

                if (CNZ_config.settings.allowed_cookies.indexOf(gk) !== -1) {
                } else {
                  if (field.value == "unclassified") {
                    if (CNZ_config.settings.blockUnspecifiedCookies == true) {
                      if (ck_domain != "") {
                        Conzent_Cookie.erase(gk, ck_domain);
                      } else {
                        Conzent_Cookie.erase(gk, "");
                      }
                    }
                  } else {
                    if (ck_domain != "") {
                      Conzent_Cookie.erase(gk, ck_domain);
                    } else {
                      Conzent_Cookie.erase(gk, "");
                    }
                  }
                }
              }

              if (field.value == "unclassified") {
                if (CNZ_config.settings.blockUnspecifiedCookies == true) {
                  Conzent_Cookie.blockMissedCookie();
                }
              }
            }
          }
        });

        let consentExists = 1;

        if (Conzent_Cookie.read("conzentConsent") == "true") {
          consentExists = 2;

          //Conzent_Cookie.blockMissedCookie();
        }

        if (consentExists == 1) {
          Conzent_Cookie.clearallCookies();
        }
      },

      runScripts: function () {
        var srcReplaceableElms = [
          "iframe",
          "IFRAME",
          "EMBED",
          "embed",
          "OBJECT",
          "object",
          "IMG",
          "img",
        ];

        var genericFuncs = {
          renderByElement: function (callback) {
            // Restore early-blocked elements (iframes/scripts blocked before consent)
            if (window._cnzBlockedEls && window._cnzBlockedEls.length > 0) {
              window._cnzConsentGiven = true;
              if (window._cnzEarlyObserver) {
                window._cnzEarlyObserver.disconnect();
              }
              // Replay cookie writes that were blocked before consent
              if (typeof window._cnzReplayBlockedCookies === "function") {
                window._cnzReplayBlockedCookies(
                  CNZ_config.settings.allowed_categories || []
                );
              }
              window._cnzBlockedEls.forEach(function (el) {
                var tag = el.tagName.toLowerCase();
                var cat = el.getAttribute("data-consent") || "marketing";
                // Only restore if this category was accepted
                if (
                  CNZ_config.settings.allowed_categories.indexOf(cat) !== -1
                ) {
                  var origSrc = el.getAttribute("data-cnz-src");
                  if (origSrc) {
                    if (tag === "iframe") {
                      el.setAttribute("src", origSrc);
                      el.style.display = "";
                      el.setAttribute("data-blocked", "no");
                      // Remove placeholder if one was added
                      if (
                        el.previousElementSibling &&
                        el.previousElementSibling.classList.contains(
                          "cnz-iframe-placeholder",
                        )
                      ) {
                        el.previousElementSibling.remove();
                      }
                    } else if (tag === "script") {
                      // Re-create script element to trigger execution
                      // MUST use _CreateElementBackup to bypass the blocking override
                      var newScript = cnz._CreateElementBackup.call(
                        document,
                        "script",
                      );
                      newScript.setAttribute("src", origSrc);
                      newScript.setAttribute("type", "text/javascript");
                      newScript.setAttribute("data-blocked", "no");
                      newScript.setAttribute("data-cnz-restored", "true");
                      if (el.parentNode) {
                        el.parentNode.insertBefore(newScript, el);
                        el.parentNode.removeChild(el);
                      } else {
                        // Element was blocked before being appended to DOM
                        document.head.appendChild(newScript);
                      }
                    }
                  }
                } else if (tag === "iframe" && el.parentNode) {
                  // Category not accepted — create placeholder for blocked iframe
                  if (
                    el.previousElementSibling &&
                    el.previousElementSibling.classList.contains(
                      "cnz-iframe-placeholder",
                    )
                  ) {
                    return; // Placeholder already exists
                  }
                  el.style.display = "";
                  el.style.visibility = "hidden";
                  el.style.position = "absolute";
                  var rect = el.getBoundingClientRect();
                  var pw =
                    rect.width > 0
                      ? rect.width + "px"
                      : el.getAttribute("data-cnz-width") || "100%";
                  var ph =
                    rect.height > 0
                      ? rect.height + "px"
                      : el.getAttribute("data-cnz-height") || "315";
                  var pcs = window.getComputedStyle
                    ? window.getComputedStyle(el)
                    : null;
                  var pExtra = "";
                  if (pcs) {
                    if (pcs.borderRadius && pcs.borderRadius !== "0px")
                      pExtra += "border-radius:" + pcs.borderRadius + ";";
                    if (pcs.marginTop && pcs.marginTop !== "0px")
                      pExtra += "margin-top:" + pcs.marginTop + ";";
                    if (pcs.marginBottom && pcs.marginBottom !== "0px")
                      pExtra += "margin-bottom:" + pcs.marginBottom + ";";
                    if (
                      pcs.width &&
                      pcs.width !== "0px" &&
                      pcs.width !== "auto"
                    )
                      pw = pcs.width;
                    if (
                      pcs.height &&
                      pcs.height !== "0px" &&
                      pcs.height !== "auto"
                    )
                      ph = pcs.height;
                  }
                  el.style.display = "none";
                  el.style.visibility = "";
                  el.style.position = "";
                  var pxRe = /px|%|vw|vh|em|rem/;
                  pw = pxRe.test(pw) ? pw : pw + "px";
                  ph = pxRe.test(ph) ? ph : ph + "px";
                  var pSrc = el.getAttribute("data-cnz-src") || "";
                  var VID_RE =
                    /(?:youtube(?:-nocookie)?\.com\/(?:[^\/\n\s]+\/\S+\/|(?:v|e(?:mbed)?)\/|\S*?[?&]v=)|youtu\.be\/)([a-zA-Z0-9_-]{11})/;
                  var pVid = pSrc.match(VID_RE);
                  var pBg = "background-color:rgba(30,30,30,0.85);";
                  if (pVid) {
                    pBg =
                      "background-image:linear-gradient(rgba(30,30,30,0.75),rgba(30,30,30,0.75)),url(https://img.youtube.com/vi/" +
                      pVid[1] +
                      "/maxresdefault.jpg);background-size:cover;background-position:center;";
                  }
                  var phDiv = document.createElement("div");
                  phDiv.className = "cnz-iframe-placeholder";
                  phDiv.setAttribute("data-cnz-for", pSrc);
                  phDiv.style.cssText =
                    "width:" +
                    pw +
                    ";height:" +
                    ph +
                    ";display:flex;align-items:center;justify-content:center;position:relative;box-sizing:border-box;overflow:hidden;" +
                    pBg +
                    pExtra;
                  phDiv.innerHTML =
                    '<div class="cnz-inner-text" style="text-align:center;padding:16px;color:#fff;cursor:pointer;">' +
                    (CNZ_config.placeholderText ||
                      "Please accept cookies to access this content") +
                    "</div>";
                  phDiv.addEventListener("click", function () {
                    if (_QE(".conzent-modal")) {
                      _QE(".conzent-modal").classList.add("cnz-modal-open");
                    }
                    if (_QE(".conzent-overlay")) {
                      _QE(".conzent-overlay").classList.remove("cnz-hide");
                    }
                  });
                  el.parentNode.insertBefore(phDiv, el);
                }
              });
              window._cnzBlockedEls = [];
            }

            scriptFuncs.renderScripts();

            htmlElmFuncs.renderSrcElement();

            callback();

            Conzent_Blocker.scriptsLoaded = true;
          },

          reviewConsent: function () {},
        };

        var scriptFuncs = {
          scriptsDone: function () {
            var DOMContentLoadedEvent = document.createEvent("Event");

            DOMContentLoadedEvent.initEvent("DOMContentLoaded", true, true);

            window.document.dispatchEvent(DOMContentLoadedEvent);
          },

          seq: function (arr, callback, index2) {
            if (typeof index2 === "undefined") {
              index2 = 0;
            }

            arr[index2](function () {
              index2++;

              if (index2 === arr.length) {
                callback();
              } else {
                scriptFuncs.seq(arr, callback, index2);
              }
            });
          },

          insertScript: function ($script, callback) {
            var allowedAttributes = [
              "data-cnz-class",

              "data-cnz-label",

              "data-cnz-placeholder",

              "data-consent",

              "data-cnz-src",

              "data-blocked",
            ];

            var scriptType = $script.getAttribute("data-consent");

            var elementPosition = $script.parentNode.nodeName;

            var isBlock = $script.getAttribute("data-blocked");

            var s_script = document.createElement("script");

            if (
              $script.getAttribute("type") == "text/javascript" ||
              $script.getAttribute("type") == "application/javascript"
            ) {
              for (
                var ij = 0;
                ij < CNZ_config.settings.allowed_scripts.length;
                ij++
              ) {
                if (
                  $script.src.indexOf(
                    CNZ_config.settings.allowed_scripts[ij],
                  ) === -1
                ) {
                  /*s_script.type                = 'text/plain';

										s_script.setAttribute('data-blocked','yes' );*/

                  s_script.addEventListener(
                    "beforescriptexecute",
                    function e(r) {
                      (r.preventDefault(),
                        s_script.removeEventListener("beforescriptexecute", e));
                    },
                  );
                } else {
                  s_script.type = "text/javascript";

                  s_script.setAttribute("data-blocked", "no");

                  s_script.setAttribute("data-consent", "necessary");
                }
              }
            } else {
              s_script.type = $script.getAttribute("type");

              s_script.setAttribute("data-blocked", isBlock);

              if (isBlock) {
                s_script.addEventListener("beforescriptexecute", function e(r) {
                  (r.preventDefault(),
                    s_script.removeEventListener("beforescriptexecute", e));
                });
              }
            }

            if ($script.async) {
              s_script.async = $script.async;
            }

            if ($script.defer) {
              s_script.defer = $script.defer;
            }

            if ($script.src) {
              s_script.onload = callback;

              s_script.onerror = callback;

              s_script.src = $script.src;
            } else {
              s_script.textContent = $script.innerText;
            }

            var elemt = $script;

            var attrs = elemt.getAttributeNames();

            var attrs_list = [];

            for (const name of elemt.getAttributeNames()) {
              const value = elemt.getAttribute(name);

              attrs_list.push({ nodeName: name, value: value });
            }

            var length = attrs_list.length;

            attrs = attrs_list;

            for (var ii = 0; ii < length; ++ii) {
              if (attrs[ii].nodeName !== "id") {
                if (allowedAttributes.indexOf(attrs[ii].nodeName) !== -1) {
                  s_script.setAttribute(attrs[ii].nodeName, attrs[ii].value);
                }
              }
            }

            if (Conzent_Blocker.blockingStatus === true) {
              if (
                CNZ_config.settings.allowed_categories.indexOf(scriptType) !==
                -1
              ) {
                s_script.setAttribute("data-cnz-consent", "accepted");

                if ($script.getAttribute("type") == "text/javascript") {
                  s_script.type = "text/javascript";
                }

                s_script.setAttribute("data-blocked", "no");
              }
            } else {
              if (scriptType == "necessary") {
                s_script.setAttribute("data-blocked", "no");
              }

              if ($script.getAttribute("type") == "text/javascript") {
                s_script.type = "text/javascript";
              }
            }

            if ($script.type != s_script.type) {
              if (elementPosition === "head" || elementPosition === "HEAD") {
                document.head.appendChild(s_script);

                if (!$script.src) {
                  callback();
                }

                $script.parentNode.removeChild($script);
              } else {
                document.body.appendChild(s_script);

                if (!$script.src) {
                  callback();
                }

                $script.parentNode.removeChild($script);
              }
            }
          },

          renderScripts: function () {
            var $scripts = _QEA('script[data-blocked="yes"]');

            if ($scripts.length > 0) {
              var runList = [];

              var typeAttr = "";

              Array.prototype.forEach.call(
                $scripts,

                function ($script) {
                  typeAttr = $script.getAttribute("type");

                  var elmType = $script.tagName;

                  runList.push(function (callback) {
                    scriptFuncs.insertScript($script, callback);
                  });
                },
              );

              scriptFuncs.seq(runList, scriptFuncs.scriptsDone);
            }
          },
        };

        const htmlElmFuncs = {
          renderSrcElement: function () {
            //var blockingElms = _QEA( '[data-cnz-class="cnz-blocker-script"]' );

            const alliframe = _QEA("iframe");

            alliframe.forEach(function (script_tag) {
              if (script_tag.getAttribute("data-consent") !== "necessary") {
                //script_tag.setAttribute('data-blocked',"yes");

                script_tag.setAttribute(
                  "data-cnz-placeholder",
                  CNZ_config.placeholderText,
                );

                script_tag.setAttribute("data-consent", "marketing");

                if (script_tag.hasAttribute("src")) {
                  script_tag.setAttribute(
                    "data-cnz-src",
                    script_tag.getAttribute("src"),
                  );
                }
              }
            });

            var blockingElms = _QEA('[data-blocked="yes"]');

            var length = blockingElms.length;

            for (var i = 0; i < length; i++) {
              var currentElm = blockingElms[i];

              var elmType = currentElm.tagName;

              if (srcReplaceableElms.indexOf(elmType) !== -1) {
                var elmCategory = currentElm.getAttribute("data-consent");

                if (Conzent_Blocker.blockingStatus === true) {
                  if (
                    CNZ_config.settings.allowed_categories.indexOf(
                      elmCategory,
                    ) !== -1
                  ) {
                    currentElm.setAttribute("data-blocked", "no");

                    this.replaceSrc(currentElm);
                  } else {
                    this.addPlaceholder(currentElm);

                    //currentElm.setAttribute('data-blocked',"yes");
                  }
                } else {
                  this.replaceSrc(currentElm);
                }
              }
            }
          },

          addPlaceholder: function (htmlElm) {
            if (
              htmlElm.previousElementSibling == null ||
              (htmlElm.previousElementSibling != null &&
                htmlElm.previousElementSibling.classList.contains(
                  "cnz-iframe-placeholder",
                ) === false)
            ) {
              var htmlElemType = htmlElm.getAttribute("data-cnz-placeholder");

              var htmlElemWidth = htmlElm.getAttribute("width");

              var htmlElemHeight = htmlElm.getAttribute("height");

              if (htmlElemWidth == null) {
                htmlElemWidth = htmlElm.offsetWidth;
              }

              if (htmlElemHeight == null) {
                htmlElemHeight = htmlElm.offsetHeight;
              }

              var pixelPattern = /px/;

              htmlElemWidth = pixelPattern.test(htmlElemWidth)
                ? htmlElemWidth
                : htmlElemWidth + "px";

              htmlElemHeight = pixelPattern.test(htmlElemHeight)
                ? htmlElemHeight
                : htmlElemHeight + "px";

              let VID_REGEX =
                /(?:youtube(?:-nocookie)?\.com\/(?:[^\/\n\s]+\/\S+\/|(?:v|e(?:mbed)?)\/|\S*?[?&]v=)|youtu\.be\/)([a-zA-Z0-9_-]{11})/;

              var addPlaceholder = "";

              var show_placeholder = "";

              if (htmlElm.hasAttribute("src")) {
                var vId = "";

                if (
                  htmlElm.getAttribute("src") == "about:blank" ||
                  htmlElemWidth == "0px" ||
                  htmlElemHeight == "0px"
                ) {
                  show_placeholder = " cnz-hide ";
                } else {
                  if (htmlElm.getAttribute("src") != "") {
                    if (htmlElm.getAttribute("src").match(VID_REGEX)) {
                      vId = htmlElm.getAttribute("src").match(VID_REGEX)[1];
                    }
                  } else {
                    show_placeholder = " cnz-hide ";
                  }
                }

                if (vId != "") {
                  addPlaceholder =
                    '<div style="width:' +
                    htmlElemWidth +
                    "; height:" +
                    htmlElemHeight +
                    ";background-image:linear-gradient(rgba(76, 72, 72, 0.7), rgba(76, 72, 72, 0.7)), url(https://img.youtube.com/vi/" +
                    vId +
                    '/maxresdefault.jpg);"  class="cnz-iframe-placeholder"><div class="cnz-inner-text">' +
                    htmlElemType +
                    "</div></div>";
                } else {
                  addPlaceholder =
                    '<div style="width:' +
                    htmlElemWidth +
                    "; height:" +
                    htmlElemHeight +
                    ';" class="cnz-iframe-placeholder ' +
                    show_placeholder +
                    '"><div class="cnz-inner-text">' +
                    htmlElemType +
                    "</div></div>";
                }

                if (htmlElm.getAttribute("src") != "null") {
                  //htmlElm.setAttribute( 'data-cnz-src',htmlElm.getAttribute('src') );
                }
              } else {
                show_placeholder = " cnz-hide ";

                if (htmlElemWidth == "0px" || htmlElemHeight == "0px") {
                  show_placeholder = " cnz-hide ";
                }

                addPlaceholder =
                  '<div style="width:' +
                  htmlElemWidth +
                  "; height:" +
                  htmlElemHeight +
                  ';" class="cnz-iframe-placeholder ' +
                  show_placeholder +
                  '"><div class="cnz-inner-text">' +
                  htmlElemType +
                  "</div></div>";
              }

              if (htmlElm.tagName !== "IMG") {
                //if(show_placeholder == true){

                htmlElm.insertAdjacentHTML("beforebegin", addPlaceholder);

                //}
              }

              //if(show_placeholder == true){

              htmlElm.removeAttribute("src");

              //}

              htmlElm.style.display = "none";
            }
          },

          replaceSrc: function (htmlElm) {
            if (!htmlElm.hasAttribute("src")) {
              var htmlElemSrc = htmlElm.getAttribute("data-cnz-src");

              if (htmlElemSrc != null) {
                htmlElm.setAttribute("src", htmlElemSrc);

                if (
                  htmlElm.previousElementSibling.classList.contains(
                    "cnz-iframe-placeholder",
                  ) === true
                ) {
                  htmlElm.previousElementSibling.remove();
                }

                htmlElm.style.display = "block";
              }
            }
          },
        };

        //genericFuncs.reviewConsent();

        genericFuncs.renderByElement(Conzent_Blocker.removeCookieByCategory);
      },
    };

    function conzentOpenBox() {
      //let cookieNoticeOpen='<div id="Conzent" class="display-left reopenbox">'+cookieIcon()+'</div>';

      setTimeout(function () {
        if (_QE("#Conzent")) {
          _QE("#Conzent").style.opacity = 0;
        }

        if (_QE(".cnz-btn-revisit-wrapper")) {
          _QE(".cnz-btn-revisit-wrapper").classList.add("cnz-revisit-hide");
        }

        fadeInEffect();
      }, 300);
    }

    function conzentCloseBox() {
      setTimeout(function () {
        if (_QE("#Conzent")) {
          _QE("#Conzent").style.opacity = 1;
        }

        if (_QE(".cnz-btn-revisit-wrapper")) {
          _QE(".cnz-btn-revisit-wrapper").classList.remove("cnz-revisit-hide");
        }

        fadeOutEffect();
      }, 300);
    }

    function fadeInEffect() {
      var fadeTarget = _QE("#Conzent");

      fadeTarget.classList.remove("cnz-hide");

      var opacity_val = 0;

      var fadeEffect = setInterval(function () {
        if (opacity_val > 0.9) {
          fadeTarget.style.opacity = 1;

          clearInterval(fadeEffect);
        } else {
          opacity_val += 0.1;

          fadeTarget.style.opacity = opacity_val;
        }
      }, 10);
    }

    function fadeOutEffect() {
      var fadeTarget = _QE("#Conzent");

      var fadeEffect = setInterval(function () {
        if (!fadeTarget.style.opacity) {
          fadeTarget.style.opacity = 1;
        }

        if (fadeTarget.style.opacity > 0) {
          fadeTarget.style.opacity -= 0.1;
        } else {
          clearInterval(fadeEffect);

          fadeTarget.classList.add("cnz-hide");

          //fadeTarget.remove();
        }
      }, 10);
    }

    function checkAllowedCookie(field) {
      return CNZ_config.settings.allowed_categories.includes(field)
        ? "granted"
        : "denied";
    }

    function _checkCookieCategory(field) {
      var preferences_val = accessCookie("conzentConsentPrefs"),
        preferences_item = preferences_val ? JSON.parse(preferences_val) : null;

      if (preferences_item) {
        return preferences_item.includes(field) ? "yes" : "";
      }

      return "";
    }
    function isGtmLoaded() {
      window.dataLayer = window.dataLayer || [];
      let gtmStartedEvent = window.dataLayer.find(
        (element) => element["gtm.start"],
      );
      if (!gtmStartedEvent) {
        return false; // Not even the GTM inline config has executed
      } else if (!gtmStartedEvent["gtm.uniqueEventId"]) {
        return false; // GTM inline config has ran, but main GTM js is not loaded (likely AdBlock, NoScript, URL blocking etc.)
      }
      return true; // GTM is fully loaded and working
    }
    function setup_msconsent(default_mode) {
      if (CNZ_config.settings.ms_consent == 1) {
        window.uetq = window.uetq || [];
        window.uetq.push("consent", default_mode, {
          ad_storage: checkAllowedCookie("marketing") ? "granted" : "denied",
        });
      }
    }
    function setup_clarity_consent(default_mode) {
      if (CNZ_config.settings.clarity_consent == 1) {
        if (typeof window.clarity === "function") {
          if (default_mode == "default") {
            window.clarity("consent", false);
          } else {
            var analytics = checkAllowedCookie("analytics");
            window.clarity("consent", analytics === "granted");
          }
        }
      }
    }
    function setup_amazon_consent() {
      if (CNZ_config.settings.amazon_consent == 1) {
        var marketing = checkAllowedCookie("marketing");
        var granted = marketing === "granted";

        // Amazon Publisher Services (APS / TAM / UAM)
        if (typeof window.apstag !== "undefined") {
          window.apstag.setConsent({
            gdpr: {
              enabled: true,
              consent: granted ? "1" : "0",
            },
          });
        }

        // Amazon Ads Tag consent signal
        window.amzn_assoc_ad_consent = granted ? "granted" : "denied";
      }
    }
    function setup_gcm() {
      // Guard against multiple calls as setup is only needed once

      if (CNZ_config.settings.google_consent == 1) {
        window.dataLayer = window.dataLayer || [];

        function conzent_gtag() {
          window.dataLayer.push(arguments);
        }

        conzent_gtag("set", "developer_id.dOTMxNW", true);

        // Raise GTM Event to let everybody know that Conzent is ready

        window.dataLayer.push({
          event: "conzent_loaded",
        });

        // See https://support.google.com/tagmanager/answer/10718549?hl=en

        // st = storage type

        // ct = consent type

        // Performance is not needed in consent mode so ignored

        // Unclassified is not needed in consent mode so ignored

        var st_ncl = checkAllowedCookie("functional"),
          ct_adv = checkAllowedCookie("marketing"),
          ct_ana = checkAllowedCookie("analytics"),
          st_prf = checkAllowedCookie("preferences");

        var consent_options = {
          ad_storage: ct_adv,

          ad_user_data: ct_adv,

          ad_personalization: ct_adv,

          analytics_storage: ct_ana,

          functionality_storage: st_ncl,

          personalization_storage: st_prf,

          security_storage: "granted",
        };
        if (isGtmLoaded() != true) {
          conzent_gtag("consent", "default", consent_options);
        }
        conzent_gtag("set", "consent", consent_options);

        /**if(cookieExists("conzentConsent") == true || cookieExists("conzentConsent") == 'true'){
						
					}**/

        conzent_gtag("consent", "update", consent_options);

        conzent_gtag("consent", "consent_update", consent_options);

        //conzent_gtag("set", "ads_data_redaction", true);

        //conzent_gtag("set", "url_passthrough", true);

        // To Push an event to the dataLayer use this and not gtag. See why here https://www.analyticsmania.com/post/google-tag-manager-custom-event-trigger/

        //legecy event

        window.dataLayer.push({
          event: "conzent_consent_update",
        });

        window.dataLayer.push({
          event: "cookie_consent_update",
        });
      }

      if (
        window.location.href.includes("?debug=true") ||
        CNZ_config.debug_mode == 1
      ) {
        if (
          (console.log("Debugging Google Consent Mode:\n"),
          window.google_tag_data &&
            google_tag_data.ics &&
            google_tag_data.ics.entries)
        ) {
          //showConsoleLog(window.google_tag_data.ics.entries);

          for (o in ((e = ""), (r = google_tag_data.ics.entries), (n = !1), r))
            ((i = r[o]),
              (c = _CK(i.default)),
              (a = _CK(i.update)),
              (e = ""
                .concat(e, "\n\t")
                .concat(o, ":\n\t\tDefault: ")
                .concat(c, "\n\t\tUpdate: ")
                .concat(a, "\n\n")),
              "missing" === c && (n = !0));

          e = n
            ? "".concat(
                e,
                "\n\tWarning: Some categories are missing a default value.\n",
              )
            : google_tag_data.ics.wasSetLate
              ? "".concat(
                  e,
                  "\n\tWarning: A tag read consent before a default was set.\n",
                )
              : "".concat(e, "\n\tConsent mode states were set correctly.");

          showConsoleLog(e);
        }
        if (typeof wp_set_consent === "function") {
          console.log("Debugging WordPress Consent API:\n");
          setTimeout(function () {
            var e_str = "\n";
            e_str +=
              "Functional : " +
              Conzent_Cookie.read("wp_consent_functional") +
              "\n";
            e_str +=
              "Marketing : " +
              Conzent_Cookie.read("wp_consent_marketing") +
              "\n";
            e_str +=
              "Preferences : " +
              Conzent_Cookie.read("wp_consent_preferences") +
              "\n";
            e_str +=
              "Statistics : " +
              Conzent_Cookie.read("wp_consent_statistics") +
              "\n";
            e_str +=
              "Statistics-anonymous : " +
              Conzent_Cookie.read("wp_consent_statistics-anonymous") +
              "\n";
            showConsoleLog(e_str);
          }, 1000);
        }
        if (window.Shopify && (window.Shopify.trackingConsent || window.Shopify.customerPrivacy)) {
          console.log("Debugging Shopify Customer Privacy API:\n");
          setTimeout(function () {
            var s_str = "\n";
            var consent_items =
              window.Shopify.customerPrivacy.currentVisitorConsent();
            s_str += "marketing : " + consent_items.marketing + "\n";
            s_str += "analytics : " + consent_items.analytics + "\n";
            s_str += "preferences : " + consent_items.preferences + "\n";
            s_str += "sale_of_data : " + consent_items.sale_of_data + "\n";
            showConsoleLog(s_str);
          }, 1000);
        }
      }
    }

    function setup_meta_consent() {
      if (CNZ_config.settings.meta_consent == 1) {
        if (typeof fbq === "function") {
          var marketing = checkAllowedCookie("marketing");
          fbq("consent", marketing === "granted" ? "grant" : "revoke");
        }
      }
    }

    function setup_gtm_inject() {
      var i = CNZ_config.settings.gtm_id;
      if (!i || document.getElementById("cnz-gtm-script")) return;
      var l = CNZ_config.settings.gtm_dl || "dataLayer";
      // Google Tag Manager — matches Google's official snippet exactly
      window[l] = window[l] || [];
      window[l].push({ "gtm.start": new Date().getTime(), event: "gtm.js" });
      // Use the original createElement to bypass Conzent's own script blocker.
      // The monkey-patched createElement intercepts src/type setters on script elements.
      // Using the backed-up original returns a clean, unpatched element.
      var _create = cnz._CreateElementBackup || document.createElement;
      var j = _create.call(document, "script");
      var dln = l != "dataLayer" ? "&l=" + l : "";
      j.async = true;
      j.id = "cnz-gtm-script";
      j.src = "https://www.googletagmanager.com/gtm.js?id=" + i + dln;
      var f = document.getElementsByTagName("script")[0];
      if (f && f.parentNode) {
        f.parentNode.insertBefore(j, f);
      } else {
        (document.head || document.documentElement).appendChild(j);
      }
      // Google Tag Manager (noscript) — injected into body for completeness
      if (document.body) {
        var ns = document.createElement("noscript");
        ns.innerHTML =
          '<iframe src="https://www.googletagmanager.com/ns.html?id=' +
          i +
          '" height="0" width="0" style="display:none;visibility:hidden"></iframe>';
        document.body.insertBefore(ns, document.body.firstChild);
      }
    }

    function _CK(t) {
      return void 0 === t ? "missing" : t ? "granted" : "denied";
    }

    function conzentRandomKey() {
      let ans = "",
        len = 40,
        arr = "0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghiklmnopqrstuvwxyz";

      for (let i = len; i > 0; i--) {
        ans += arr[Math.floor(Math.random() * arr.length)];
      }

      return ans;
    }

    function conzentUniqueKey(t) {
      let ans = "",
        len = t,
        arr = "0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghiklmnopqrstuvwxyz";

      for (let i = len; i > 0; i--) {
        ans += arr[Math.floor(Math.random() * arr.length)];
      }

      return ans;
    }

    function loadJSON(path, success, error) {
      var xhr = new XMLHttpRequest();

      xhr.onreadystatechange = function () {
        if (xhr.readyState === 4) {
          if (xhr.status === 200) {
            success(JSON.parse(xhr.responseText));
          } else {
            //error(xhr);
            showConsoleError(xhr.responseText);
          }
        }
      };

      xhr.open("GET", path, true);

      xhr.send();
    }

    function c_t(e) {
      return (
        (t =
          "function" == typeof Symbol && "symbol" == typeof Symbol.iterator
            ? function (t) {
                return typeof t;
              }
            : function (t) {
                return t &&
                  "function" == typeof Symbol &&
                  t.constructor === Symbol &&
                  t !== Symbol.prototype
                  ? "symbol"
                  : typeof t;
              }),
        t(e)
      );
    }

    var c_e = function (t) {
        return "string" == typeof t;
      },
      c_r = function (t) {
        return t instanceof Blob;
      };

    function c_n(t, n) {
      var o = this.event && this.event.type,
        i = "unload" === o || "beforeunload" === o,
        s =
          "XMLHttpRequest" in this
            ? new XMLHttpRequest()
            : new ActiveXObject("Microsoft.XMLHTTP");

      (s.open("POST", c_t, !i),
        (s.withCredentials = !0),
        s.setRequestHeader("Accept", "*/*"),
        c_e(n)
          ? (s.setRequestHeader("Content-Type", "text/plain;charset=UTF-8"),
            (s.responseType = "text"))
          : r(n) && n.type && s.setRequestHeader("Content-Type", n.type));

      try {
        s.send(n);
      } catch (c_t) {
        return !1;
      }

      return !0;
    }
    (function () {
      "navigator" in this || (this.navigator = {});

      "function" != typeof this.navigator.sendBeacon &&
        (this.navigator.sendBeacon = c_n.bind(this));
    }).call(
      "object" === ("undefined" == typeof window ? "undefined" : c_t(window))
        ? window
        : {},
    );

    function _loadLocation() {
      if (CNZ_config.currentTarget != "all") {
        var xhr = new XMLHttpRequest();

        xhr.onreadystatechange = function () {
          if (xhr.readyState === 4) {
            if (xhr.status === 200) {
              var loc_res = JSON.parse(xhr.responseText);

              if (loc_res.hasOwnProperty("country")) {
                CNZ_config.currentTarget = loc_res.country.toLowerCase();

                CNZ_config.in_eu = loc_res.in_eu;
              }

              //success(JSON.parse(xhr.responseText));
            } else {
              //error(xhr);

              //console.log(xhr);
              showConsoleError(xhr.responseText);
            }
          }
        };

        xhr.open("GET", "[API_PATH]/geo_ip", true);

        xhr.send();
      }
    }

    function _showBanner() {
      var show_banner = 1;

      if (CNZ_config.settings.geo_target == "selected") {
        if (
          CNZ_config.settings.geo_target_selected.includes(
            CNZ_config.currentTarget,
          )
        ) {
          show_banner = 1;
        } else {
          show_banner = 0;
        }
      }
      if (
        CNZ_config.user_site.disable_on_pages.includes(window.location.href)
      ) {
        show_banner = 0;
      }
      if (CNZ_config.user_site.status != 1) {
        show_banner = 0;
      }

      return show_banner;
    }

    function getRootDomain() {
      for (var dm = 0; dm < CNZ_config.allowed_domains.length; dm++) {
        if (
          CNZ_config.allowed_domains[dm] ==
          window.location.hostname.replace(/^www\./, "")
        ) {
          return CNZ_config.allowed_domains[dm];
        }
      }

      return "[SITE_DOMAIN]";
    }
    function showConsoleError(message) {
      const ec =
        "\n                display: inline-block;\n                font-size: 14px;\n                background: linear-gradient(to right,rgb(181, 34, 34),rgb(201, 26, 26),rgb(181, 34, 34));\n                color: white;\n                padding: 4px;\n                border-radius: 4px;\n            ";
      let ct = "\n\n";
      ((ct += "💡 An error has occured with the Conzent Script \n"),
        (ct += "✉️ Send the below error to support@conzent.net\n"),
        (ct += "❌ Error:"),
        (ct += message),
        (ct += "\n\n"),
        (ct += "Learn more at: https://getconzent.com/\n\n"),
        console.groupCollapsed("%cConzent.net - Configuration error.", ec),
        console.log(`%c${ct}`, "font-size: 14px;"),
        console.groupEnd());
    }
    function showConsoleLog(message) {
      const ec =
        "\n                display: inline-block;\n                font-size: 14px;\n                background: linear-gradient(to right, #22b573,rgb(15, 67, 43), #22b573);\n                color: white;\n                padding: 4px;\n                border-radius: 4px;\n            ";
      let ct = "\n\n";
      ((ct += "💡 Debugging for Conzsent \n"),
        (ct += "🚀 Log:"),
        (ct += message),
        (ct += "\n\n"),
        (ct += "Learn more at: https://getconzent.com/\n\n"),
        console.groupCollapsed("%cConzent.net - Debug Logging.", ec),
        console.log(`%c${ct}`, "font-size: 14px;"),
        console.groupEnd());
    }
    function showInfo() {
      const ec =
        "\n                display: inline-block;\n                font-size: 14px;\n                background: linear-gradient(to right,#22b573,rgb(30, 102, 69), #22b573);\n                color: white;\n                padding: 4px;\n                border-radius: 4px;\n            ";
      let ct = "\n\n";
      ((ct += "🌐 Google CMP Certified\n"),
        (ct += "🌐 IAB Europe TCF 2.3 Certified\n"),
        (ct += "🌐 Google GTM Integration for easy setup\n"),
        (ct += "🌐 Template based configuration\n"),
        (ct += "🌐 Advanced features for 100% design control\n"),
        (ct += "🌐 Language detection: " + CNZ_config.currentLang + "\n"),
        (ct +=
          "🌐 Google Consent Mode detected: " +
          (CNZ_config.settings.google_consent ? "Yes" : "No") +
          "\n"),
        (ct +=
          "🌐 Google Tag Manager detected: " +
          (CNZ_config.settings.gtm_id ? "Yes" : "No") +
          "\n"),
        (ct +=
          "🌐 Meta Pixel detected: " +
          (CNZ_config.settings.meta_consent ? "Yes" : "No") +
          "\n"),
        (ct +=
          "🌐 Microsoft Consent detected: " +
          (CNZ_config.settings.ms_consent ? "Yes" : "No") +
          "\n"),
        (ct += "\n\n"),
        (ct +=
          "Learn more at: https://getconzent.com/\n\nVersion: " +
          window._cnzVersion +
          "\n\n"),
        console.group(
          "%cConzent.net - TCF 2.3 & CMP Certified Cookie Solution",
          ec,
        ),
        console.log(`%c${ct}`, "font-size: 14px;"),
        console.groupEnd());
    }

    var config_json = "gdpr";

    if (CNZ_config.displayBanner == "ccpa") {
      config_json = "ccpa";
      if (Object.keys(CNZ_config.ccpa_setting).length > 0) {
        CNZ_config.settings = CNZ_config.ccpa_setting;
      }

      cnz._Store._bannerConfig = CNZ_config.settings;
    } else if (CNZ_config.displayBanner == "gdpr_ccpa") {
      if (CNZ_config.in_eu == true) {
        config_json = "ccpa";

        if (Object.keys(CNZ_config.ccpa_setting).length > 0) {
          CNZ_config.settings = CNZ_config.ccpa_setting;
        }

        cnz._Store._bannerConfig = CNZ_config.settings;
      } else {
        config_json = "gdpr";
      }
    }

    function load_config(config_json) {
      var xhr = new XMLHttpRequest();

      xhr.onreadystatechange = function () {
        if (xhr.readyState === 4) {
          if (xhr.status === 200) {
            CNZ_config.settings = JSON.parse(xhr.responseText);

            cnz._Store._bannerConfig = JSON.parse(xhr.responseText);
          } else {
            //error(xhr);

            showConsoleError(xhr.responseText);
          }
        }
      };

      xhr.open(
        "GET",
        "[WEB_PATH]sites_data/[WEBSITE_KEY]/" + config_json + "_config.json",
        true,
      );

      xhr.send();
    }

    function waitforload(ms) {
      var start = new Date().getTime();

      var end = start;

      while (end < start + ms) {
        end = new Date().getTime();
      }
    }

    function checkCNZCookie() {
      //blockScriptIframe.iframes();

      //Conzent_Blocker.runScripts();

      Conzent_Cookie.clearallCookies();

      Conzent_Cookie.blockMissedCookie();

      runCall();

      //cnz._observer.disconnect(), document.createElement = cnz._CreateElementBackup;
    }

    // Function to sync conzent consent with Shoplift
    function syncConzentWithShoplift() {
      if (window.shoplift) {
        // Check for statistics/analytics consent
        const hasAnalyticsConsent =
          checkAllowedCookie("analytics") == "granted" ? true : false;

        // Update Shoplift consent
        if (window.shoplift && window.shoplift.setAnalyticsConsent) {
          window.shoplift
            .setAnalyticsConsent(hasAnalyticsConsent)
            .then(() => {
              console.log(
                "Shoplift consent synchronized with conzent:",
                hasAnalyticsConsent,
              );
            })
            .catch((error) => {
              console.error("Failed to update Shoplift consent:", error);
            });
        } else {
          // Retry if Shoplift isn't ready
          setTimeout(syncConzentWithShoplift, 100);
        }
      }
    }

    function loadInit() {
      var show_banner = _showBanner();

      // Initialize cookie consent banner

      let PrefsValue_new = accessCookie("conzentConsentPrefs");

      // If consent is not accepted

      var preferences_selected = PrefsValue_new ? JSON.parse(PrefsValue_new) : null;

      let ConsentExists = cookieExists("conzentConsent");

      if (preferences_selected) {
        preferences_selected.forEach((field) => {
          if (field != "necessary") {
            if (!CNZ_config.settings.allowed_categories.includes(field)) {
              CNZ_config.settings.allowed_categories.push(field);
            }
          }
        });
      }

      if (show_banner && Object.keys(CNZ_config.settings).length > 0) {
        var conzent_id = cookieExists("conzent_id");

        var lastRenewedDate = Conzent_Cookie.read("lastRenewedDate");

        if (conzent_id === false) {
          conzent_id = conzentRandomKey();

          Conzent_Cookie.set(
            "conzent_id",
            conzent_id,
            CNZ_config.settings.expires,
            1,
          );

          Conzent_Cookie.set(
            "lastRenewedDate",
            CNZ_config.settings.renew_consent,
            CNZ_config.settings.expires,
            1,
          );

          lastRenewedDate = CNZ_config.settings.renew_consent;
        }

        conzent_id = Conzent_Cookie.read("conzent_id");

        if (
          conzent_id &&
          parseInt(lastRenewedDate || 0) <
            parseInt(CNZ_config.settings.renew_consent)
        ) {
          Conzent_Cookie.erase("conzentConsent");

          //Conzent_Cookie.erase("conzentConsentPrefs");

          let prefs_new = ["necessary"];

          Conzent_Cookie.set(
            "conzentConsentPrefs",
            encodeURIComponent(JSON.stringify(prefs_new)),
            CNZ_config.settings.expires,
            1,
          );

          Conzent_Cookie.erase("euconsent");

          Conzent_Cookie.erase("conzent_id");

          // Regenerate conzent_id after renewal erase
          conzent_id = conzentRandomKey();
          Conzent_Cookie.set(
            "conzent_id",
            conzent_id,
            CNZ_config.settings.expires,
            1,
          );

          // Update lastRenewedDate so renewal doesn't fire again on next load
          Conzent_Cookie.set(
            "lastRenewedDate",
            CNZ_config.settings.renew_consent,
            CNZ_config.settings.expires,
            1,
          );
          lastRenewedDate = CNZ_config.settings.renew_consent;

          // Re-show the banner since ConzentFN hid it before renewal ran
          conzentOpenBox();
        }

        // IMPORTANT: This is monitoring all elemnenets on the page as they are created.

        // Blocks script tag be deleting it from the DOM, which is more safe than setting it to text/plain

        // Iframes are changed to image placeholders

        let hasConsent = false;

        let blockall = false;

        if (cookieExists("conzentConsent"))
          hasConsent = accessCookie("conzentConsent") == "true";

        if (
          CNZ_config.settings.default_laws == "ccpa" ||
          1 == navigator.doNotTrack
        ) {
          if (CNZ_config.settings.allow_gpc == 1 && CNZ_config.gpcStatus == 1) {
            blockall = true;
          }
        }

        if (1 == navigator.doNotTrack) {
          blockall = true;
        }

        if (preferences_selected) {
          //[IAB2LOADTCF_old]
        } else {
          //Conzent_Cookie.blockMissedCookie();

          //Conzent_Cookie.clearallCookies();

          "complete" === document.readyState
            ? checkCNZCookie()
            : window.addEventListener("load", checkCNZCookie);
          //[IAB2LOADTCF_old2]
        }

        if (hasConsent !== true || blockall == true) {
          //blockScriptIframe.iframes();

          //blockScriptIframe.scripts();

          /*setTimeout(function(){

						checkCNZCookie();				

					},1000);*/

          "complete" === document.readyState
            ? checkCNZCookie()
            : window.addEventListener("load", checkCNZCookie);
        }
      }

      // GCM default/update MUST fire synchronously before GTM processes tags.
      // Deferring to window.load causes GTM to read consent as "Unknown".
      setup_gcm();
      setup_meta_consent();
      setup_gtm_inject();

      if (preferences_selected || ConsentExists) {
        setup_msconsent("update");
        setup_clarity_consent("update");
      } else {
        setup_msconsent("default");
        setup_clarity_consent("default");
      }
      setup_amazon_consent();
      if (ConsentExists) {
        "complete" === document.readyState
          ? cnzEvent("conzentck_cookie_banner_load", getCnzConsent())
          : window.addEventListener(
              "load",
              cnzEvent("conzentck_cookie_banner_load", getCnzConsent()),
            );
      }

      "complete" === document.readyState
        ? syncConzentWithShoplift()
        : window.addEventListener("load", syncConzentWithShoplift);

      cnzPageViewLog("banner_load");
    }

    function parseDocumentCookie(str) {
      const out = [];
      (str || "").split(/;\s*/).forEach((pair) => {
        if (!pair) return;
        const idx = pair.indexOf("=");
        const name = idx >= 0 ? pair.slice(0, idx) : pair;
        const val = idx >= 0 ? pair.slice(idx + 1) : "";
        if (name) out.push({ name, valueLen: (val || "").length });
      });
      return out;
    }
    function scanCookie() {
      if (window.location.href.includes("?scan=true")) {
        var url_link = window.location.search;
        var main_url = window.location.href.split("?");
        var scan_key = "scan_id";
        var scan_id = 0;
        scan_key = scan_key.replace(/[\[\]]/g, "\\$&");

        let regex = new RegExp("[?&]" + scan_key + "(=([^&#]*)|&|#|$)");
        let results = regex.exec(url_link);

        if (!results) {
          scan_id = 0;
        } else {
          if (!results[2]) {
            scan_id = 0;
          } else {
            scan_id = decodeURIComponent(results[2]);
          }
        }

        if (scan_id > 0) {
          const jsCookies = parseDocumentCookie(document.cookie || "");
          const localStorageData = {};
          for (let i = 0; i < localStorage.length; i++) {
            const key = localStorage.key(i);
            localStorageData[key] = localStorage.getItem(key);
          }

          var r = {
              scan_id: scan_id,
              action: "runscan",
              scan_url: main_url[0],
              consent_phase: accessCookie("conzentConsent")
                ? "post_consent"
                : "pre_consent",
              data: { c: jsCookies, l: localStorageData },
            },
            n = new FormData();
          (n.append("payload", JSON.stringify(r)),
            n.append("key", "[WEBSITE_KEY]"),
            navigator.sendBeacon("[API_PATH]/scan_data", n));
        }
      }
    }
    !(async function () {
      /*if (window.location.href.indexOf("conzent.net/app") > -1) {

			   //nothing to do

			}

			else{

				[LOAD_LOCATION]

				[LOAD_CONFIG]

				

				[DIRECT_LOAD]	

			}*/

      [LOAD_LOCATION][LOAD_CONFIG][DIRECT_LOAD];
    })();

    if (window.location.href.indexOf("conzent.net/app") > -1) {
      //nothing to do
    } else {
      var show_banner_iab = _showBanner();
      if (show_banner_iab) {
        [IAB2LOADTCF];
      }

      [READY_LOAD];
    }
    [SHOW_ADS];
  })();
}
