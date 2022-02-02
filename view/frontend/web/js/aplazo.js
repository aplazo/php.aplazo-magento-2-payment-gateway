/******/ (() => { // webpackBootstrap
/******/    "use strict";
/******/    var __webpack_modules__ = ({

/***/ 107:
/***/ ((__unused_webpack_module, exports) => {


    Object.defineProperty(exports, "__esModule", ({ value: true }));
    exports.AplazoLogo = void 0;
    const template = document.createElement('template');
    template.innerHTML = `
        <style>
        :host{
        display: inline-flex;
        }
              @media only screen and (max-width: 420px) {
                img {
                  height: 29px !important;
                    }
               }
        img {
            height:30px;
            width: 100px;
            position:relative;
            top: 7px;
            border-radius: 25px;
        }
        </style>
         <img id="logo-image"  src="https://cdn.aplazo.mx/logo-raw.png" alt="">
     
    `;
    class AplazoLogo extends HTMLElement {
        constructor() {
            var _a;
            super();
            this.attachShadow({ mode: 'open' });
            (_a = this.shadowRoot) === null || _a === void 0 ? void 0 : _a.appendChild(template.content.cloneNode(true));
        }
        connectedCallback() {
            var _a;
            const logoSize = this.getAttribute('logo-size');
            const imgEle = (_a = this.shadowRoot) === null || _a === void 0 ? void 0 : _a.getElementById("logo-image");
            if (!imgEle) {
                return;
            }
            const heigth = logoSize || (window.innerWidth < 420 ? '29' : '29');
            imgEle.style.height = heigth + 'px';
        }
    }
    exports.AplazoLogo = AplazoLogo;
    customElements.get('aplazo-logo') || customElements.define('aplazo-logo', AplazoLogo);
    
    
    /***/ }),
    
    /***/ 278:
    /***/ ((__unused_webpack_module, exports) => {
    
    
    Object.defineProperty(exports, "__esModule", ({ value: true }));
    exports.AplazoButton = void 0;
    const template = document.createElement('template');
    template.innerHTML = `
        <style>
        
        div{
        color:white
        }
    
        button {
        margin-top: 30px;
            width: 172px;
            height: 44px;
            background: #3D72C9;
            border-radius: 8px;
            border: none;
    font-size: 14px;
    font-style: normal;
    font-weight: 700;
    line-height: 18px;
    letter-spacing: 0.5px;
    text-align: center;
    
        }
        
        button:hover {
            cursor: pointer;
            background: #345ea4 ;
        }
        </style>
        <button id="action-btn"> <div id="text-holder"></div> </button>
    `;
    class AplazoButton extends HTMLElement {
        constructor() {
            var _a;
            super();
            this.attachShadow({ mode: 'open' });
            (_a = this.shadowRoot) === null || _a === void 0 ? void 0 : _a.appendChild(template.content.cloneNode(true));
        }
        connectedCallback() {
            var _a, _b, _c;
            this.textHolder = (_a = this.shadowRoot) === null || _a === void 0 ? void 0 : _a.querySelector("#text-holder");
            if (this.textHolder) {
                this.textHolder.innerHTML = this.innerHTML || '';
            }
            (_c = (_b = this.shadowRoot) === null || _b === void 0 ? void 0 : _b.querySelector('#action-btn')) === null || _c === void 0 ? void 0 : _c.addEventListener('click', (ev) => {
                ev.stopPropagation();
                ev.preventDefault();
                window.open("https://customer.aplazo.mx/register/credentials", "_blank");
            });
        }
    }
    exports.AplazoButton = AplazoButton;
    customElements.get('aplazo-button') || customElements.define('aplazo-button', AplazoButton);
    
    
    /***/ }),
    
    /***/ 697:
    /***/ ((__unused_webpack_module, exports) => {
    
    
    Object.defineProperty(exports, "__esModule", ({ value: true }));
    exports.Icon = void 0;
    const template = document.createElement('template');
    template.innerHTML = `
        <script>
      
        </script>
       <span >
       
       </span>
     
    `;
    class Icon extends HTMLElement {
        constructor() {
            var _a;
            super();
            this.attachShadow({ mode: 'open' });
            (_a = this.shadowRoot) === null || _a === void 0 ? void 0 : _a.appendChild(template.content.cloneNode(true));
        }
    }
    exports.Icon = Icon;
    customElements.get('ap-icon') || customElements.define('ap-icon', Icon);
    
    
    /***/ }),
    
    /***/ 214:
    /***/ ((__unused_webpack_module, exports, __webpack_require__) => {
    
    
    Object.defineProperty(exports, "__esModule", ({ value: true }));
    exports.Typography = exports.AplazoButton = exports.StepCard = exports.InstructionCard = exports.Icon = exports.AplazoLogo = void 0;
    var aplazo_logo_1 = __webpack_require__(107);
    Object.defineProperty(exports, "AplazoLogo", ({ enumerable: true, get: function () { return aplazo_logo_1.AplazoLogo; } }));
    var icon_1 = __webpack_require__(697);
    Object.defineProperty(exports, "Icon", ({ enumerable: true, get: function () { return icon_1.Icon; } }));
    var instruction_card_1 = __webpack_require__(445);
    Object.defineProperty(exports, "InstructionCard", ({ enumerable: true, get: function () { return instruction_card_1.InstructionCard; } }));
    var step_card_1 = __webpack_require__(796);
    Object.defineProperty(exports, "StepCard", ({ enumerable: true, get: function () { return step_card_1.StepCard; } }));
    var button_1 = __webpack_require__(278);
    Object.defineProperty(exports, "AplazoButton", ({ enumerable: true, get: function () { return button_1.AplazoButton; } }));
    var typography_1 = __webpack_require__(324);
    Object.defineProperty(exports, "Typography", ({ enumerable: true, get: function () { return typography_1.Typography; } }));
    
    
    /***/ }),
    
    /***/ 445:
    /***/ ((__unused_webpack_module, exports) => {
    
    
    Object.defineProperty(exports, "__esModule", ({ value: true }));
    exports.InstructionCard = void 0;
    const template = document.createElement('template');
    template.innerHTML = `
                    <style>
                        .info{
                            justify-content: center;
                            display: flex;
                            flex-direction: column;
                            padding-left: 22px;
                         }                   
                    </style>
                    <div style="display: flex;align-content: center;margin: 14px 0px 14px 0px">
                        <!--logo for step-->
                        <div>
                          <img id="step-img"  alt="">
                        </div>
                         <!--info-->
                        <div class="info">
                           <aplazo-text id="step-title" variant="title"></aplazo-text>
                           <aplazo-text id="step-description" variant="p"></aplazo-text>
                        </div>
                    </div> 
     
    `;
    class InstructionCard extends HTMLElement {
        constructor() {
            var _a;
            super();
            this.attachShadow({ mode: 'open' });
            (_a = this.shadowRoot) === null || _a === void 0 ? void 0 : _a.appendChild(template.content.cloneNode(true));
        }
        connectedCallback() {
            var _a, _b, _c;
            const stepTitleEle = (_a = this.shadowRoot) === null || _a === void 0 ? void 0 : _a.querySelector("#step-title");
            if (stepTitleEle) {
                stepTitleEle.textContent = this.StepTitle || '';
            }
            const stepDescriptionEle = (_b = this.shadowRoot) === null || _b === void 0 ? void 0 : _b.querySelector("#step-description");
            if (stepDescriptionEle) {
                stepDescriptionEle.textContent = this.StepDescription || '';
            }
            const stepImgEle = (_c = this.shadowRoot) === null || _c === void 0 ? void 0 : _c.querySelector("#step-img");
            if (stepImgEle) {
                stepImgEle.src = this.StepImg || '';
            }
        }
        get StepTitle() {
            return this.getAttribute("step-title") || '';
        }
        get StepDescription() {
            return this.getAttribute("step-description") || '';
        }
        get StepImg() {
            return this.getAttribute("step-img") || '';
        }
    }
    exports.InstructionCard = InstructionCard;
    customElements.get('instruction-card') || customElements.define('instruction-card', InstructionCard);
    
    
    /***/ }),
    
    /***/ 796:
    /***/ ((__unused_webpack_module, exports) => {
    
    
    Object.defineProperty(exports, "__esModule", ({ value: true }));
    exports.StepCard = void 0;
    const template = document.createElement('template');
    template.innerHTML = `
                    <style>
                            .image-lg {
                display: block;
    }
    
                @media (max-width: 800px) {
                        .image-lg {
                           display: none;
                           }
                    }
                        :host{
                          width: 230px;
                          padding-left: 10px;
                          padding-right: 10px;
                        }
                        .info{
                            justify-content: center;
                            display: flex;
                            flex-direction: column;
                            padding-left: 22px;
                         }                   
                    </style>
                    <div style="align-content: center;margin: 14px 0px 14px 0px">
                        <!--logo for step-->
                        <div class="image-lg">
                          <img id="step-img"  alt="">
                        </div>
                         <!--info-->
                         <div style="display: flex">
                            <aplazo-text id="step-number" variant="title"></aplazo-text>
                            <div class="info">
                                <aplazo-text id="step-title" variant="title"></aplazo-text>
                                <aplazo-text id="step-description" variant="p"></aplazo-text>
                            </div>
                        </div>
                    </div> 
     
    `;
    class StepCard extends HTMLElement {
        constructor() {
            var _a;
            super();
            this.attachShadow({ mode: 'open' });
            (_a = this.shadowRoot) === null || _a === void 0 ? void 0 : _a.appendChild(template.content.cloneNode(true));
        }
        connectedCallback() {
            var _a, _b, _c, _d;
            const stepTitleEle = (_a = this.shadowRoot) === null || _a === void 0 ? void 0 : _a.querySelector("#step-title");
            if (stepTitleEle) {
                stepTitleEle.textContent = this.StepTitle;
            }
            const stepDescriptionEle = (_b = this.shadowRoot) === null || _b === void 0 ? void 0 : _b.querySelector("#step-description");
            if (stepDescriptionEle) {
                stepDescriptionEle.textContent = this.StepDescription;
            }
            const stepImgEle = (_c = this.shadowRoot) === null || _c === void 0 ? void 0 : _c.querySelector("#step-img");
            if (stepImgEle) {
                stepImgEle.src = this.StepImg;
            }
            const stepNumberEle = (_d = this.shadowRoot) === null || _d === void 0 ? void 0 : _d.querySelector("#step-number");
            if (stepNumberEle) {
                stepNumberEle.textContent = this.StepNumber;
            }
        }
        get StepTitle() {
            return this.getAttribute("step-title") || '';
        }
        get StepDescription() {
            return this.getAttribute("step-description") || '';
        }
        get StepImg() {
            return this.getAttribute("step-img") || '';
        }
        get StepNumber() {
            return this.getAttribute("step-number") || '';
        }
    }
    exports.StepCard = StepCard;
    customElements.get('step-card') || customElements.define('step-card', StepCard);
    
    
    /***/ }),
    
    /***/ 324:
    /***/ ((__unused_webpack_module, exports) => {
    
    
    Object.defineProperty(exports, "__esModule", ({ value: true }));
    exports.Typography = void 0;
    const template = document.createElement('template');
    template.innerHTML = `
        <style>
    
        @import url('https://fonts.googleapis.com/css2?family=Manrope:wght@200;700&display=swap');
    
        :host {
            font-family: 'Manrope', sans-serif;
            font-style: normal;
            letter-spacing: 0px;
            text-align: left;
            color: #000000;
        }
        div {
            text-align: inherit;
        }
        .p{
            font-size: 14px;
            font-weight: 400;
            line-height: 19.8px;
            color: #37474F;
        }
        
        .light-gray-title{
            font-size: 10px;
            font-style: normal;
            font-weight: 700;
            line-height: 12px;
            letter-spacing: 0.3275538980960846px;
            color:  #78909C;
    ;
        }
        .title {
           font-size: 15px;
           font-weight: 700;
           line-height: 23px;
           letter-spacing: 0em;
           text-align: left;
        }
           .title-small {
    font-size: 20px;
    font-style: normal;
    font-weight: 700;
    line-height: 28px;
    letter-spacing: 0em;
    text-align: left;
    
        }
        .light-title {
            font-size: 16px;
            font-weight: 400;
            line-height: 28px;
        }
        .big {
            font-weight: bold;
            font-size: 28px;
            line-height: 42px;
        }
        .soft-p {
            color: #B0BEC5;;
            font-size: 12px;
            line-height: 20px;
        }
        
        </style>
        <div id="text-holder"></div>    
     
    `;
    class Typography extends HTMLElement {
        constructor() {
            var _a;
            super();
            this.attachShadow({ mode: 'open' });
            (_a = this.shadowRoot) === null || _a === void 0 ? void 0 : _a.appendChild(template.content.cloneNode(true));
        }
        connectedCallback() {
            var _a;
            this.textHolder = (_a = this.shadowRoot) === null || _a === void 0 ? void 0 : _a.querySelector("#text-holder");
            if (this.textHolder) {
                this.textHolder.innerHTML = this.innerHTML || '';
                this.textHolder.classList.add(this.Variant);
            }
        }
        get Variant() {
            return this.getAttribute("variant") || 'p';
        }
    }
    exports.Typography = Typography;
    customElements.get('aplazo-text') || customElements.define('aplazo-text', Typography);
    
    
    /***/ }),
    
    /***/ 982:
    /***/ ((__unused_webpack_module, exports) => {
    
    
    Object.defineProperty(exports, "__esModule", ({ value: true }));
    exports.PricingComponent = void 0;
    class PricingComponent {
        constructor(target) {
            this.target = target;
        }
        trackElement(elementIdentifier) {
            var _a;
            console.log(elementIdentifier);
            let price = document.querySelector(elementIdentifier);
            if (price) {
                const priceStr = (_a = price.textContent) === null || _a === void 0 ? void 0 : _a.trim().replace("$", "").replace("MXN", "").replace(".", "").replace(",", "");
                if (priceStr) {
                    this.target.setAttribute('product-price', priceStr);
                }
                window.onclick = ((ev) => {
                    var _a;
                    if (!price) {
                        return;
                    }
                    const priceStr = (_a = price.textContent) === null || _a === void 0 ? void 0 : _a.trim().replace("$", "").replace("MXN", "").replace(".", "").replace(",", "");
                    if (priceStr) {
                        this.target.setAttribute('product-price', priceStr);
                    }
                });
            }
        }
        init() {
        }
    }
    exports.PricingComponent = PricingComponent;
    
    
    /***/ }),
    
    /***/ 22:
    /***/ (function(__unused_webpack_module, exports, __webpack_require__) {
    
    
    var __createBinding = (this && this.__createBinding) || (Object.create ? (function(o, m, k, k2) {
        if (k2 === undefined) k2 = k;
        Object.defineProperty(o, k2, { enumerable: true, get: function() { return m[k]; } });
    }) : (function(o, m, k, k2) {
        if (k2 === undefined) k2 = k;
        o[k2] = m[k];
    }));
    var __exportStar = (this && this.__exportStar) || function(m, exports) {
        for (var p in m) if (p !== "default" && !Object.prototype.hasOwnProperty.call(exports, p)) __createBinding(exports, m, p);
    };
    Object.defineProperty(exports, "__esModule", ({ value: true }));
    exports.AplazoInstall = void 0;
    __exportStar(__webpack_require__(893), exports);
    __exportStar(__webpack_require__(214), exports);
    __exportStar(__webpack_require__(141), exports);
    __exportStar(__webpack_require__(914), exports);
    __exportStar(__webpack_require__(951), exports);
    class AplazoInstall extends HTMLElement {
        constructor() {
            super();
        }
        static get observedAttributes() {
            return ['respawn-spot'];
        }
        attributeChangedCallback(name, oldValue, newValue) {
            switch (name) {
                case 'respawn-spot':
                    this.installWidgets(newValue);
                    break;
            }
        }
        connectedCallback() {
            document.addEventListener('DOMContentLoaded', (event) => {
                this.installWidgets(this.getAttribute("respawn-spot"));
            });
        }
        installWidgets(newValue) {
            if (!newValue) {
                return;
            }
            const selectors = newValue.split(",");
            selectors.forEach((s) => {
                var _a, _b;
                const [elementSelector, priceSelector] = s.split(":");
                const elementSpot = document.querySelector(elementSelector);
                if (elementSpot) {
                    if ((_a = elementSpot === null || elementSpot === void 0 ? void 0 : elementSpot.parentElement) === null || _a === void 0 ? void 0 : _a.querySelector('aplazo-placement')) {
                        return;
                    }
                    let ele = document.createElement('aplazo-placement');
                    (_b = elementSpot === null || elementSpot === void 0 ? void 0 : elementSpot.parentElement) === null || _b === void 0 ? void 0 : _b.insertBefore(ele, elementSpot);
                    ele.setAttribute('price-element-selector', priceSelector);
                }
            });
        }
    }
    exports.AplazoInstall = AplazoInstall;
    //register
    customElements.get('aplazo-install') || customElements.define('aplazo-install', AplazoInstall);
    
    
    /***/ }),
    
    /***/ 250:
    /***/ ((__unused_webpack_module, exports) => {
    
    
    Object.defineProperty(exports, "__esModule", ({ value: true }));
    exports.FooterInfo = void 0;
    const template = document.createElement('template');
    template.innerHTML = `
                      </div>
                        <aplazo-text style="text-align: center"  variant="soft-p">
                          Para registrarte, es necesario contar con una tarjeta de débito o crédito, tu INE, y un número celular mexicano. Sujeto a la aprobación de crédito. Aplican términos y condiciones.  Visita <a target="_blank" style="color: #B0BEC5;" href="https://aplazo.mx"> www.aplazo.mx </a> para más información. 
                       </aplazo-text>
                    </div> 
     
    `;
    class FooterInfo extends HTMLElement {
        constructor() {
            var _a;
            super();
            this.attachShadow({ mode: 'open' });
            (_a = this.shadowRoot) === null || _a === void 0 ? void 0 : _a.appendChild(template.content.cloneNode(true));
        }
    }
    exports.FooterInfo = FooterInfo;
    customElements.get('footer-info') || customElements.define('footer-info', FooterInfo);
    
    
    /***/ }),
    
    /***/ 141:
    /***/ (function(__unused_webpack_module, exports, __webpack_require__) {
    
    
    var __createBinding = (this && this.__createBinding) || (Object.create ? (function(o, m, k, k2) {
        if (k2 === undefined) k2 = k;
        Object.defineProperty(o, k2, { enumerable: true, get: function() { return m[k]; } });
    }) : (function(o, m, k, k2) {
        if (k2 === undefined) k2 = k;
        o[k2] = m[k];
    }));
    var __exportStar = (this && this.__exportStar) || function(m, exports) {
        for (var p in m) if (p !== "default" && !Object.prototype.hasOwnProperty.call(exports, p)) __createBinding(exports, m, p);
    };
    Object.defineProperty(exports, "__esModule", ({ value: true }));
    exports.ResponsiveRow = exports.ModalActions = exports.FooterInfo = exports.StepsList = exports.InstructionsList = void 0;
    __exportStar(__webpack_require__(159), exports);
    var instructions_list_1 = __webpack_require__(981);
    Object.defineProperty(exports, "InstructionsList", ({ enumerable: true, get: function () { return instructions_list_1.InstructionsList; } }));
    var steps_list_1 = __webpack_require__(735);
    Object.defineProperty(exports, "StepsList", ({ enumerable: true, get: function () { return steps_list_1.StepsList; } }));
    var footer_info_1 = __webpack_require__(250);
    Object.defineProperty(exports, "FooterInfo", ({ enumerable: true, get: function () { return footer_info_1.FooterInfo; } }));
    var modal_actions_1 = __webpack_require__(876);
    Object.defineProperty(exports, "ModalActions", ({ enumerable: true, get: function () { return modal_actions_1.ModalActions; } }));
    var responsive_row_1 = __webpack_require__(446);
    Object.defineProperty(exports, "ResponsiveRow", ({ enumerable: true, get: function () { return responsive_row_1.ResponsiveRow; } }));
    
    
    /***/ }),
    
    /***/ 159:
    /***/ ((__unused_webpack_module, exports) => {
    
    
    Object.defineProperty(exports, "__esModule", ({ value: true }));
    exports.AplazoInfoIcon = void 0;
    const template = document.createElement('template');
    template.innerHTML = `
        <style>
        .info-trigger{
                margin-left: 10px;
                font-weight: 400;
          font-size: 1.2rem;
          }
          .row{
            flex:1
          }
          /* Modal Content */
    .modal-content {
      background-color: none;
      margin: auto;
       padding-top: 20px; 
    
      width: 37rem;
      height: 27rem;
    }
    
    /* The Close Button */
    .close {
      color: #000;
      float: right;
      font-size: 28px;
      font-weight: bold;
      padding-right: 10px;
    }
    
    .close:hover,
    .close:focus {
      color: #000;
      text-decoration: none;
      cursor: pointer;
    }
        
          @media only screen and (max-width: 420px) {
                .info-trigger{
                padding-right: 10px;
                }
                aplazo-logo{
                    padding-left: 6px;
                }
              .xs-visible {
                display: block !important;
                img {
                  width: 100%;
                }
              }
              .modal-content{
               width: 80%;
               height: 100%;
                  padding-top: 5px; 
              }
              .banner {
                padding:0px;
                font-size:0.7rem;
              }
              
              .mid-visible{
                display: none !important;;
              }
           }
           @media only screen and (min-width: 420px) {
              .xs-visible {
                display: none !important;
              }
        
              .mid-visible{
                display: block !important;;
              }
           }
    
        </style>
           <aplazo-modal>
                <div slot="trigger"> 
                    <slot name="info-trigger"></slot>
                </div>
        
                  <div slot="content" class="modal-content">
                    <div class="mid-visible" style="
                        width: 100%;
                        height: 100%;
                        background-repeat: round;
                        background-size: 100% 100%;
                        background-image: url('https://cdn.aplazo.mx/aplazo-description.png');"
                    ><span class="close" slot="close">&times</span>
                    </div>
                    
                    <div class="xs-visible" 
                    style="
                        width: 100%;
                        height: 93%;
                        background-repeat:round;
                        background-size: 100% 100%;
                        background-image: url('https://cdn.aplazo.mx/aplazo-desc-movil.png');">
                       <span class="close" slot="close">&times</span>
                      </div>
                  </div>
                
            </aplazo-modal>
        `;
    class AplazoInfoIcon extends HTMLElement {
        constructor() {
            var _a;
            super();
            this.attachShadow({ mode: 'open' });
            (_a = this.shadowRoot) === null || _a === void 0 ? void 0 : _a.appendChild(template.content.cloneNode(true));
        }
    }
    exports.AplazoInfoIcon = AplazoInfoIcon;
    //register
    customElements.get('aplazo-info-icon') || customElements.define('aplazo-info-icon', AplazoInfoIcon);
    
    
    /***/ }),
    
    /***/ 981:
    /***/ ((__unused_webpack_module, exports) => {
    
    
    Object.defineProperty(exports, "__esModule", ({ value: true }));
    exports.InstructionsList = void 0;
    const template = document.createElement('template');
    template.innerHTML = `
        <style>:host{flex:1}</style>
    
             <div style="flex: 1">
                       <instruction-card 
                        step-img="https://cdn.aplazo.mx/Group-2.png"
                        step-title="Paga en 5 plazos quincenales" 
                        step-description="Obtén más control sobre tus compras pagando el primer plazo al momento de la compra." >    
                      </instruction-card>  
                      
                      <instruction-card
                        step-img="https://cdn.aplazo.mx/Group-1.png" 
                        step-title="Aprobación instantánea" 
                        step-description="Registrate una vez, obten tu crédito de forma instantánea y comienza a comprar con Aplazo." >    
                      </instruction-card> 
                           
                      <instruction-card
                        step-img="https://cdn.aplazo.mx/Group.png" 
                        step-title="Simple y transparente" 
                        step-description="Olvidate de cargos extras y términos confusos, nuestro servicio es seguro y fácil de entender." >    
                      </instruction-card>
                    </div>
     
    `;
    class InstructionsList extends HTMLElement {
        constructor() {
            var _a;
            super();
            this.attachShadow({ mode: 'open' });
            (_a = this.shadowRoot) === null || _a === void 0 ? void 0 : _a.appendChild(template.content.cloneNode(true));
        }
    }
    exports.InstructionsList = InstructionsList;
    customElements.get('instructions-list') || customElements.define('instructions-list', InstructionsList);
    
    
    /***/ }),
    
    /***/ 876:
    /***/ ((__unused_webpack_module, exports) => {
    
    
    Object.defineProperty(exports, "__esModule", ({ value: true }));
    exports.ModalActions = void 0;
    const template = document.createElement('template');
    template.innerHTML = `
    <style>:host{flex:1}</style>
                     <div style="flex: 1">
                           <aplazo-text variant="light-gray-title">¿POR QUÉ UTILIZAR APLAZO?</aplazo-text>
                           <aplazo-text  variant="title-small">Compra ahora. Paga a plazos. Sin tarjeta de crédito.</aplazo-text>
                           <aplazo-text>Visita nuestras <a target="_blank" href="https://www.aplazo.mx/preguntas-frecuentes"> Preguntas Frecuentes. </a> </aplazo-text>
                           <aplazo-button>CREAR MI CUENTA</aplazo-button>
                      </div>
     
    `;
    class ModalActions extends HTMLElement {
        constructor() {
            var _a;
            super();
            this.attachShadow({ mode: 'open' });
            (_a = this.shadowRoot) === null || _a === void 0 ? void 0 : _a.appendChild(template.content.cloneNode(true));
        }
    }
    exports.ModalActions = ModalActions;
    customElements.get('modal-actions') || customElements.define('modal-actions', ModalActions);
    
    
    /***/ }),
    
    /***/ 446:
    /***/ ((__unused_webpack_module, exports) => {
    
    
    Object.defineProperty(exports, "__esModule", ({ value: true }));
    exports.ResponsiveRow = void 0;
    const template = document.createElement('template');
    template.innerHTML = `
    <style>
            .flex-container {
      display: flex;
      flex-direction: row;
    }
    
    @media (max-width: 800px) {
      .flex-container {
        flex-direction: column;
      }
    }
    </style>
             <div class="flex-container" style="padding-top: 15px">
             <modal-actions></modal-actions>
                                <instructions-list></instructions-list>
             </div>
     
    `;
    class ResponsiveRow extends HTMLElement {
        constructor() {
            var _a;
            super();
            this.attachShadow({ mode: 'open' });
            (_a = this.shadowRoot) === null || _a === void 0 ? void 0 : _a.appendChild(template.content.cloneNode(true));
        }
    }
    exports.ResponsiveRow = ResponsiveRow;
    customElements.get('responsive-row') || customElements.define('responsive-row', ResponsiveRow);
    
    
    /***/ }),
    
    /***/ 735:
    /***/ ((__unused_webpack_module, exports) => {
    
    
    Object.defineProperty(exports, "__esModule", ({ value: true }));
    exports.StepsList = void 0;
    const template = document.createElement('template');
    template.innerHTML = `
    <style>
            .flex-container {
      display: flex;
      flex-direction: row;
    }
    
    @media (max-width: 800px) {
      .flex-container {
        flex-direction: column;
      }
    }
    </style>
                <div class="flex-container">
                      <step-card 
                        step-number="1"
                        step-img="https://cdn.aplazo.mx/Paso-1.png"
                        step-title="Llena tu carrito" 
                        step-description="Agrega los productos que quieres comprar a tu carrito de compra." >    
                      </step-card>
                      <step-card 
                        step-number="2"
                        step-img="https://cdn.aplazo.mx/Paso-1-1.png"
                        step-title="Elige Aplazo como forma de pago" 
                        step-description="Crea tu cuenta y agrega tu tarjeta de crédito o débito." >    
                      </step-card> 
                      <step-card 
                        step-number="3"
                        step-img="https://cdn.aplazo.mx/Paso-1-2.png"
                        step-title="Disfruta tu compra" 
                        step-description="Paga el 20% y llévatelo hoy. Paga en 5 plazos quincenales." >    
                      </step-card>  
                    </div>
     
    `;
    class StepsList extends HTMLElement {
        constructor() {
            var _a;
            super();
            this.attachShadow({ mode: 'open' });
            (_a = this.shadowRoot) === null || _a === void 0 ? void 0 : _a.appendChild(template.content.cloneNode(true));
        }
    }
    exports.StepsList = StepsList;
    customElements.get('steps-list') || customElements.define('steps-list', StepsList);
    
    
    /***/ }),
    
    /***/ 409:
    /***/ ((__unused_webpack_module, exports, __webpack_require__) => {
    
    
    Object.defineProperty(exports, "__esModule", ({ value: true }));
    exports.AplazoBannerElement = void 0;
    const aplazo_banner_template_1 = __webpack_require__(468);
    class AplazoBannerElement extends HTMLElement {
        constructor() {
            var _a;
            super();
            this.attachShadow({ mode: 'open' });
            (_a = this.shadowRoot) === null || _a === void 0 ? void 0 : _a.appendChild(aplazo_banner_template_1.template.content.cloneNode(true));
        }
        connectedCallback() {
            var _a, _b, _c, _d;
            this._textElement = (_a = this.shadowRoot) === null || _a === void 0 ? void 0 : _a.querySelector("#info-text");
            const logo = (_b = this.shadowRoot) === null || _b === void 0 ? void 0 : _b.querySelector("aplazo-logo");
            logo === null || logo === void 0 ? void 0 : logo.setAttribute("logo-size", '27');
            if (Number(this.offsetWidth) < 300 && this._textElement) {
                this._textElement.textContent = "Paga en cuotas!";
            }
            if (this.getAttribute("sticky") !== null) {
                this.style.position = 'fixed';
                this.style.justifyContent = 'center';
                this.style.top = '0px';
            }
            console.log(this.offsetWidth);
            (_d = (_c = this.shadowRoot) === null || _c === void 0 ? void 0 : _c.querySelector('.container')) === null || _d === void 0 ? void 0 : _d.addEventListener('click', () => {
            });
        }
    }
    exports.AplazoBannerElement = AplazoBannerElement;
    //register
    customElements.get('aplazo-banner') || customElements.define('aplazo-banner', AplazoBannerElement);
    
    
    /***/ }),
    
    /***/ 468:
    /***/ ((__unused_webpack_module, exports) => {
    
    
    Object.defineProperty(exports, "__esModule", ({ value: true }));
    exports.template = void 0;
    exports.template = document.createElement('template');
    exports.template.innerHTML = `
       <style>
       :host{
        width: 100%;
        display: inline;
    
        align-items: center;
        
       }
          .info-trigger{
          font-size: 12px;
          border-bottom: 1px solid;
          font-weight: bold;
       }
          
        </style>
           <aplazo-logo style="display: inline-table;">></aplazo-logo>
           <span style="padding-right: 5px; border-left: 1px solid black;height: 20px">
            </span>
            <span id="info-text">
            Paga en quincenas sin tarjeta de crédito
            </span>
             <aplazo-info-icon style="display: inline-table">
              <a class="info-trigger"    slot="info-trigger">Conoce más.</a>
    
          </aplazo-info-icon>  
    
    `;
    
    
    /***/ }),
    
    /***/ 951:
    /***/ (function(__unused_webpack_module, exports, __webpack_require__) {
    
    
    var __createBinding = (this && this.__createBinding) || (Object.create ? (function(o, m, k, k2) {
        if (k2 === undefined) k2 = k;
        Object.defineProperty(o, k2, { enumerable: true, get: function() { return m[k]; } });
    }) : (function(o, m, k, k2) {
        if (k2 === undefined) k2 = k;
        o[k2] = m[k];
    }));
    var __exportStar = (this && this.__exportStar) || function(m, exports) {
        for (var p in m) if (p !== "default" && !Object.prototype.hasOwnProperty.call(exports, p)) __createBinding(exports, m, p);
    };
    Object.defineProperty(exports, "__esModule", ({ value: true }));
    __exportStar(__webpack_require__(409), exports);
    
    
    /***/ }),
    
    /***/ 174:
    /***/ ((__unused_webpack_module, exports, __webpack_require__) => {
    
    
    Object.defineProperty(exports, "__esModule", ({ value: true }));
    exports.AplazoPlacementElement = void 0;
    const aplazo_placement_template_1 = __webpack_require__(460);
    const pricing_component_1 = __webpack_require__(982);
    class AplazoPlacementElement extends HTMLElement {
        constructor() {
            var _a;
            super();
            this.attachShadow({ mode: 'open' });
            (_a = this.shadowRoot) === null || _a === void 0 ? void 0 : _a.appendChild(aplazo_placement_template_1.template.content.cloneNode(true));
            this.pricingComponent = new pricing_component_1.PricingComponent(this);
        }
        connectedCallback() {
            var _a, _b, _c;
            this.updateQuotes();
            this.updateLogoSize();
            const quotes = (_a = this.shadowRoot) === null || _a === void 0 ? void 0 : _a.querySelector(".quotes-amount");
            if (!quotes) {
                return;
            }
            quotes.setAttribute("style", this.QuoteStyle);
            const info = (_b = this.shadowRoot) === null || _b === void 0 ? void 0 : _b.querySelector(".info-trigger");
            if (!info) {
                return;
            }
            info.setAttribute("style", this.InfoElementStyle);
            const logo = (_c = this.shadowRoot) === null || _c === void 0 ? void 0 : _c.querySelector("aplazo-logo");
            if (!logo) {
                return;
            }
            logo.setAttribute("style", this.LogoStyle);
            this.setAttribute("style", this.MainStyle);
        }
        static get observedAttributes() {
            return ['product-price', 'price-element-selector'];
        }
        attributeChangedCallback(name, oldValue, newValue) {
            switch (name) {
                case 'product-price':
                    this.updateQuotes();
                    break;
                case 'price-element-selector':
                    if (!this.ProductPrice) {
                        this.pricingComponent.trackElement(newValue);
                    }
                    break;
            }
        }
        get ProductPrice() {
            return Number(this.getAttribute('product-price')) / 100;
        }
        //styles
        get LogoSize() {
            return Number(this.getAttribute('aplazo-logo-size')) || 29;
        }
        get LogoStyle() {
            return this.getAttribute('logo-style') || '';
        }
        get QuoteStyle() {
            return this.getAttribute('quote-style') || '';
        }
        get InfoElementStyle() {
            return this.getAttribute('info-style') || '';
        }
        get MainStyle() {
            return this.getAttribute('main-style') || '';
        }
        updateLogoSize() {
            var _a;
            const logoElement = (_a = this.shadowRoot) === null || _a === void 0 ? void 0 : _a.querySelector("aplazo-logo");
            if (!logoElement) {
                return;
            }
            logoElement.setAttribute("logo-size", String(this.LogoSize));
        }
        updateQuotes() {
            var _a;
            const price = this.ProductPrice;
            if (!price) {
                return;
            }
            const priceSpot = (_a = this.shadowRoot) === null || _a === void 0 ? void 0 : _a.querySelector("#price-slot");
            if (!priceSpot) {
                return;
            }
            priceSpot.textContent = `desde $ ${parseFloat(`${price / 5}`).toFixed(2)}`;
        }
    }
    exports.AplazoPlacementElement = AplazoPlacementElement;
    //register
    customElements.get('aplazo-placement') || customElements.define('aplazo-placement', AplazoPlacementElement);
    
    
    /***/ }),
    
    /***/ 460:
    /***/ ((__unused_webpack_module, exports) => {
    
    
    Object.defineProperty(exports, "__esModule", ({ value: true }));
    exports.template = void 0;
    exports.template = document.createElement('template');
    exports.template.innerHTML = `
       <style>
       :host{
          display: block;
          font-family: inherit;
          color: inherit;
          padding: 15px 0px 15px 0px;
       }
       .info-trigger{
          font-size: 12px;
          border-bottom: 1px solid;
          font-weight: bold;
       }
          
       .quotes-amount{
         font-weight: bold;
       }
       </style>                  
     
            Paga en <span class="quotes-amount">5 plazos <span style="font-weight: lighter" id="price-slot"></span> </span>
               <span class="quotes-amount" style="color: black">sin intereses</span>
          <aplazo-logo style="display: inline-table"></aplazo-logo>
          <aplazo-info-icon style="display: inline-table">
            <a class="info-trigger"    slot="info-trigger">Conoce más.</a>
          </aplazo-info-icon>  
          
     
    `;
    
    
    /***/ }),
    
    /***/ 914:
    /***/ (function(__unused_webpack_module, exports, __webpack_require__) {
    
    
    var __createBinding = (this && this.__createBinding) || (Object.create ? (function(o, m, k, k2) {
        if (k2 === undefined) k2 = k;
        Object.defineProperty(o, k2, { enumerable: true, get: function() { return m[k]; } });
    }) : (function(o, m, k, k2) {
        if (k2 === undefined) k2 = k;
        o[k2] = m[k];
    }));
    var __exportStar = (this && this.__exportStar) || function(m, exports) {
        for (var p in m) if (p !== "default" && !Object.prototype.hasOwnProperty.call(exports, p)) __createBinding(exports, m, p);
    };
    Object.defineProperty(exports, "__esModule", ({ value: true }));
    __exportStar(__webpack_require__(174), exports);
    __exportStar(__webpack_require__(914), exports);
    
    
    /***/ }),
    
    /***/ 893:
    /***/ (function(__unused_webpack_module, exports, __webpack_require__) {
    
    
    var __createBinding = (this && this.__createBinding) || (Object.create ? (function(o, m, k, k2) {
        if (k2 === undefined) k2 = k;
        Object.defineProperty(o, k2, { enumerable: true, get: function() { return m[k]; } });
    }) : (function(o, m, k, k2) {
        if (k2 === undefined) k2 = k;
        o[k2] = m[k];
    }));
    var __exportStar = (this && this.__exportStar) || function(m, exports) {
        for (var p in m) if (p !== "default" && !Object.prototype.hasOwnProperty.call(exports, p)) __createBinding(exports, m, p);
    };
    Object.defineProperty(exports, "__esModule", ({ value: true }));
    __exportStar(__webpack_require__(983), exports);
    
    
    /***/ }),
    
    /***/ 983:
    /***/ (function(__unused_webpack_module, exports, __webpack_require__) {
    
    
    var __createBinding = (this && this.__createBinding) || (Object.create ? (function(o, m, k, k2) {
        if (k2 === undefined) k2 = k;
        Object.defineProperty(o, k2, { enumerable: true, get: function() { return m[k]; } });
    }) : (function(o, m, k, k2) {
        if (k2 === undefined) k2 = k;
        o[k2] = m[k];
    }));
    var __exportStar = (this && this.__exportStar) || function(m, exports) {
        for (var p in m) if (p !== "default" && !Object.prototype.hasOwnProperty.call(exports, p)) __createBinding(exports, m, p);
    };
    Object.defineProperty(exports, "__esModule", ({ value: true }));
    __exportStar(__webpack_require__(129), exports);
    
    
    /***/ }),
    
    /***/ 129:
    /***/ ((__unused_webpack_module, exports, __webpack_require__) => {
    
    
    Object.defineProperty(exports, "__esModule", ({ value: true }));
    exports.ModalElement = void 0;
    const modal_template_1 = __webpack_require__(777);
    class ModalElement extends HTMLElement {
        constructor() {
            var _a;
            super();
            this.attachShadow({ mode: "open" });
            (_a = this.shadowRoot) === null || _a === void 0 ? void 0 : _a.appendChild(modal_template_1.template.content.cloneNode(true));
        }
        connectedCallback() {
            var _a, _b, _c, _d, _e, _f, _g;
            (_b = (_a = this.shadowRoot) === null || _a === void 0 ? void 0 : _a.querySelector('.trigger-container')) === null || _b === void 0 ? void 0 : _b.addEventListener('click', (ev) => {
                ev.stopPropagation();
                ev.preventDefault();
                this.toggleModal(true);
            });
            const outside = (_c = this.shadowRoot) === null || _c === void 0 ? void 0 : _c.querySelector('#modal');
            outside === null || outside === void 0 ? void 0 : outside.addEventListener('click', (ev) => {
                if (ev.target === outside) {
                    ev.stopPropagation();
                    ev.preventDefault();
                    this.toggleModal(false);
                }
            });
            // Avoid close modal on context click
            (_e = (_d = this.shadowRoot) === null || _d === void 0 ? void 0 : _d.querySelector('.content-container')) === null || _e === void 0 ? void 0 : _e.addEventListener('click', (ev) => {
                ev.stopPropagation();
                ev.preventDefault();
            });
            // Avoid close modal on context click
            (_g = (_f = this.shadowRoot) === null || _f === void 0 ? void 0 : _f.querySelector('[slot=close]')) === null || _g === void 0 ? void 0 : _g.addEventListener('click', (ev) => {
                ev.stopPropagation();
                ev.preventDefault();
                this.toggleModal(false);
            });
        }
        toggleModal(show) {
            var _a;
            // First time call
            if (!this.modalRef) {
                this.modalRef = (_a = this.shadowRoot) === null || _a === void 0 ? void 0 : _a.querySelector('#modal');
            }
            this.modalRef.style.display = show ? 'block' : 'none';
            document.body.appendChild(this.modalRef);
        }
    }
    exports.ModalElement = ModalElement;
    customElements.get('aplazo-modal') || customElements.define('aplazo-modal', ModalElement);
    
    
    /***/ }),
    
    /***/ 777:
    /***/ ((__unused_webpack_module, exports) => {
    
    
    Object.defineProperty(exports, "__esModule", ({ value: true }));
    exports.template = void 0;
    exports.template = document.createElement('template');
    exports.template.innerHTML = `
        <style>
            
            .trigger-container{
                cursor: pointer;
            }
            .content-container{
                width: 100%;
                height: 100%;
                display: flex;
                align-items: center;
                justify-content: center;
                background-color: none;
            }
            
         
        </style>
        <div> 
           <div class="trigger-container">
            <slot name="trigger"></slot>
           </div>
           <div id="modal" style=" 
              display: none;
              position: fixed;
              z-index: 999999999999;
              padding-top: 40px;
              left: 0;
              top: 0;
              width: 100%; 
              height: 99%; 
              overflow: auto;
              background-color: rgb(0,0,0,0.05);
              padding-bottom: 20px;
              ">
           
              <div style=" 
               flex-direction: column;
              display: flex;
              width:  min-content;
              margin: auto;
              border-radius: 8px;
              padding: 50px ;
              background: white"> 
                         <div style="display: flex; justify-content: space-between;padding-bottom: 10px">
                            <aplazo-logo style="right: 10px;position: relative" ></aplazo-logo>
                                 <span class="close" style=" cursor: pointer;align-self: center;color: #131332;font-size: 17px;"
                                    slot="close"><aplazo-icon></aplazo-icon> &times</span>
                         </div>
                        <!--body-->
                        <div>
                             <aplazo-text  variant="big"> Compra ahora. Paga a plazos. Sin tarjeta de crédito.</aplazo-text>
                             <aplazo-text  variant="light-title">Ahora puedes tener lo que quieras, cuando quieras. Compra ahora y paga en 5 plazos quincenales.</aplazo-text>    
                            <steps-list></steps-list>
                            <responsive-row>  </responsive-row>
                            <footer-info></footer-info>
                        </div>
                    </div>
              </div>
           </div>
        <div> 
    `;
    
    
    /***/ })
    
    /******/    });
    /************************************************************************/
    /******/    // The module cache
    /******/    var __webpack_module_cache__ = {};
    /******/    
    /******/    // The require function
    /******/    function __webpack_require__(moduleId) {
    /******/        // Check if module is in cache
    /******/        var cachedModule = __webpack_module_cache__[moduleId];
    /******/        if (cachedModule !== undefined) {
    /******/            return cachedModule.exports;
    /******/        }
    /******/        // Create a new module (and put it into the cache)
    /******/        var module = __webpack_module_cache__[moduleId] = {
    /******/            // no module.id needed
    /******/            // no module.loaded needed
    /******/            exports: {}
    /******/        };
    /******/    
    /******/        // Execute the module function
    /******/        __webpack_modules__[moduleId].call(module.exports, module, module.exports, __webpack_require__);
    /******/    
    /******/        // Return the exports of the module
    /******/        return module.exports;
    /******/    }
    /******/    
    /************************************************************************/
    /******/    
    /******/    // startup
    /******/    // Load entry module and return exports
    /******/    // This entry module is referenced by other modules so it can't be inlined
    /******/    var __webpack_exports__ = __webpack_require__(22);
    /******/    
    /******/ })()
    ;