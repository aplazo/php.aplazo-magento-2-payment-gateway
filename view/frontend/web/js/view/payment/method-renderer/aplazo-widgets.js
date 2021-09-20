(() => {
    "use strict";
    var t = {
            107: (t, e) => {
                Object.defineProperty(e, "__esModule", { value: !0 }), (e.AplazoLogo = void 0);
                const n = document.createElement("template");
                n.innerHTML =
                    '\n    <style>\n    :host{\n    display: inline-flex;\n    }\n          @media only screen and (max-width: 420px) {\n            img {\n              height: 25px !important;\n                }\n           }\n    img {\n        height:30px;\n        position:relative;\n        top: 10px;\n    }\n    </style>\n     <img id="logo-image"  src="https://cdn.aplazo.mx/aplazo-logo-png-colores.png" alt="">\n \n';
                class o extends HTMLElement {
                    constructor() {
                        var t;
                        super(), this.attachShadow({ mode: "open" }), null === (t = this.shadowRoot) || void 0 === t || t.appendChild(n.content.cloneNode(!0));
                    }
                    connectedCallback() {
                        var t;
                        const e = this.getAttribute("theme"),
                            n = this.getAttribute("logo-size"),
                            o = e || "light",
                            i = null === (t = this.shadowRoot) || void 0 === t ? void 0 : t.getElementById("logo-image");
                        if (!i) return;
                        const r = n || (window.innerWidth < 420 ? "25" : "35");
                        switch (((i.style.height = r + "px"), o)) {
                            case "dark":
                                i.setAttribute("src", "https://cdn.aplazo.mx/aplazo_logo_blanco.png"), (i.style.height = "" + (Number(r) - 10));
                                break;
                            case "light":
                                i.setAttribute("src", "https://cdn.aplazo.mx/aplazo-logo-png-colores.png");
                        }
                    }
                }
                (e.AplazoLogo = o), customElements.define("aplazo-logo", o);
            },
            697: (t, e) => {
                Object.defineProperty(e, "__esModule", { value: !0 }), (e.Icon = void 0);
                const n = document.createElement("template");
                n.innerHTML = "\n    <script>\n  \n    </script>\n   <span >\n   \n   </span>\n \n";
                class o extends HTMLElement {
                    constructor() {
                        var t;
                        super(), this.attachShadow({ mode: "open" }), null === (t = this.shadowRoot) || void 0 === t || t.appendChild(n.content.cloneNode(!0));
                    }
                }
                (e.Icon = o), customElements.define("ap-icon", o);
            },
            214: (t, e, n) => {
                Object.defineProperty(e, "__esModule", { value: !0 }), (e.Typography = e.InstructionCard = e.Icon = e.AplazoLogo = void 0);
                var o = n(107);
                Object.defineProperty(e, "AplazoLogo", {
                    enumerable: !0,
                    get: function () {
                        return o.AplazoLogo;
                    },
                });
                var i = n(697);
                Object.defineProperty(e, "Icon", {
                    enumerable: !0,
                    get: function () {
                        return i.Icon;
                    },
                });
                var r = n(445);
                Object.defineProperty(e, "InstructionCard", {
                    enumerable: !0,
                    get: function () {
                        return r.InstructionCard;
                    },
                });
                var a = n(324);
                Object.defineProperty(e, "Typography", {
                    enumerable: !0,
                    get: function () {
                        return a.Typography;
                    },
                });
            },
            445: (t, e) => {
                Object.defineProperty(e, "__esModule", { value: !0 }), (e.InstructionCard = void 0);
                const n = document.createElement("template");
                n.innerHTML =
                    '\n                <style>\n                    .info{\n                        justify-content: center;\n                        display: flex;\n                        flex-direction: column;\n                        padding-left: 22px;\n                     }                   \n                </style>\n                <div style="display: flex;align-content: center;margin: 14px 0px 14px 0px">\n                    \x3c!--logo for step--\x3e\n                    <div>\n                      <img id="step-img"  alt="">\n                    </div>\n                     \x3c!--info--\x3e\n                    <div class="info">\n                       <aplazo-text id="step-title" variant="title"></aplazo-text>\n                       <aplazo-text id="step-description" variant="p"></aplazo-text>\n                    </div>\n                </div> \n \n';
                class o extends HTMLElement {
                    constructor() {
                        var t;
                        super(), this.attachShadow({ mode: "open" }), null === (t = this.shadowRoot) || void 0 === t || t.appendChild(n.content.cloneNode(!0));
                    }
                    connectedCallback() {
                        var t, e, n;
                        const o = null === (t = this.shadowRoot) || void 0 === t ? void 0 : t.querySelector("#step-title");
                        o && (o.textContent = this.StepTitle || "");
                        const i = null === (e = this.shadowRoot) || void 0 === e ? void 0 : e.querySelector("#step-description");
                        i && (i.textContent = this.StepDescription || "");
                        const r = null === (n = this.shadowRoot) || void 0 === n ? void 0 : n.querySelector("#step-img");
                        r && (r.src = this.StepImg || "");
                    }
                    get StepTitle() {
                        return this.getAttribute("step-title") || "";
                    }
                    get StepDescription() {
                        return this.getAttribute("step-description") || "";
                    }
                    get StepImg() {
                        return this.getAttribute("step-img") || "";
                    }
                }
                (e.InstructionCard = o), customElements.define("instruction-card", o);
            },
            324: (t, e) => {
                Object.defineProperty(e, "__esModule", { value: !0 }), (e.Typography = void 0);
                const n = document.createElement("template");
                n.innerHTML =
                    "\n    <style>\n\n    @import url('https://fonts.googleapis.com/css2?family=Manrope:wght@200;700&display=swap');\n\n    :host {\n        font-family: 'Manrope', sans-serif;\n        font-style: normal;\n        letter-spacing: 0px;\n        text-align: left;\n        color: #131332;\n    }\n    div {\n        text-align: inherit;\n    }\n    .p{\n        font-size: 14px;\n        font-weight: 400;\n        line-height: 26px;\n        color: #78909C;\n    }\n    .title {\n        font-size: 16px;\n        font-weight: 700;\n        line-height: 24px;\n    }\n    .light-title {\n        font-size: 16px;\n        font-weight: 400;\n        line-height: 28px;\n    }\n    .big {\n        font-weight: bold;\n        font-size: 28px;\n        line-height: 42px;\n    }\n    .soft-p {\n        color: #B0BEC5;;\n        font-size: 12px;\n        line-height: 20px;\n    }\n     @media only screen and (max-width: 420px) {\n         .big {\n            font-size: 20px;\n            line-height:  30px;px;\n        }\n         .light-title{\n            font-size: 14px;\n          line-height: 25px;\n        }\n        .p{\n        font-size: 12px;\n         line-height: 17px;\n        } \n       .title {\n        font-size: 12px;\n        line-height:20px;\n        }\n        \n        .soft-p{\n            font-size: 10px;\n        }\n       }\n    </style>\n    <div id=\"text-holder\"></div>    \n \n";
                class o extends HTMLElement {
                    constructor() {
                        var t;
                        super(), this.attachShadow({ mode: "open" }), null === (t = this.shadowRoot) || void 0 === t || t.appendChild(n.content.cloneNode(!0));
                    }
                    connectedCallback() {
                        var t;
                        (this.textHolder = null === (t = this.shadowRoot) || void 0 === t ? void 0 : t.querySelector("#text-holder")),
                            this.textHolder && ((this.textHolder.innerHTML = this.innerHTML || ""), this.textHolder.classList.add(this.Variant));
                    }
                    get Variant() {
                        return this.getAttribute("variant") || "p";
                    }
                }
                (e.Typography = o), customElements.define("aplazo-text", o);
            },
            818: (t, e) => {
                Object.defineProperty(e, "__esModule", { value: !0 }),
                    (e.UnExpectedError = e.UnauthorizeError = e.AplazoError = void 0),
                    (e.AplazoError = class {}),
                    (e.UnauthorizeError = { code: 403, msg: "unhautorized request" }),
                    (e.UnExpectedError = (t) => ({ code: 500, msg: "an unexpected error occurs", inner: t }));
            },
            711: function (t, e, n) {
                var o =
                    (this && this.__awaiter) ||
                    function (t, e, n, o) {
                        return new (n || (n = Promise))(function (i, r) {
                            function a(t) {
                                try {
                                    l(o.next(t));
                                } catch (t) {
                                    r(t);
                                }
                            }
                            function s(t) {
                                try {
                                    l(o.throw(t));
                                } catch (t) {
                                    r(t);
                                }
                            }
                            function l(t) {
                                var e;
                                t.done
                                    ? i(t.value)
                                    : ((e = t.value),
                                      e instanceof n
                                          ? e
                                          : new n(function (t) {
                                                t(e);
                                            })).then(a, s);
                            }
                            l((o = o.apply(t, e || [])).next());
                        });
                    };
                Object.defineProperty(e, "__esModule", { value: !0 }), (e.aplazoCreateLoan = e.authenticate = void 0);
                const i = n(818);
                (e.authenticate = function (t, e) {
                    return o(this, void 0, void 0, function* () {
                        try {
                            let n = new Headers();
                            n.append("Content-Type", "application/json");
                            let o = yield fetch("https://api.aplazo.dev/api/auth", { method: "POST", headers: n, body: JSON.stringify({ apiToken: t, merchantId: e }) });
                            const i = yield o.json();
                            return console.log(i), { data: i, error: null };
                        } catch (t) {
                            return console.log(t), { data: null, error: i.UnExpectedError(t) };
                        }
                    });
                }),
                    (e.aplazoCreateLoan = function (t, e) {
                        return o(this, void 0, void 0, function* () {
                            try {
                                let n = yield fetch("https://api.aplazo.dev/api/loan", { method: "POST", headers: { "Content-Type": "application/json", Authorization: e }, body: JSON.stringify(t) });
                                const o = yield n.json();
                                return (n.status >= 400 && n.status < 500) || (n.status > 499 && n.status < 600) ? { data: null, error: i.UnauthorizeError } : { data: o, error: null };
                            } catch (t) {
                                return console.log(t), { data: null, error: i.UnExpectedError(t) };
                            }
                        });
                    });
            },
            601: function (t, e, n) {
                var o =
                    (this && this.__awaiter) ||
                    function (t, e, n, o) {
                        return new (n || (n = Promise))(function (i, r) {
                            function a(t) {
                                try {
                                    l(o.next(t));
                                } catch (t) {
                                    r(t);
                                }
                            }
                            function s(t) {
                                try {
                                    l(o.throw(t));
                                } catch (t) {
                                    r(t);
                                }
                            }
                            function l(t) {
                                var e;
                                t.done
                                    ? i(t.value)
                                    : ((e = t.value),
                                      e instanceof n
                                          ? e
                                          : new n(function (t) {
                                                t(e);
                                            })).then(a, s);
                            }
                            l((o = o.apply(t, e || [])).next());
                        });
                    };
                Object.defineProperty(e, "__esModule", { value: !0 }), (e.CheckoutComponent = e.PAY_BUTTON_ATTRIBUTES_NAME = void 0);
                const i = n(711),
                    r = n(454);
                var a;
                !(function (t) {
                    (t.PRODUCT_PRICE = "product_price"),
                        (t.PRODUCT_NAME = "product_name"),
                        (t.PRODUCT_SKU = "product_sku"),
                        (t.PRODUCT_QUANTITY = "product_quantity"),
                        (t.SUCCESS_URL = "success_url"),
                        (t.ERROR_URL = "error_url"),
                        (t.WEBHOOK_URL = "webhook_url"),
                        (t.API_KEY = "API_KEY"),
                        (t.MERCHANT_ID = "MERCHANT_ID");
                })((a = e.PAY_BUTTON_ATTRIBUTES_NAME || (e.PAY_BUTTON_ATTRIBUTES_NAME = {}))),
                    (e.CheckoutComponent = class {
                        constructor(t) {
                            (this.ATTRIBUTES_NAME = a), (this.target = t);
                        }
                        init() {
                            (this._apikey = this.target.getAttribute(this.ATTRIBUTES_NAME.API_KEY)),
                                this._apikey
                                    ? ((this._merchantID = Number(this.target.getAttribute(this.ATTRIBUTES_NAME.MERCHANT_ID))),
                                      this._merchantID
                                          ? i
                                                .authenticate(this._apikey, this._merchantID)
                                                .then((t) => {
                                                    t.data && (this._token = t.data.Authorization);
                                                })
                                                .catch(console.log)
                                          : console.warn("missing MERCHANT_ID"))
                                    : console.warn("missing API_KEY");
                        }
                        submitProduct() {
                            return o(this, void 0, void 0, function* () {
                                try {
                                    let t = { title: this.ProductName, price: this.ProductPrice, count: this.ProductQuantity, sku: this.ProductSKU };
                                    const e = r.buildLoan({ products: [t], successUrl: this.SuccessURL, errorUrl: this.ErrorURL, webhookUrl: this.WebhookURL }),
                                        { data: n, error: o } = yield i.aplazoCreateLoan(e, this._token);
                                    if (o) return console.error(o);
                                    n && (console.log(n), window.open(n.url));
                                } catch (t) {
                                    console.log(t);
                                }
                            });
                        }
                        get ProductPrice() {
                            const t = this.target.getAttribute(this.ATTRIBUTES_NAME.PRODUCT_PRICE) || "";
                            return t ? parseFloat(t) : 0;
                        }
                        get ProductName() {
                            return this.target.getAttribute(this.ATTRIBUTES_NAME.PRODUCT_NAME) || "";
                        }
                        get ProductSKU() {
                            return this.target.getAttribute(this.ATTRIBUTES_NAME.PRODUCT_SKU) || "";
                        }
                        get ProductQuantity() {
                            const t = this.target.getAttribute(this.ATTRIBUTES_NAME.PRODUCT_QUANTITY) || "";
                            return t ? parseInt(t) : 0;
                        }
                        get SuccessURL() {
                            return this.target.getAttribute(this.ATTRIBUTES_NAME.SUCCESS_URL) || "";
                        }
                        get ErrorURL() {
                            return this.target.getAttribute(this.ATTRIBUTES_NAME.ERROR_URL) || "";
                        }
                        get WebhookURL() {
                            return this.target.getAttribute(this.ATTRIBUTES_NAME.WEBHOOK_URL) || "";
                        }
                    });
            },
            454: (t, e) => {
                Object.defineProperty(e, "__esModule", { value: !0 }),
                    (e.buildLoan = void 0),
                    (e.buildLoan = function (t) {
                        const { products: e, errorUrl: n, successUrl: o, webhookUrl: i } = t;
                        let r = 0;
                        for (const t of e) (r += t.price * t.count), (t.id = 1), (t.externalId = t.sku), (t.description = t.title), (t.imageUrl = t.imageUrl || "https://aplazoassets.s3-us-west-2.amazonaws.com/aplazo-logo-png-colores.png");
                        const a = 0.16 * r;
                        return {
                            shopId: 8,
                            cartId: new Date().getTime().toString(),
                            products: e,
                            discount: { title: "sin descuento", price: 0 },
                            taxes: { price: a, title: "IVA" },
                            shipping: { title: "Recoger en tienda", price: 0 },
                            totalPrice: parseFloat(`${r}`) + parseFloat(`${a}`),
                            successUrl: o || "localhost",
                            errorUrl: n || "localhost",
                            webHookUrl: i || "THIS WILL BE OVVERRIDED BY BACKEND",
                        };
                    });
            },
            982: (t, e) => {
                Object.defineProperty(e, "__esModule", { value: !0 }),
                    (e.PricingComponent = void 0),
                    (e.PricingComponent = class {
                        constructor(t) {
                            this.target = t;
                        }
                        trackElement(t) {
                            var e;
                            console.log(t);
                            let n = document.querySelector(t);
                            if (n) {
                                const t = null === (e = n.textContent) || void 0 === e ? void 0 : e.trim().replace("$", "").replace("MXN", "").replace(".", "").replace(",", "");
                                t && this.target.setAttribute("product-price", t),
                                    (window.onclick = (t) => {
                                        var e;
                                        if (!n) return;
                                        const o = null === (e = n.textContent) || void 0 === e ? void 0 : e.trim().replace("$", "").replace("MXN", "").replace(".", "").replace(",", "");
                                        o && this.target.setAttribute("product-price", o);
                                    });
                            }
                        }
                        init() {}
                    });
            },
            716: (t, e) => {
                Object.defineProperty(e, "__esModule", { value: !0 }),
                    (e.ThemeComponent = void 0),
                    (e.ThemeComponent = class {
                        constructor(t) {
                            this.target = t;
                        }
                        init() {
                            switch (this.target.getAttribute("theme")) {
                                case "dark":
                                    (this.target.style.color = "white"), (this.target.style.backgroundColor = "black");
                                    break;
                                case "light":
                                    (this.target.style.color = "black"), (this.target.style.backgroundColor = "white");
                            }
                        }
                    });
            },
            22: function (t, e, n) {
                var o =
                        (this && this.__createBinding) ||
                        (Object.create
                            ? function (t, e, n, o) {
                                  void 0 === o && (o = n),
                                      Object.defineProperty(t, o, {
                                          enumerable: !0,
                                          get: function () {
                                              return e[n];
                                          },
                                      });
                              }
                            : function (t, e, n, o) {
                                  void 0 === o && (o = n), (t[o] = e[n]);
                              }),
                    i =
                        (this && this.__exportStar) ||
                        function (t, e) {
                            for (var n in t) "default" === n || Object.prototype.hasOwnProperty.call(e, n) || o(e, t, n);
                        };
                Object.defineProperty(e, "__esModule", { value: !0 }), (e.AplazoInstall = void 0), i(n(893), e), i(n(214), e), i(n(141), e), i(n(914), e), i(n(951), e), i(n(907), e);
                class r extends HTMLElement {
                    constructor() {
                        super();
                    }
                    static get observedAttributes() {
                        return ["respawn-spot"];
                    }
                    attributeChangedCallback(t, e, n) {
                        switch (t) {
                            case "respawn-spot":
                                this.installWidgets(n);
                        }
                    }
                    connectedCallback() {
                        document.addEventListener("DOMContentLoaded", (t) => {
                            this.installWidgets(this.getAttribute("respawn-spot"));
                        });
                    }
                    installWidgets(t) {
                        t &&
                            t.split(",").forEach((t) => {
                                var e, n;
                                const [o, i] = t.split(":"),
                                    r = document.querySelector(o);
                                if (r) {
                                    if (null === (e = null == r ? void 0 : r.parentElement) || void 0 === e ? void 0 : e.querySelector("aplazo-placement")) return;
                                    let t = document.createElement("aplazo-placement");
                                    null === (n = null == r ? void 0 : r.parentElement) || void 0 === n || n.insertBefore(t, r), t.setAttribute("price-element-selector", i);
                                }
                            });
                    }
                }
                (e.AplazoInstall = r), customElements.define("aplazo-install", r);
            },
            141: function (t, e, n) {
                var o =
                        (this && this.__createBinding) ||
                        (Object.create
                            ? function (t, e, n, o) {
                                  void 0 === o && (o = n),
                                      Object.defineProperty(t, o, {
                                          enumerable: !0,
                                          get: function () {
                                              return e[n];
                                          },
                                      });
                              }
                            : function (t, e, n, o) {
                                  void 0 === o && (o = n), (t[o] = e[n]);
                              }),
                    i =
                        (this && this.__exportStar) ||
                        function (t, e) {
                            for (var n in t) "default" === n || Object.prototype.hasOwnProperty.call(e, n) || o(e, t, n);
                        };
                Object.defineProperty(e, "__esModule", { value: !0 }), i(n(159), e);
            },
            159: (t, e) => {
                Object.defineProperty(e, "__esModule", { value: !0 }), (e.AplazoInfoIcon = void 0);
                const n = document.createElement("template");
                n.innerHTML =
                    '\n    <style>\n    .info-trigger{\n            margin-left: 10px;\n            font-weight: 400;\n      font-size: 1.2rem;\n      }\n      .row{\n        flex:1\n      }\n      /* Modal Content */\n.modal-content {\n  background-color: none;\n  margin: auto;\n   padding-top: 20px; \n\n  width: 37rem;\n  height: 27rem;\n}\n\n/* The Close Button */\n.close {\n  color: #000;\n  float: right;\n  font-size: 28px;\n  font-weight: bold;\n  padding-right: 10px;\n}\n\n.close:hover,\n.close:focus {\n  color: #000;\n  text-decoration: none;\n  cursor: pointer;\n}\n    \n      @media only screen and (max-width: 420px) {\n            .info-trigger{\n            padding-right: 10px;\n            }\n            aplazo-logo{\n                padding-left: 6px;\n            }\n          .xs-visible {\n            display: block !important;\n            img {\n              width: 100%;\n            }\n          }\n          .modal-content{\n           width: 80%;\n           height: 100%;\n              padding-top: 5px; \n          }\n          .banner {\n            padding:0px;\n            font-size:0.7rem;\n          }\n          \n          .mid-visible{\n            display: none !important;;\n          }\n       }\n       @media only screen and (min-width: 420px) {\n          .xs-visible {\n            display: none !important;\n          }\n    \n          .mid-visible{\n            display: block !important;;\n          }\n       }\n\n    </style>\n       <aplazo-modal>\n            <div slot="trigger"> \n                <slot name="info-trigger"></slot>\n            </div>\n    \n              <div slot="content" class="modal-content">\n                <div class="mid-visible" style="\n                    width: 100%;\n                    height: 100%;\n                    background-repeat: round;\n                    background-size: 100% 100%;\n                    background-image: url(\'https://cdn.aplazo.mx/aplazo-description.png\');"\n                ><span class="close" slot="close">&times</span>\n                </div>\n                \n                <div class="xs-visible" \n                style="\n                    width: 100%;\n                    height: 93%;\n                    background-repeat:round;\n                    background-size: 100% 100%;\n                    background-image: url(\' https://cdn.aplazo.mx/aplazo-desc-movil.png\');">\n                   <span class="close" slot="close">&times</span>\n                  </div>\n              </div>\n            \n        </aplazo-modal>\n    ';
                class o extends HTMLElement {
                    constructor() {
                        var t;
                        super(), this.attachShadow({ mode: "open" }), null === (t = this.shadowRoot) || void 0 === t || t.appendChild(n.content.cloneNode(!0));
                    }
                    connectedCallback() {}
                }
                (e.AplazoInfoIcon = o), customElements.define("aplazo-info-icon", o);
            },
            409: (t, e, n) => {
                Object.defineProperty(e, "__esModule", { value: !0 }), (e.AplazoBannerElement = void 0);
                const o = n(468),
                    i = n(716);
                class r extends HTMLElement {
                    constructor() {
                        var t;
                        super(), this.attachShadow({ mode: "open" }), null === (t = this.shadowRoot) || void 0 === t || t.appendChild(o.template.content.cloneNode(!0)), (this.themeComponent = new i.ThemeComponent(this));
                    }
                    connectedCallback() {
                        var t, e, n, o;
                        this._textElement = null === (t = this.shadowRoot) || void 0 === t ? void 0 : t.querySelector("#info-text");
                        const i = null === (e = this.shadowRoot) || void 0 === e ? void 0 : e.querySelector("aplazo-logo");
                        null == i || i.setAttribute("theme", this.Theme),
                            null == i || i.setAttribute("logo-size", "27"),
                            this.themeComponent.init(),
                            Number(this.offsetWidth) < 300 && this._textElement && (this._textElement.textContent = "Paga en cuotas!"),
                            null !== this.getAttribute("sticky") && ((this.style.position = "fixed"), (this.style.justifyContent = "center"), (this.style.top = "0px")),
                            console.log(this.offsetWidth),
                            null === (o = null === (n = this.shadowRoot) || void 0 === n ? void 0 : n.querySelector(".container")) || void 0 === o || o.addEventListener("click", () => {});
                    }
                    get Theme() {
                        return this.getAttribute("theme") || "light";
                    }
                }
                (e.AplazoBannerElement = r), customElements.define("aplazo-banner", r);
            },
            468: (t, e) => {
                Object.defineProperty(e, "__esModule", { value: !0 }),
                    (e.template = void 0),
                    (e.template = document.createElement("template")),
                    (e.template.innerHTML =
                        '\n   <style>\n   :host{\n    width: 100%;\n    display: inline;\n\n    align-items: center;\n    \n   }\n      .info-trigger{\n      font-size: 12px;\n      border-bottom: 1px solid;\n      font-weight: bold;\n   }\n      \n    </style>\n<aplazo-info-icon style="display: inline-table">\n          <a class="info-trigger"    slot="info-trigger">Conoce más.</a>\n\n      </aplazo-info-icon>  \n\n');
            },
            951: function (t, e, n) {
                var o =
                        (this && this.__createBinding) ||
                        (Object.create
                            ? function (t, e, n, o) {
                                  void 0 === o && (o = n),
                                      Object.defineProperty(t, o, {
                                          enumerable: !0,
                                          get: function () {
                                              return e[n];
                                          },
                                      });
                              }
                            : function (t, e, n, o) {
                                  void 0 === o && (o = n), (t[o] = e[n]);
                              }),
                    i =
                        (this && this.__exportStar) ||
                        function (t, e) {
                            for (var n in t) "default" === n || Object.prototype.hasOwnProperty.call(e, n) || o(e, t, n);
                        };
                Object.defineProperty(e, "__esModule", { value: !0 }), i(n(409), e);
            },
            169: (t, e) => {
                Object.defineProperty(e, "__esModule", { value: !0 }),
                    (e.template = void 0),
                    (e.template = document.createElement("template")),
                    (e.template.innerHTML =
                        '\n   <style>\n   :host{\n       display: flex;\n       height: 50px;\n       font-family: inherit;\n       width: 100%;\n       align-items: center;\n   }\n   .container{\n      display: inherit;\n      height:inherit;\n      align-items: inherit;\n      padding: 0px 10px 0px 10px;\n      width: inherit;\n      color: inherit;\n      background:inherit;\n      font-family: inherit;\n   }\n\n   .container{\n        cursor: pointer;\n   }\n\n    </style>\n   \n    <button class="container">\n         <aplazo-info-icon ></aplazo-info-icon>\n         <span style="width: 100%; ">\n\n           Paga en cuotas con \n        </span>\n        <aplazo-logo ></aplazo-logo>\n    </button>\n');
            },
            728: (t, e, n) => {
                Object.defineProperty(e, "__esModule", { value: !0 }), (e.AplazoPayButton = void 0);
                const o = n(169),
                    i = n(601),
                    r = n(716);
                class a extends HTMLElement {
                    constructor() {
                        var t;
                        super(),
                            this.attachShadow({ mode: "open" }),
                            null === (t = this.shadowRoot) || void 0 === t || t.appendChild(o.template.content.cloneNode(!0)),
                            (this.checkoutComponent = new i.CheckoutComponent(this)),
                            (this.themeComponent = new r.ThemeComponent(this));
                    }
                    connectedCallback() {
                        var t, e, n;
                        this.checkoutComponent.init();
                        const o = this.getAttribute("theme") || "light",
                            i = null === (t = this.shadowRoot) || void 0 === t ? void 0 : t.querySelector("aplazo-logo");
                        null == i || i.setAttribute("theme", o),
                            this.themeComponent.init(),
                            null === (n = null === (e = this.shadowRoot) || void 0 === e ? void 0 : e.querySelector(".container")) ||
                                void 0 === n ||
                                n.addEventListener("click", () => {
                                    this.checkoutComponent.submitProduct();
                                });
                    }
                }
                (e.AplazoPayButton = a), customElements.define("aplazo-pay-button", a);
            },
            237: function (t, e, n) {
                var o =
                        (this && this.__createBinding) ||
                        (Object.create
                            ? function (t, e, n, o) {
                                  void 0 === o && (o = n),
                                      Object.defineProperty(t, o, {
                                          enumerable: !0,
                                          get: function () {
                                              return e[n];
                                          },
                                      });
                              }
                            : function (t, e, n, o) {
                                  void 0 === o && (o = n), (t[o] = e[n]);
                              }),
                    i =
                        (this && this.__exportStar) ||
                        function (t, e) {
                            for (var n in t) "default" === n || Object.prototype.hasOwnProperty.call(e, n) || o(e, t, n);
                        };
                Object.defineProperty(e, "__esModule", { value: !0 }), i(n(728), e), i(n(237), e);
            },
            174: (t, e, n) => {
                Object.defineProperty(e, "__esModule", { value: !0 }), (e.AplazoPlacementElement = void 0);
                const o = n(460),
                    i = n(982);
                class r extends HTMLElement {
                    constructor() {
                        var t;
                        super(), this.attachShadow({ mode: "open" }), null === (t = this.shadowRoot) || void 0 === t || t.appendChild(o.template.content.cloneNode(!0)), (this.pricingComponent = new i.PricingComponent(this));
                    }
                    connectedCallback() {
                        var t, e, n;
                        this.updateQuotes(), this.updateLogoSize();
                        const o = null === (t = this.shadowRoot) || void 0 === t ? void 0 : t.querySelector(".quotes-amount");
                        if (!o) return;
                        o.setAttribute("style", this.QuoteStyle);
                        const i = null === (e = this.shadowRoot) || void 0 === e ? void 0 : e.querySelector(".info-trigger");
                        if (!i) return;
                        i.setAttribute("style", this.InfoElementStyle);
                        const r = null === (n = this.shadowRoot) || void 0 === n ? void 0 : n.querySelector("aplazo-logo");
                        r && (r.setAttribute("style", this.LogoStyle), this.setAttribute("style", this.MainStyle));
                    }
                    static get observedAttributes() {
                        return ["product-price", "price-element-selector"];
                    }
                    attributeChangedCallback(t, e, n) {
                        switch (t) {
                            case "product-price":
                                this.updateQuotes();
                                break;
                            case "price-element-selector":
                                this.ProductPrice || this.pricingComponent.trackElement(n);
                        }
                    }
                    get ProductPrice() {
                        return Number(this.getAttribute("product-price")) / 100;
                    }
                    get LogoSize() {
                        return Number(this.getAttribute("aplazo-logo-size")) || 30;
                    }
                    get LogoStyle() {
                        return this.getAttribute("logo-style") || "";
                    }
                    get QuoteStyle() {
                        return this.getAttribute("quote-style") || "";
                    }
                    get InfoElementStyle() {
                        return this.getAttribute("info-style") || "";
                    }
                    get MainStyle() {
                        return this.getAttribute("main-style") || "";
                    }
                    updateLogoSize() {
                        var t;
                        const e = null === (t = this.shadowRoot) || void 0 === t ? void 0 : t.querySelector("aplazo-logo");
                        e && e.setAttribute("logo-size", String(this.LogoSize));
                    }
                    updateQuotes() {
                        var t;
                        const e = this.ProductPrice;
                        if (!e) return;
                        const n = null === (t = this.shadowRoot) || void 0 === t ? void 0 : t.querySelector("#price-slot");
                        n && (n.textContent = `desde $ ${parseFloat("" + e / 5).toFixed(2)}`);
                    }
                }
                (e.AplazoPlacementElement = r), customElements.define("aplazo-placement", r);
            },
            460: (t, e) => {
                Object.defineProperty(e, "__esModule", { value: !0 }),
                    (e.template = void 0),
                    (e.template = document.createElement("template")),
                    (e.template.innerHTML =
                        '\n   <style>\n   :host{\n      display: inlibe;\n      font-family: inherit;\n      color: inherit;\n      padding-left: 10px;\n   }\n   .info-trigger{\n      font-size: 12px;\n      border-bottom: 1px solid;\n      font-weight: bold;\n   }\n      \n   .quotes-amount{\n     font-weight: bold;\n   }\n   </style><aplazo-info-icon style="display: inline-table">\n        <a class="info-trigger"    slot="info-trigger">Conoce más.</a>\n      </aplazo-info-icon>  \n      \n \n');
            },
            914: function (t, e, n) {
                var o =
                        (this && this.__createBinding) ||
                        (Object.create
                            ? function (t, e, n, o) {
                                  void 0 === o && (o = n),
                                      Object.defineProperty(t, o, {
                                          enumerable: !0,
                                          get: function () {
                                              return e[n];
                                          },
                                      });
                              }
                            : function (t, e, n, o) {
                                  void 0 === o && (o = n), (t[o] = e[n]);
                              }),
                    i =
                        (this && this.__exportStar) ||
                        function (t, e) {
                            for (var n in t) "default" === n || Object.prototype.hasOwnProperty.call(e, n) || o(e, t, n);
                        };
                Object.defineProperty(e, "__esModule", { value: !0 }), i(n(174), e), i(n(914), e);
            },
            907: function (t, e, n) {
                var o =
                        (this && this.__createBinding) ||
                        (Object.create
                            ? function (t, e, n, o) {
                                  void 0 === o && (o = n),
                                      Object.defineProperty(t, o, {
                                          enumerable: !0,
                                          get: function () {
                                              return e[n];
                                          },
                                      });
                              }
                            : function (t, e, n, o) {
                                  void 0 === o && (o = n), (t[o] = e[n]);
                              }),
                    i =
                        (this && this.__exportStar) ||
                        function (t, e) {
                            for (var n in t) "default" === n || Object.prototype.hasOwnProperty.call(e, n) || o(e, t, n);
                        };
                Object.defineProperty(e, "__esModule", { value: !0 }), i(n(237), e);
            },
            893: function (t, e, n) {
                var o =
                        (this && this.__createBinding) ||
                        (Object.create
                            ? function (t, e, n, o) {
                                  void 0 === o && (o = n),
                                      Object.defineProperty(t, o, {
                                          enumerable: !0,
                                          get: function () {
                                              return e[n];
                                          },
                                      });
                              }
                            : function (t, e, n, o) {
                                  void 0 === o && (o = n), (t[o] = e[n]);
                              }),
                    i =
                        (this && this.__exportStar) ||
                        function (t, e) {
                            for (var n in t) "default" === n || Object.prototype.hasOwnProperty.call(e, n) || o(e, t, n);
                        };
                Object.defineProperty(e, "__esModule", { value: !0 }), i(n(983), e);
            },
            983: function (t, e, n) {
                var o =
                        (this && this.__createBinding) ||
                        (Object.create
                            ? function (t, e, n, o) {
                                  void 0 === o && (o = n),
                                      Object.defineProperty(t, o, {
                                          enumerable: !0,
                                          get: function () {
                                              return e[n];
                                          },
                                      });
                              }
                            : function (t, e, n, o) {
                                  void 0 === o && (o = n), (t[o] = e[n]);
                              }),
                    i =
                        (this && this.__exportStar) ||
                        function (t, e) {
                            for (var n in t) "default" === n || Object.prototype.hasOwnProperty.call(e, n) || o(e, t, n);
                        };
                Object.defineProperty(e, "__esModule", { value: !0 }), i(n(129), e);
            },
            129: (t, e, n) => {
                Object.defineProperty(e, "__esModule", { value: !0 }), (e.ModalElement = void 0);
                const o = n(777);
                class i extends HTMLElement {
                    constructor() {
                        var t;
                        super(), this.attachShadow({ mode: "open" }), null === (t = this.shadowRoot) || void 0 === t || t.appendChild(o.template.content.cloneNode(!0));
                    }
                    connectedCallback() {
                        var t, e, n, o, i, r, a, s;
                        null === (e = null === (t = this.shadowRoot) || void 0 === t ? void 0 : t.querySelector(".trigger-container")) ||
                            void 0 === e ||
                            e.addEventListener("click", (t) => {
                                t.stopPropagation(), t.preventDefault(), this.toggleModal(!0);
                            }),
                            null === (o = null === (n = this.shadowRoot) || void 0 === n ? void 0 : n.querySelector(".modal")) ||
                                void 0 === o ||
                                o.addEventListener("click", (t) => {
                                    t.stopPropagation(), t.preventDefault(), this.toggleModal(!1);
                                }),
                            null === (r = null === (i = this.shadowRoot) || void 0 === i ? void 0 : i.querySelector(".content-container")) ||
                                void 0 === r ||
                                r.addEventListener("click", (t) => {
                                    t.stopPropagation(), t.preventDefault();
                                }),
                            null === (s = null === (a = this.shadowRoot) || void 0 === a ? void 0 : a.querySelector("[slot=close]")) ||
                                void 0 === s ||
                                s.addEventListener("click", (t) => {
                                    t.stopPropagation(), t.preventDefault(), this.toggleModal(!1);
                                });
                    }
                    toggleModal(t) {
                        var e;
                        this.modalRef || (this.modalRef = null === (e = this.shadowRoot) || void 0 === e ? void 0 : e.querySelector("#modal")), (this.modalRef.style.display = t ? "block" : "none"), document.body.appendChild(this.modalRef);
                    }
                }
                (e.ModalElement = i), customElements.define("aplazo-modal", i);
            },
            777: (t, e) => {
                Object.defineProperty(e, "__esModule", { value: !0 }),
                    (e.template = void 0),
                    (e.template = document.createElement("template")),
                    (e.template.innerHTML =
                        '\n    <style>\n        \n        .trigger-container{\n            cursor: pointer;\n        }\n        .content-container{\n            width: 100%;\n            height: 100%;\n            display: flex;\n            align-items: center;\n            justify-content: center;\n            background-color: none;\n        }\n  \n     \n        \n    </style>\n    <div> \n       <div class="trigger-container">\n        <slot name="trigger"></slot>\n       </div>\n       <div id="modal" style=" \n          display: none;\n          position: fixed;\n          z-index: 999999999999;\n          padding-top: 40px;\n          left: 0;\n          top: 0;\n          width: 100%; \n          height: 100%; \n          overflow: auto;\n          background-color: rgb(0,0,0,0.1);\n          ">\n       \n          <div style=" \n           flex-direction: column;\n          display: flex;\n          width: 75%;\n          max-width: 500px;\n          margin: auto;\n          border-radius: 8px;\n          padding: 35px ;\n          background: white"> \n       <div style="display: flex; justify-content: space-between;padding-bottom: 10px">\n       <aplazo-logo style="right: 10px;position: relative" logo-size="40"></aplazo-logo>\n             <span class="close"\n                style=" \n                    cursor: pointer;\n                    align-self: center;\n                    color: #131332;;\n                    font-size: 17px;"\n                slot="close"><aplazo-icon></aplazo-icon> &times</span>\n            </div>\n                \x3c!--body--\x3e\n                <div>\n                \n                 <aplazo-text  variant="big"> Compra ahora. Paga a plazos. Sin tarjeta de crédito.</aplazo-text>\n               \n         <aplazo-text  variant="light-title">\n         Ahora puedes tener lo que quieras, cuando quieras. Compra ahora y paga en 5 plazos quincenales.\n         </aplazo-text>\n            \n               \n                \x3c!--icon--\x3e\n                  <instruction-card \n                    step-img="https://aplazoassets.s3.us-west-2.amazonaws.com/step-1.png"\n                    step-title="LLENA TU CARRITO" \n                    step-description="Agrega los productos a tu carrito de compra." >    \n                  </instruction-card>  \n                  \n                  <instruction-card\n                    step-img="https://aplazoassets.s3.us-west-2.amazonaws.com/step-2.png" \n                    step-title="ELIGE APLAZO AL CHECKOUT" \n                    step-description="Crea tu cuenta y agrega tu tarjeta de crédito o débito." >    \n                  </instruction-card> \n                       \n                  <instruction-card\n                    step-img="https://aplazoassets.s3.us-west-2.amazonaws.com/step-3.png" \n                    step-title="PAGA EL 20% Y LLÉVATELO HOY" \n                    step-description="Paga en 5 plazos quincenales, sin tarjeta de crédito." >    \n                  </instruction-card>\n                    <aplazo-text style="text-align: center"  variant="soft-p">\n                  Para registrarte, es necesario contar con una tarjeta de débito o crédito, tu INE, y un número celular mexicano. Sujeto a la aprobación de crédito. Aplican términos y condiciones.  Visita <a target="_blank" style="color: #B0BEC5;" href="https://aplazo.mx"> www.aplazo.mx </a> para más información. \n               </aplazo-text>\n                </div> \n              </div>\n              </div>\n        </div>\n    </div>\n');
            },
        },
        e = {};
    !(function n(o) {
        var i = e[o];
        if (void 0 !== i) return i.exports;
        var r = (e[o] = { exports: {} });
        return t[o].call(r.exports, r, r.exports, n), r.exports;
    })(22);
})();
