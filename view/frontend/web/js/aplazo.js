(()=>{"use strict";var t={107:(t,e)=>{Object.defineProperty(e,"__esModule",{value:!0}),e.AplazoLogo=void 0;const n=document.createElement("template");n.innerHTML='\n    <style>\n    :host{\n    display: inline-flex;\n    }\n          @media only screen and (max-width: 420px) {\n            img {\n              height: 25px !important;\n                }\n           }\n    img {\n        height:30px;\n        width: 100px;\n        position:relative;\n        top: 7px;\n        border-radius: 25px;    \n    }\n    </style>\n     <img slot="trigger" id="logo-image"  src="https://cdn.aplazo.com.mx/logo-color.svg" alt="">\n';class o extends HTMLElement{constructor(){var t;super(),this.attachShadow({mode:"open"}),null===(t=this.shadowRoot)||void 0===t||t.appendChild(n.content.cloneNode(!0))}connectedCallback(){var t;const e=this.getAttribute("logo-size"),n=this.getAttribute("theme")||"light",o=null===(t=this.shadowRoot)||void 0===t?void 0:t.getElementById("logo-image");if(!o)return;"dark"===n&&o.setAttribute("src","https://cdn.aplazo.mx/logo-black.svg");const i=e||(window.innerWidth,"25");o.style.height=i+"px"}static get observedAttributes(){return["theme"]}attributeChangedCallback(t,e,n){var o;switch(t){case"theme":const t=null===(o=this.shadowRoot)||void 0===o?void 0:o.getElementById("logo-image");if(!t)return;"dark"===n&&t.setAttribute("src","https://cdn.aplazo.mx/logo-black.svg")}}}e.AplazoLogo=o,customElements.get("aplazo-logo")||customElements.define("aplazo-logo",o)},278:(t,e)=>{Object.defineProperty(e,"__esModule",{value:!0}),e.AplazoButton=void 0;const n=document.createElement("template");n.innerHTML='\n    <style>\n    \n    div{\n    color:white\n    }\n\n    button {\n    margin-top: 30px;\n        width: 172px;\n        height: 44px;\n        background: #3D72C9;\n        border-radius: 8px;\n        border: none;\nfont-size: 14px;\nfont-style: normal;\nfont-weight: 700;\nline-height: 18px;\nletter-spacing: 0.5px;\ntext-align: center;\n\n    }\n    \n    button:hover {\n        cursor: pointer;\n        background: #345ea4 ;\n    }\n    </style>\n    <button id="action-btn"> <div id="text-holder"></div> </button>\n';class o extends HTMLElement{constructor(){var t;super(),this.attachShadow({mode:"open"}),null===(t=this.shadowRoot)||void 0===t||t.appendChild(n.content.cloneNode(!0))}connectedCallback(){var t,e,n;this.textHolder=null===(t=this.shadowRoot)||void 0===t?void 0:t.querySelector("#text-holder"),this.textHolder&&(this.textHolder.innerHTML=this.innerHTML||""),null===(n=null===(e=this.shadowRoot)||void 0===e?void 0:e.querySelector("#action-btn"))||void 0===n||n.addEventListener("click",(t=>{t.stopPropagation(),t.preventDefault(),window.open("https://customer.aplazo.mx/register/credentials","_blank")}))}}e.AplazoButton=o,customElements.get("aplazo-button")||customElements.define("aplazo-button",o)},697:(t,e)=>{Object.defineProperty(e,"__esModule",{value:!0}),e.Icon=void 0;const n=document.createElement("template");n.innerHTML="\n    <script>\n  \n    <\/script>\n   <span >\n   \n   </span>\n \n";class o extends HTMLElement{constructor(){var t;super(),this.attachShadow({mode:"open"}),null===(t=this.shadowRoot)||void 0===t||t.appendChild(n.content.cloneNode(!0))}}e.Icon=o,customElements.get("ap-icon")||customElements.define("ap-icon",o)},214:(t,e,n)=>{Object.defineProperty(e,"__esModule",{value:!0}),e.Typography=e.AplazoButton=e.StepCard=e.InstructionCard=e.Icon=e.AplazoLogo=void 0;var o=n(107);Object.defineProperty(e,"AplazoLogo",{enumerable:!0,get:function(){return o.AplazoLogo}});var i=n(697);Object.defineProperty(e,"Icon",{enumerable:!0,get:function(){return i.Icon}});var r=n(445);Object.defineProperty(e,"InstructionCard",{enumerable:!0,get:function(){return r.InstructionCard}});var s=n(796);Object.defineProperty(e,"StepCard",{enumerable:!0,get:function(){return s.StepCard}});var a=n(278);Object.defineProperty(e,"AplazoButton",{enumerable:!0,get:function(){return a.AplazoButton}});var l=n(324);Object.defineProperty(e,"Typography",{enumerable:!0,get:function(){return l.Typography}})},445:(t,e)=>{Object.defineProperty(e,"__esModule",{value:!0}),e.InstructionCard=void 0;const n=document.createElement("template");n.innerHTML='\n                <style>\n                    .info{\n                        justify-content: center;\n                        display: flex;\n                        flex-direction: column;\n                        padding-left: 22px;\n                     }                   \n                </style>\n                <div style="display: flex;align-content: center;margin: 14px 0px 14px 0px">\n                    \x3c!--logo for step--\x3e\n                    <div>\n                      <img id="step-img"  alt="">\n                    </div>\n                     \x3c!--info--\x3e\n                    <div class="info">\n                       <aplazo-text id="step-title" variant="title"></aplazo-text>\n                       <aplazo-text id="step-description" variant="p"></aplazo-text>\n                    </div>\n                </div> \n \n';class o extends HTMLElement{constructor(){var t;super(),this.attachShadow({mode:"open"}),null===(t=this.shadowRoot)||void 0===t||t.appendChild(n.content.cloneNode(!0))}connectedCallback(){var t,e,n;const o=null===(t=this.shadowRoot)||void 0===t?void 0:t.querySelector("#step-title");o&&(o.textContent=this.StepTitle||"");const i=null===(e=this.shadowRoot)||void 0===e?void 0:e.querySelector("#step-description");i&&(i.textContent=this.StepDescription||"");const r=null===(n=this.shadowRoot)||void 0===n?void 0:n.querySelector("#step-img");r&&(r.src=this.StepImg||"")}get StepTitle(){return this.getAttribute("step-title")||""}get StepDescription(){return this.getAttribute("step-description")||""}get StepImg(){return this.getAttribute("step-img")||""}}e.InstructionCard=o,customElements.get("instruction-card")||customElements.define("instruction-card",o)},796:(t,e)=>{Object.defineProperty(e,"__esModule",{value:!0}),e.StepCard=void 0;const n=document.createElement("template");n.innerHTML='\n                <style>\n                        .image-lg {\n            display: block;\n}\n\n            @media (max-width: 800px) {\n                    .image-lg {\n                       display: none;\n                       }\n                }\n                    :host{\n                      width: 230px;\n                      padding-left: 10px;\n                      padding-right: 10px;\n                    }\n                    .info{\n                        justify-content: center;\n                        display: flex;\n                        flex-direction: column;\n                        padding-left: 22px;\n                     }                   \n                </style>\n                <div style="align-content: center;margin: 14px 0px 14px 0px">\n                    \x3c!--logo for step--\x3e\n                    <div class="image-lg">\n                      <img id="step-img"  alt="">\n                    </div>\n                     \x3c!--info--\x3e\n                     <div style="display: flex">\n                        <aplazo-text id="step-number" variant="title"></aplazo-text>\n                        <div class="info">\n                            <aplazo-text id="step-title" variant="title"></aplazo-text>\n                            <aplazo-text id="step-description" variant="p"></aplazo-text>\n                        </div>\n                    </div>\n                </div> \n \n';class o extends HTMLElement{constructor(){var t;super(),this.attachShadow({mode:"open"}),null===(t=this.shadowRoot)||void 0===t||t.appendChild(n.content.cloneNode(!0))}connectedCallback(){var t,e,n,o;const i=null===(t=this.shadowRoot)||void 0===t?void 0:t.querySelector("#step-title");i&&(i.textContent=this.StepTitle);const r=null===(e=this.shadowRoot)||void 0===e?void 0:e.querySelector("#step-description");r&&(r.textContent=this.StepDescription);const s=null===(n=this.shadowRoot)||void 0===n?void 0:n.querySelector("#step-img");s&&(s.src=this.StepImg);const a=null===(o=this.shadowRoot)||void 0===o?void 0:o.querySelector("#step-number");a&&(a.textContent=this.StepNumber)}get StepTitle(){return this.getAttribute("step-title")||""}get StepDescription(){return this.getAttribute("step-description")||""}get StepImg(){return this.getAttribute("step-img")||""}get StepNumber(){return this.getAttribute("step-number")||""}}e.StepCard=o,customElements.get("step-card")||customElements.define("step-card",o)},324:(t,e)=>{Object.defineProperty(e,"__esModule",{value:!0}),e.Typography=void 0;const n=document.createElement("template");n.innerHTML="\n    <style>\n\n    @import url('https://fonts.googleapis.com/css2?family=Manrope:wght@200;700&display=swap');\n\n    :host {\n        font-family: 'Manrope', sans-serif;\n        font-style: normal;\n        letter-spacing: 0px;\n        text-align: left;\n        color: #000000;\n    }\n    div {\n        text-align: inherit;\n    }\n    .p{\n        font-size: 14px;\n        font-weight: 400;\n        line-height: 19.8px;\n        color: #37474F;\n    }\n    \n    .light-gray-title{\n        font-size: 10px;\n        font-style: normal;\n        font-weight: 700;\n        line-height: 12px;\n        letter-spacing: 0.3275538980960846px;\n        color:  #78909C;\n;\n    }\n    .title {\n       font-size: 15px;\n       font-weight: 700;\n       line-height: 23px;\n       letter-spacing: 0em;\n       text-align: left;\n    }\n       .title-small {\nfont-size: 20px;\nfont-style: normal;\nfont-weight: 700;\nline-height: 28px;\nletter-spacing: 0em;\ntext-align: left;\n\n    }\n    .light-title {\n        font-size: 16px;\n        font-weight: 400;\n        line-height: 28px;\n    }\n    .big {\n        font-weight: bold;\n        font-size: 28px;\n        line-height: 42px;\n    }\n    .soft-p {\n        color: #B0BEC5;;\n        font-size: 12px;\n        line-height: 20px;\n    }\n    \n    </style>\n    <div id=\"text-holder\"></div>    \n \n";class o extends HTMLElement{constructor(){var t;super(),this.attachShadow({mode:"open"}),null===(t=this.shadowRoot)||void 0===t||t.appendChild(n.content.cloneNode(!0))}connectedCallback(){var t;this.textHolder=null===(t=this.shadowRoot)||void 0===t?void 0:t.querySelector("#text-holder"),this.textHolder&&(this.textHolder.innerHTML=this.innerHTML||"",this.textHolder.classList.add(this.Variant))}get Variant(){return this.getAttribute("variant")||"p"}}e.Typography=o,customElements.get("aplazo-text")||customElements.define("aplazo-text",o)},740:(t,e)=>{Object.defineProperty(e,"__esModule",{value:!0}),e.LoggerComponent=void 0,e.LoggerComponent=function(t){Object.getOwnPropertyNames(t.prototype).filter((t=>!(t[0]===t[0].toUpperCase()))).forEach((e=>{const n=t.prototype[e];t.prototype[e]=function(...o){const i=window.location.search.includes("_APLAZO_DEBUG_");i&&(console.log(`[${t.name}][${e}] inputs`),console.log({inputs:o}));const r=n.apply(this,o);return i&&(console.log(`[${t.name}][${e}] output `),console.log({output:r})),r}}))}},982:(t,e)=>{Object.defineProperty(e,"__esModule",{value:!0}),e.PricingComponent=void 0,e.PricingComponent=class{constructor(t){this.target=t}trackElement(t,e=null){var n;if(this.target.setAttribute("product-price",""),!t)return;const o=e?`${t}:not(${e})`:t;let i=document.querySelector(o);if(i){const t=null===(n=i.textContent)||void 0===n?void 0:n.trim().replace("$","").replace("MXN","").replace("mxn","").replace(".","").replace(",","");t&&this.target.setAttribute("product-price",t),window.onclick=t=>{var e;if(!i)return;const n=null===(e=i.textContent)||void 0===e?void 0:e.trim().replace("$","").replace("MXN","").replace(".","").replace(",","");n&&this.target.setAttribute("product-price",n)}}else this.target.setAttribute("product-price","")}init(){}}},622:(t,e)=>{Object.defineProperty(e,"__esModule",{value:!0}),e.SpawnerComponent=void 0,e.SpawnerComponent=class{constructor(t){this.target=t}init(){}updatePosition(t){if(this.positionSettings=t,window.innerWidth<520){const t=this.insetElement(this.positionSettings.xsSelector);"no-found"!==t&&"invalid-selector"!==t||this.insetElement(this.positionSettings.defaultSelector)}else this.insetElement(this.positionSettings.defaultSelector)}insetElement(t){var e;if(""===t)return"invalid-selector";const n=document.querySelector(t);return n?n.hasAttribute("aplazo-banner-attached")?"already-attached":(this.previousSpot&&this.previousSpot.removeAttribute("aplazo-banner-attached"),null===(e=null==n?void 0:n.parentElement)||void 0===e||e.insertBefore(this.target,n),n.setAttribute("aplazo-banner-attached","true"),this.previousSpot=n,console.log(`widget attached to [${n}]`),"inserted"):(console.warn(`unable to find ${t}`),"no-found")}}},22:function(t,e,n){var o=this&&this.__createBinding||(Object.create?function(t,e,n,o){void 0===o&&(o=n),Object.defineProperty(t,o,{enumerable:!0,get:function(){return e[n]}})}:function(t,e,n,o){void 0===o&&(o=n),t[o]=e[n]}),i=this&&this.__decorate||function(t,e,n,o){var i,r=arguments.length,s=r<3?e:null===o?o=Object.getOwnPropertyDescriptor(e,n):o;if("object"==typeof Reflect&&"function"==typeof Reflect.decorate)s=Reflect.decorate(t,e,n,o);else for(var a=t.length-1;a>=0;a--)(i=t[a])&&(s=(r<3?i(s):r>3?i(e,n,s):i(e,n))||s);return r>3&&s&&Object.defineProperty(e,n,s),s},r=this&&this.__exportStar||function(t,e){for(var n in t)"default"===n||Object.prototype.hasOwnProperty.call(e,n)||o(e,t,n)};Object.defineProperty(e,"__esModule",{value:!0}),e.AplazoInstall=void 0;const s=n(740);r(n(86),e),r(n(893),e),r(n(214),e),r(n(141),e),r(n(914),e);let a=class extends HTMLElement{constructor(){super(),window._APLAZO_INSTALL_=this.installWidgets.bind(this),window._APLAZO_MANUAL_INSERT_=this.insertBannerOnElement.bind(this)}static get observedAttributes(){return["respawn-spot","ignore","theme","multi-spawn"]}attributeChangedCallback(t,e,n){switch(t){case"ignore":case"respawn-spot":case"multi-spawn":case"theme":this.installWidgets()}}connectedCallback(){document.addEventListener("DOMContentLoaded",(t=>this.installWidgets()))}installWidgets(){const t=this.getAttribute("respawn-spot"),e=this.getAttribute("xs-respawn-spot")||"",n=this.getAttribute("ignore"),o=null!==this.getAttribute("multi-spawn"),i=this.getAttribute("theme")||"light",r=this.getAttribute("merchant-id")||"",s=this.getAttribute("integration-type")||"",a=this.getAttribute("env")||"";if(!t)return;const l=t.split(",");e.split(","),l.forEach(((t,e)=>{const[l,c]=t.split(":");if(o){const t=document.querySelectorAll(l);document.querySelectorAll(c).forEach(((t,e)=>t.setAttribute("aplazo-banner-price",`${e+1}`))),t.forEach(((t,e)=>{t.setAttribute("aplazo-banner-position",`${e+1}`),this.insertBannerOnElement(`[aplazo-banner-position="${e+1}"]`,`[aplazo-banner-price="${e+1}"]`,i,n,r,s,a)}))}else this.insertBannerOnElement(l,c,i,n,r,s,a)}))}insertBannerOnElement(t,e,n,o,i,r,s){console.log(`inserting on [${t}]`);let a=document.createElement("aplazo-placement");a.setAttribute("price-element-selector",e),a.setAttribute("price-format",this.getAttribute("price-format")||""),a.setAttribute("theme",n),a.setAttribute("merchant-id",i),a.setAttribute("integration-type",r),a.setAttribute("env",s),o&&a.setAttribute("ignore",o),a.setAttribute("default-selector",t)}};a=i([s.LoggerComponent],a),e.AplazoInstall=a,customElements.get("aplazo-install")||customElements.define("aplazo-install",a)},250:(t,e)=>{Object.defineProperty(e,"__esModule",{value:!0}),e.FooterInfo=void 0;const n=document.createElement("template");n.innerHTML='\n                  </div>\n                    <aplazo-text style="text-align: center"  variant="soft-p">\n                      Para registrarte, es necesario contar con una tarjeta de débito o crédito, tu INE, y un número celular mexicano. Sujeto a la aprobación de crédito. Aplican términos y condiciones.  Visita <a target="_blank" style="color: #B0BEC5;" href="https://aplazo.mx"> www.aplazo.mx </a> para más información. \n                   </aplazo-text>\n                </div> \n \n';class o extends HTMLElement{constructor(){var t;super(),this.attachShadow({mode:"open"}),null===(t=this.shadowRoot)||void 0===t||t.appendChild(n.content.cloneNode(!0))}}e.FooterInfo=o,customElements.get("footer-info")||customElements.define("footer-info",o)},141:function(t,e,n){var o=this&&this.__createBinding||(Object.create?function(t,e,n,o){void 0===o&&(o=n),Object.defineProperty(t,o,{enumerable:!0,get:function(){return e[n]}})}:function(t,e,n,o){void 0===o&&(o=n),t[o]=e[n]}),i=this&&this.__exportStar||function(t,e){for(var n in t)"default"===n||Object.prototype.hasOwnProperty.call(e,n)||o(e,t,n)};Object.defineProperty(e,"__esModule",{value:!0}),e.ResponsiveRow=e.ModalActions=e.FooterInfo=e.StepsList=e.InstructionsList=void 0,i(n(159),e);var r=n(981);Object.defineProperty(e,"InstructionsList",{enumerable:!0,get:function(){return r.InstructionsList}});var s=n(735);Object.defineProperty(e,"StepsList",{enumerable:!0,get:function(){return s.StepsList}});var a=n(250);Object.defineProperty(e,"FooterInfo",{enumerable:!0,get:function(){return a.FooterInfo}});var l=n(876);Object.defineProperty(e,"ModalActions",{enumerable:!0,get:function(){return l.ModalActions}});var c=n(446);Object.defineProperty(e,"ResponsiveRow",{enumerable:!0,get:function(){return c.ResponsiveRow}})},159:(t,e)=>{Object.defineProperty(e,"__esModule",{value:!0}),e.AplazoInfoIcon=void 0;const n=document.createElement("template");n.innerHTML='\n    <style>\n    .info-trigger{\n            margin-left: 10px;\n            font-weight: 400;\n      font-size: 1.2rem;\n      }\n      .row{\n        flex:1\n      }\n      /* Modal Content */\n.modal-content {\n  background-color: none;\n  margin: auto;\n   padding-top: 20px; \n\n  width: 37rem;\n  height: 27rem;\n}\n\n/* The Close Button */\n.close {\n  color: #000;\n  float: right;\n  font-size: 28px;\n  font-weight: bold;\n  padding-right: 10px;\n}\n\n.close:hover,\n.close:focus {\n  color: #000;\n  text-decoration: none;\n  cursor: pointer;\n}\n    \n      @media only screen and (max-width: 420px) {\n            .info-trigger{\n            padding-right: 10px;\n            }\n            aplazo-logo{\n                padding-left: 6px;\n            }\n          .xs-visible {\n            display: block !important;\n            img {\n              width: 100%;\n            }\n          }\n          .modal-content{\n           width: 80%;\n           height: 100%;\n              padding-top: 5px; \n          }\n          .banner {\n            padding:0px;\n            font-size:0.7rem;\n          }\n          \n          .mid-visible{\n            display: none !important;;\n          }\n       }\n       @media only screen and (min-width: 420px) {\n          .xs-visible {\n            display: none !important;\n          }\n    \n          .mid-visible{\n            display: block !important;;\n          }\n       }\n\n    </style>\n       <aplazo-modal>\n            <div slot="trigger"> \n                <slot name="info-trigger"></slot>\n            </div>\n    \n              <div slot="content" class="modal-content">\n                <div class="mid-visible" style="\n                    width: 100%;\n                    height: 100%;\n                    background-repeat: round;\n                    background-size: 100% 100%;\n                    background-image: url(\'https://cdn.aplazo.mx/aplazo-description.png\');"\n                ><span class="close" slot="close">&times</span>\n                </div>\n                \n                <div class="xs-visible" \n                style="\n                    width: 100%;\n                    height: 93%;\n                    background-repeat:round;\n                    background-size: 100% 100%;\n                    background-image: url(\'https://cdn.aplazo.mx/aplazo-desc-movil.png\');">\n                   <span class="close" slot="close">&times</span>\n                  </div>\n              </div>\n            \n        </aplazo-modal>\n    ';class o extends HTMLElement{constructor(){var t;super(),this.attachShadow({mode:"open"}),null===(t=this.shadowRoot)||void 0===t||t.appendChild(n.content.cloneNode(!0))}}e.AplazoInfoIcon=o,customElements.get("aplazo-info-icon")||customElements.define("aplazo-info-icon",o)},981:(t,e)=>{Object.defineProperty(e,"__esModule",{value:!0}),e.InstructionsList=void 0;const n=document.createElement("template");n.innerHTML='\n    <style>:host{flex:1}</style>\n\n         <div style="flex: 1">\n                   <instruction-card \n                    step-img="https://cdn.aplazo.mx/Group-2.png"\n                    step-title="Paga en 5 plazos quincenales" \n                    step-description="Obtén más control sobre tus compras pagando el primer plazo al momento de la compra." >    \n                  </instruction-card>  \n                  \n                  <instruction-card\n                    step-img="https://cdn.aplazo.mx/Group-1.png" \n                    step-title="Aprobación instantánea" \n                    step-description="Registrate una vez, obten tu crédito de forma instantánea y comienza a comprar con Aplazo." >    \n                  </instruction-card> \n                       \n                  <instruction-card\n                    step-img="https://cdn.aplazo.mx/Group.png" \n                    step-title="Simple y transparente" \n                    step-description="Olvidate de cargos extras y términos confusos, nuestro servicio es seguro y fácil de entender." >    \n                  </instruction-card>\n                </div>\n \n';class o extends HTMLElement{constructor(){var t;super(),this.attachShadow({mode:"open"}),null===(t=this.shadowRoot)||void 0===t||t.appendChild(n.content.cloneNode(!0))}}e.InstructionsList=o,customElements.get("instructions-list")||customElements.define("instructions-list",o)},876:(t,e)=>{Object.defineProperty(e,"__esModule",{value:!0}),e.ModalActions=void 0;const n=document.createElement("template");n.innerHTML='\n<style>:host{flex:1}</style>\n                 <div style="flex: 1">\n                       <aplazo-text variant="light-gray-title">¿POR QUÉ UTILIZAR APLAZO?</aplazo-text>\n                       <aplazo-text  variant="title-small">Compra ahora. Paga a plazos. Sin tarjeta de crédito.</aplazo-text>\n                       <aplazo-text>Visita nuestras <a target="_blank" href="https://www.aplazo.mx/preguntas-frecuentes"> Preguntas Frecuentes. </a> </aplazo-text>\n                       <aplazo-button>CREAR MI CUENTA</aplazo-button>\n                  </div>\n \n';class o extends HTMLElement{constructor(){var t;super(),this.attachShadow({mode:"open"}),null===(t=this.shadowRoot)||void 0===t||t.appendChild(n.content.cloneNode(!0))}}e.ModalActions=o,customElements.get("modal-actions")||customElements.define("modal-actions",o)},446:(t,e)=>{Object.defineProperty(e,"__esModule",{value:!0}),e.ResponsiveRow=void 0;const n=document.createElement("template");n.innerHTML='\n<style>\n        .flex-container {\n  display: flex;\n  flex-direction: row;\n}\n\n@media (max-width: 800px) {\n  .flex-container {\n    flex-direction: column;\n  }\n}\n</style>\n         <div class="flex-container" style="padding-top: 15px">\n         <modal-actions></modal-actions>\n                            <instructions-list></instructions-list>\n         </div>\n \n';class o extends HTMLElement{constructor(){var t;super(),this.attachShadow({mode:"open"}),null===(t=this.shadowRoot)||void 0===t||t.appendChild(n.content.cloneNode(!0))}}e.ResponsiveRow=o,customElements.get("responsive-row")||customElements.define("responsive-row",o)},735:(t,e)=>{Object.defineProperty(e,"__esModule",{value:!0}),e.StepsList=void 0;const n=document.createElement("template");n.innerHTML='\n<style>\n        .flex-container {\n  display: flex;\n  flex-direction: row;\n}\n\n@media (max-width: 800px) {\n  .flex-container {\n    flex-direction: column;\n  }\n}\n</style>\n            <div class="flex-container">\n                  <step-card \n                    step-number="1"\n                    step-img="https://cdn.aplazo.mx/Paso-1.jpg"\n                    step-title="Llena tu carrito" \n                    step-description="Agrega los productos que quieres comprar a tu carrito de compra." >    \n                  </step-card>\n                  </step-card>\n                  <step-card \n                    step-number="2"\n                    step-img="https://cdn.aplazo.mx/Paso-1-1.jpg"\n                    step-title="Elige Aplazo como forma de pago" \n                    step-description="Crea tu cuenta y agrega tu tarjeta de crédito o débito." >    \n                  </step-card> \n                  <step-card \n                    step-number="3"\n                    step-img="https://cdn.aplazo.mx/Paso-1-2.jpg"\n                    step-title="Disfruta tu compra" \n                    step-description="Paga el 20% y llévatelo hoy. Paga en 5 plazos quincenales." >    \n                  </step-card>  \n                </div>\n \n';class o extends HTMLElement{constructor(){var t;super(),this.attachShadow({mode:"open"}),null===(t=this.shadowRoot)||void 0===t||t.appendChild(n.content.cloneNode(!0))}}e.StepsList=o,customElements.get("steps-list")||customElements.define("steps-list",o)},174:function(t,e,n){var o=this&&this.__decorate||function(t,e,n,o){var i,r=arguments.length,s=r<3?e:null===o?o=Object.getOwnPropertyDescriptor(e,n):o;if("object"==typeof Reflect&&"function"==typeof Reflect.decorate)s=Reflect.decorate(t,e,n,o);else for(var a=t.length-1;a>=0;a--)(i=t[a])&&(s=(r<3?i(s):r>3?i(e,n,s):i(e,n))||s);return r>3&&s&&Object.defineProperty(e,n,s),s},i=this&&this.__awaiter||function(t,e,n,o){return new(n||(n=Promise))((function(i,r){function s(t){try{l(o.next(t))}catch(t){r(t)}}function a(t){try{l(o.throw(t))}catch(t){r(t)}}function l(t){var e;t.done?i(t.value):(e=t.value,e instanceof n?e:new n((function(t){t(e)}))).then(s,a)}l((o=o.apply(t,e||[])).next())}))};Object.defineProperty(e,"__esModule",{value:!0}),e.AplazoPlacementElement=void 0;const r=n(460),s=n(622),a=n(740),l={prod:"mx",dev:"dev"};let c=class extends HTMLElement{constructor(){var t;super(),this.itsLoadingTemplate=!1,this.attachShadow({mode:"open"}),null===(t=this.shadowRoot)||void 0===t||t.appendChild(r.template.content.cloneNode(!0)),this.spawnerComponent=new s.SpawnerComponent(this)}connectedCallback(){var t,e;this.getAttribute("env")||this.setAttribute("env","prod"),this.updateLogoSize();const n=null===(t=this.shadowRoot)||void 0===t?void 0:t.querySelector(".quotes-amount");n&&n.setAttribute("style",this.QuoteStyle);const o=null===(e=this.shadowRoot)||void 0===e?void 0:e.querySelector(".info-trigger");o&&o.setAttribute("style",this.InfoElementStyle),this.setLogoAttrs(),this.setAttribute("style",this.MainStyle),document.addEventListener("DOMContentLoaded",(t=>{this.updatePosition()})),window.addEventListener("resize",(t=>{this.updatePosition()}))}static get observedAttributes(){return["product-price","price-element-selector","ignore","theme","default-selector","xs-selector","merchant-id","integration-type","env"]}attributeChangedCallback(t,e,n){switch(t){case"default-selector":this.updatePosition();break;case"theme":this.setLogoAttrs();break;case"merchant-id":case"integration-type":case"env":this.fetchMerchantTemplate()}this.propagateProps()}updatePosition(){this.spawnerComponent.updatePosition({xsSelector:this.getAttribute("xs-selector")||"",defaultSelector:this.getAttribute("default-selector")||""})}get ProductPrice(){return"NO-DECIMAL"===this.PriceFormat?Number(this.getAttribute("product-price")):Number(this.getAttribute("product-price"))/100}get PriceFormat(){return this.getAttribute("price-format")||""}get LogoSize(){return Number(this.getAttribute("aplazo-logo-size"))||22}get LogoStyle(){return this.getAttribute("logo-style")||""}get Theme(){return this.getAttribute("theme")||"light"}get Environment(){return this.getAttribute("env")||""}get QuoteStyle(){return this.getAttribute("quote-style")||""}get InfoElementStyle(){return this.getAttribute("info-style")||""}get MainStyle(){return this.getAttribute("main-style")||""}get MerchantID(){return this.getAttribute("merchant-id")||""}get IntegrationType(){return this.getAttribute("integration-type")||""}updateLogoSize(){var t;const e=null===(t=this.shadowRoot)||void 0===t?void 0:t.querySelector("aplazo-logo");e&&e.setAttribute("logo-size",String(this.LogoSize))}setLogoAttrs(){var t;const e=null===(t=this.shadowRoot)||void 0===t?void 0:t.querySelector("aplazo-logo");e&&(e.setAttribute("style",this.LogoStyle),e.setAttribute("theme",this.Theme))}fetchMerchantTemplate(){const t=this.MerchantID,e=this.IntegrationType,n=this.Environment;t&&e&&(n||this.itsLoadingTemplate)?(this.itsLoadingTemplate=!0,fetch(`https://mpromotions.aplazo.${l[n]}/api/v1/widget-configurations/${t}/${e}`,{method:"GET"}).then((t=>i(this,void 0,void 0,(function*(){return yield t.json()})))).then((({errorMessage:t,data:e})=>{if(null===t&&null!==e){const{html:t}=e;t&&t.length>3?(this.shadowRoot.innerHTML=t,this.propagateProps()):this.useDefault()}else this.useDefault();this.itsLoadingTemplate=!1})).catch((t=>{this.itsLoadingTemplate=!1}))):this.useDefault()}useDefault(){var t;0===(this.shadowRoot.childNodes||[]).length&&(null===(t=this.shadowRoot)||void 0===t||t.appendChild(r.template.content.cloneNode(!0)),this.propagateProps())}propagateProps(){var t;const e=null===(t=this.shadowRoot)||void 0===t?void 0:t.children;e&&Array.from(e).forEach((t=>{t.setAttribute("product-price",this.getAttribute("product-price")||""),t.setAttribute("env",this.Environment),t.setAttribute("merchant-id",this.MerchantID),t.setAttribute("integration-type",this.IntegrationType),t.setAttribute("theme",this.Theme),t.setAttribute("price-element-selector",this.getAttribute("price-element-selector")||"")}))}};c=o([a.LoggerComponent],c),e.AplazoPlacementElement=c,customElements.get("aplazo-placement")||customElements.define("aplazo-placement",c)},460:(t,e)=>{Object.defineProperty(e,"__esModule",{value:!0}),e.template=void 0,e.template=document.createElement("template"),e.template.setAttribute("aplazo-element","template"),e.template.innerHTML='\n   <style>\n   :host{\n      display: block !important;\n      font-family: inherit;\n      color: inherit;\n      padding: 10px 0px 10px 0px;\n   }\n   .info-trigger{\n      font-size: 12px;\n      border-bottom: 1px solid;\n      font-weight: bold;\n   }\n      \n   .quotes-amount{\n     font-weight: bold;\n   }\n   </style>                  \n \n        Paga en \n           <aplazo-quotes></aplazo-quotes>\n     con \n     <aplazo-modal style="display: inline-table">\n        <aplazo-logo slot="trigger" style="display: inline-table"></aplazo-logo>\n     </aplazo-modal>\n      <aplazo-info-icon style="display: inline-table">\n        <a class="info-trigger"    slot="info-trigger">Conoce más.</a>\n      </aplazo-info-icon>  \n      \n \n'},914:function(t,e,n){var o=this&&this.__createBinding||(Object.create?function(t,e,n,o){void 0===o&&(o=n),Object.defineProperty(t,o,{enumerable:!0,get:function(){return e[n]}})}:function(t,e,n,o){void 0===o&&(o=n),t[o]=e[n]}),i=this&&this.__exportStar||function(t,e){for(var n in t)"default"===n||Object.prototype.hasOwnProperty.call(e,n)||o(e,t,n)};Object.defineProperty(e,"__esModule",{value:!0}),i(n(174),e),i(n(914),e)},588:function(t,e,n){var o=this&&this.__decorate||function(t,e,n,o){var i,r=arguments.length,s=r<3?e:null===o?o=Object.getOwnPropertyDescriptor(e,n):o;if("object"==typeof Reflect&&"function"==typeof Reflect.decorate)s=Reflect.decorate(t,e,n,o);else for(var a=t.length-1;a>=0;a--)(i=t[a])&&(s=(r<3?i(s):r>3?i(e,n,s):i(e,n))||s);return r>3&&s&&Object.defineProperty(e,n,s),s};Object.defineProperty(e,"__esModule",{value:!0}),e.AplazoQuotesElement=void 0;const i=n(740),r=n(982);let s=class extends HTMLElement{constructor(){var t,e,n;super(),this.pricingComponent=new r.PricingComponent(this),this.attachShadow({mode:"open"});const o=document.createElement("template");this.setAttribute("aplazo-element","true"),o.innerHTML=' \n            <style>     \n               .quotes-amount{\n                 font-weight: bold;\n               }\n            </style>\n            <span class="quotes-amount">5 plazos  </span>\n            <span style="font-weight: lighter" id="price-slot"></span>\n           ',null===(t=this.shadowRoot)||void 0===t||t.appendChild(o.content.cloneNode(!0)),this.priceSpot=null===(e=this.shadowRoot)||void 0===e?void 0:e.querySelector("#price-slot"),this.quotesElement=null===(n=this.shadowRoot)||void 0===n?void 0:n.querySelector(".quotes-amount")}connectedCallback(){this.updateQuotes(),document.addEventListener("DOMContentLoaded",(t=>{this.pricingComponent.trackElement(this.getAttribute("price-element-selector"),this.getAttribute("ignore"))})),window.addEventListener("resize",(t=>{this.pricingComponent.trackElement(this.getAttribute("price-element-selector"),this.getAttribute("ignore"))}))}static get observedAttributes(){return["product-price","price-element-selector","ignore","theme","merchant-id","integration-type","quotes"]}attributeChangedCallback(t,e,n){var o;switch(this.priceSpot=null===(o=this.shadowRoot)||void 0===o?void 0:o.querySelector("#price-slot"),this.updateQuotes(),t){case"product-price":this.updateQuotes();break;case"price-element-selector":case"price-format":this.ProductPrice||this.pricingComponent.trackElement(n,this.getAttribute("ignore"));break;case"ignore":this.pricingComponent.trackElement(this.getAttribute("price-element-selector"),this.getAttribute("ignore"))}}updateQuotes(){const t=this.ProductPrice;if(!t)return void(this.priceSpot&&(this.priceSpot.textContent=""));if(!this.priceSpot||!this.quotesElement)return;const e=Number(this.getAttribute("quotes"))||5;this.quotesElement.innerHTML=`${e} plazos`,this.priceSpot.textContent=`desde $${parseFloat(""+t/e).toFixed(2)}`}get ProductPrice(){return"NO-DECIMAL"===this.PriceFormat?Number(this.getAttribute("product-price")):Number(this.getAttribute("product-price"))/100}get PriceFormat(){return this.getAttribute("price-format")||""}get MerchantID(){return this.getAttribute("merchant-id")||""}};s=o([i.LoggerComponent],s),e.AplazoQuotesElement=s,customElements.get("aplazo-quotes")||customElements.define("aplazo-quotes",s)},86:function(t,e,n){var o=this&&this.__createBinding||(Object.create?function(t,e,n,o){void 0===o&&(o=n),Object.defineProperty(t,o,{enumerable:!0,get:function(){return e[n]}})}:function(t,e,n,o){void 0===o&&(o=n),t[o]=e[n]}),i=this&&this.__exportStar||function(t,e){for(var n in t)"default"===n||Object.prototype.hasOwnProperty.call(e,n)||o(e,t,n)};Object.defineProperty(e,"__esModule",{value:!0}),i(n(588),e)},893:function(t,e,n){var o=this&&this.__createBinding||(Object.create?function(t,e,n,o){void 0===o&&(o=n),Object.defineProperty(t,o,{enumerable:!0,get:function(){return e[n]}})}:function(t,e,n,o){void 0===o&&(o=n),t[o]=e[n]}),i=this&&this.__exportStar||function(t,e){for(var n in t)"default"===n||Object.prototype.hasOwnProperty.call(e,n)||o(e,t,n)};Object.defineProperty(e,"__esModule",{value:!0}),i(n(983),e)},983:function(t,e,n){var o=this&&this.__createBinding||(Object.create?function(t,e,n,o){void 0===o&&(o=n),Object.defineProperty(t,o,{enumerable:!0,get:function(){return e[n]}})}:function(t,e,n,o){void 0===o&&(o=n),t[o]=e[n]}),i=this&&this.__exportStar||function(t,e){for(var n in t)"default"===n||Object.prototype.hasOwnProperty.call(e,n)||o(e,t,n)};Object.defineProperty(e,"__esModule",{value:!0}),i(n(129),e)},129:(t,e,n)=>{Object.defineProperty(e,"__esModule",{value:!0}),e.ModalElement=void 0;const o=n(777);class i extends HTMLElement{constructor(){var t;super(),this.attachShadow({mode:"open"}),null===(t=this.shadowRoot)||void 0===t||t.appendChild(o.template.content.cloneNode(!0))}connectedCallback(){var t,e,n,o,i,r,s;null===(e=null===(t=this.shadowRoot)||void 0===t?void 0:t.querySelector(".trigger-container"))||void 0===e||e.addEventListener("click",(t=>{t.stopPropagation(),t.preventDefault(),this.toggleModal(!0)}));const a=null===(n=this.shadowRoot)||void 0===n?void 0:n.querySelector("#modal");null==a||a.addEventListener("click",(t=>{t.target===a&&(t.stopPropagation(),t.preventDefault(),this.toggleModal(!1))})),null===(i=null===(o=this.shadowRoot)||void 0===o?void 0:o.querySelector(".content-container"))||void 0===i||i.addEventListener("click",(t=>{t.stopPropagation(),t.preventDefault()})),null===(s=null===(r=this.shadowRoot)||void 0===r?void 0:r.querySelector("[slot=close]"))||void 0===s||s.addEventListener("click",(t=>{t.stopPropagation(),t.preventDefault(),this.toggleModal(!1)}))}toggleModal(t){var e;this.modalRef||(this.modalRef=null===(e=this.shadowRoot)||void 0===e?void 0:e.querySelector("#modal")),this.modalRef.style.display=t?"block":"none",document.body.appendChild(this.modalRef)}}e.ModalElement=i,customElements.get("aplazo-modal")||customElements.define("aplazo-modal",i)},777:(t,e)=>{Object.defineProperty(e,"__esModule",{value:!0}),e.template=void 0,e.template=document.createElement("template"),e.template.innerHTML='\n    <style>\n        \n        .trigger-container{\n            cursor: pointer;\n        }\n        .content-container{\n            width: 100%;\n            height: 100%;\n            display: flex;\n            align-items: center;\n            justify-content: center;\n            background-color: none;\n        }\n        \n     \n    </style>\n    <div> \n       <div class="trigger-container">\n        <slot name="trigger"></slot>\n       </div>\n       <div id="modal" style=" \n          display: none;\n          position: fixed;\n          z-index: 999999999999;\n          padding-top: 40px;\n          left: 0;\n          top: 0;\n          width: 100%; \n          height: 99%; \n          overflow: auto;\n          background-color: rgb(0,0,0,0.05);\n          padding-bottom: 20px;\n          ">\n       \n          <div style=" \n           flex-direction: column;\n          display: flex;\n          width:  min-content;\n          margin: auto;\n          border-radius: 8px;\n          padding: 50px ;\n          background: white"> \n                     <div style="display: flex; justify-content: space-between;padding-bottom: 10px">\n                        <aplazo-logo style="right: 10px;position: relative" ></aplazo-logo>\n                             <span class="close" style=" cursor: pointer;align-self: center;color: #131332;font-size: 17px;"\n                                slot="close"><aplazo-icon></aplazo-icon> &times</span>\n                     </div>\n                    \x3c!--body--\x3e\n                    <div>\n                         <aplazo-text  variant="big"> Compra ahora. Paga a plazos. Sin tarjeta de crédito.</aplazo-text>\n                         <aplazo-text  variant="light-title">Ahora puedes tener lo que quieras, cuando quieras. Compra ahora y paga en 5 plazos quincenales.</aplazo-text>    \n                        <steps-list></steps-list>\n                        <responsive-row>  </responsive-row>\n                        <footer-info></footer-info>\n                    </div>\n                </div>\n          </div>\n       </div>\n    <div> \n'}},e={};!function n(o){var i=e[o];if(void 0!==i)return i.exports;var r=e[o]={exports:{}};return t[o].call(r.exports,r,r.exports,n),r.exports}(22)})();
